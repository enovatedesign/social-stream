<?php

namespace enovate\socialstream\providers\youtube;

use Craft;
use craft\helpers\App;
use enovate\socialstream\providers\YouTubeProvider;
use enovate\socialstream\records\ConnectionRecord;
use enovate\socialstream\SocialStream;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Talks to YouTube's WebSub (PubSubHubbub) hub.
 *
 * Subscribing to a channel's Atom feed is what turns a new upload into a refresh
 * within seconds rather than whenever the cache happens to expire. Leases cap at 10
 * days, so a subscription is something that has to be kept alive rather than made
 * once — see {@see \enovate\socialstream\jobs\RenewWebSubJob} and the
 * `social-stream/web-sub/renew` command.
 *
 * Reports outcomes rather than recording them: the provider owns the connection's
 * error state, and this class owns the hub protocol.
 */
class WebSubSubscriber
{
    public const HUB_URL = 'https://pubsubhubbub.appspot.com/subscribe';

    public const TOPIC_URL = 'https://www.youtube.com/feeds/videos.xml';

    /**
     * The longest lease the hub will grant — 10 days. What it actually grants arrives
     * with the verification request, which can be shorter.
     */
    public const DEFAULT_LEASE_SECONDS = 864000;

    /**
     * How long a subscribe request is reported as awaiting the hub's verification.
     *
     * Generous for a callback that normally lands within seconds: the flag only
     * changes what the CP says, and saying "waiting" for a minute too long is a far
     * better failure than saying "not subscribed" while it is being set up.
     */
    private const PENDING_TTL = 120;

    /**
     * How long an unsubscribe stays confirmable after the hub was asked for it.
     *
     * The hub verifies out of band, and a disconnect clears the channel ID that
     * verification is matched on — so the request itself is what the callback is
     * checked against. Ten minutes is far longer than the seconds a callback takes,
     * and the window only ever permits cancelling a subscription.
     */
    private const UNSUBSCRIBING_TTL = 600;

    /**
     * Whether a subscribe request is still waiting on the hub's verification call.
     */
    public static function isPending(int $siteId): bool
    {
        return Craft::$app->cache->get(self::pendingCacheKey($siteId)) !== false;
    }

    /**
     * Forget that a subscription was awaiting verification — the answer has arrived.
     */
    public static function clearPending(int $siteId): void
    {
        Craft::$app->cache->delete(self::pendingCacheKey($siteId));
    }

    private static function pendingCacheKey(int $siteId): string
    {
        return 'social-stream:websub-pending:' . $siteId;
    }

    /**
     * Remember that this install asked the hub to cancel a channel's subscription.
     *
     * Recorded against the channel rather than the site because that is all the hub's
     * verification call carries, and it is deliberately set before the request: the
     * hub may verify synchronously, and a callback arriving first would otherwise
     * find no record of what it is confirming.
     */
    public static function markUnsubscribing(string $channelId): void
    {
        Craft::$app->cache->set(self::unsubscribingCacheKey($channelId), true, self::UNSUBSCRIBING_TTL);
    }

    /**
     * Whether this install asked the hub to cancel this channel's subscription.
     */
    public static function isUnsubscribing(string $channelId): bool
    {
        return Craft::$app->cache->get(self::unsubscribingCacheKey($channelId)) !== false;
    }

    /**
     * Forget the request — the hub has confirmed it, or it never reached the hub.
     */
    public static function clearUnsubscribing(string $channelId): void
    {
        Craft::$app->cache->delete(self::unsubscribingCacheKey($channelId));
    }

    private static function unsubscribingCacheKey(string $channelId): string
    {
        return 'social-stream:websub-unsubscribing:' . $channelId;
    }

    /**
     * @return array{success: bool, error: string|null}
     */
    public function subscribe(int $siteId, string $channelId): array
    {
        return $this->request($siteId, $channelId, 'subscribe');
    }

    /**
     * @return array{success: bool, error: string|null}
     */
    public function unsubscribe(int $siteId, string $channelId): array
    {
        return $this->request($siteId, $channelId, 'unsubscribe');
    }

    /**
     * Whether any site other than this one is connected to the same channel.
     *
     * Matters on unsubscribe: the hub identifies a subscription by callback and topic,
     * and the callback is one URL for the whole install — so sites sharing a channel
     * share the subscription, and cancelling it for one cancels it for all.
     */
    public function othersWatch(string $channelId, int $siteId): bool
    {
        return ConnectionRecord::find()
            ->where([
                'provider' => YouTubeProvider::handle(),
                'providerUserId' => $channelId,
            ])
            ->andWhere(['not', ['siteId' => $siteId]])
            ->exists();
    }

    public function topicUrl(string $channelId): string
    {
        return self::TOPIC_URL . '?channel_id=' . $channelId;
    }

    /**
     * The hub uses one URL for both the verification GET and the notification POST,
     * so there is exactly one callback.
     */
    public function callbackUrl(): string
    {
        return rtrim(App::parseEnv(Craft::$app->sites->primarySite->baseUrl), '/')
            . '/actions/social-stream/webhook/youtube';
    }

    /**
     * @return array{success: bool, error: string|null}
     */
    private function request(int $siteId, string $channelId, string $mode): array
    {
        $secret = $this->establishSecret($channelId);

        if ($secret === null) {
            return ['success' => false, 'error' => 'Could not store the WebSub secret for this channel.'];
        }

        if ($mode === 'unsubscribe') {
            // Before the request, not after: the disconnect that triggers this clears
            // the channel ID the hub's verification call is matched on, so without a
            // record of having asked, that call is rejected as an unknown topic — and
            // the hub takes a rejected verification to mean the subscription stands.
            self::markUnsubscribing($channelId);
        }

        try {
            $client = Craft::createGuzzleClient();
            $response = $client->post(self::HUB_URL, [
                'form_params' => [
                    'hub.callback' => $this->callbackUrl(),
                    'hub.topic' => $this->topicUrl($channelId),
                    'hub.mode' => $mode,
                    'hub.lease_seconds' => self::DEFAULT_LEASE_SECONDS,
                    'hub.secret' => $secret,
                    'hub.verify' => 'async',
                ],
            ]);

            $status = $response->getStatusCode();

            // The hub answers 202 and then calls back to verify; 204 means it verified
            // synchronously. Anything else is a refusal.
            if ($status !== 202 && $status !== 204) {
                if ($mode === 'unsubscribe') {
                    self::clearUnsubscribing($channelId);
                }

                return [
                    'success' => false,
                    'error' => 'The WebSub hub returned HTTP ' . $status . ' for ' . $mode . '.',
                ];
            }

            if ($mode === 'unsubscribe') {
                $connection = SocialStream::$plugin->token->getConnection($siteId, YouTubeProvider::handle());
                $connection->websubExpiresAt = null;
                $connection->save();
                Craft::$app->cache->delete(self::pendingCacheKey($siteId));
            } else {
                // The lease is not stored until the hub calls back, which is usually a
                // second or two away — long enough for the page that triggered this to
                // render first. Without this flag it would say "not subscribed", which
                // is the one thing that has not happened.
                Craft::$app->cache->set(self::pendingCacheKey($siteId), true, self::PENDING_TTL);
            }

            SocialStream::info(
                'WebSub ' . $mode . ' accepted by the hub for channel ' . $channelId . ' (site ' . $siteId . ').'
            );

            return ['success' => true, 'error' => null];
        } catch (GuzzleException $e) {
            if ($mode === 'unsubscribe') {
                // Nothing reached the hub, so nothing will call back to confirm it.
                self::clearUnsubscribing($channelId);
            }

            return ['success' => false, 'error' => 'WebSub ' . $mode . ' failed: ' . $e->getMessage()];
        }
    }

    /**
     * The secret every connection watching this channel signs with, stored on all of
     * them before the hub is told about it.
     *
     * Sites sharing a channel share one subscription, and therefore one secret.
     * Per-connection secrets would mean whichever site subscribed last set the secret
     * and the others could no longer verify a notification — silently, from the moment
     * the second one connected.
     *
     * Saving before subscribing matters too: verification is asynchronous, so the
     * first notification can arrive before the subscribe call has returned, and one
     * signed with a secret not yet stored is indistinguishable from a forgery.
     *
     * @return string|null The shared secret, or null if it could not be stored.
     */
    private function establishSecret(string $channelId): ?string
    {
        $tokenService = SocialStream::$plugin->token;

        /** @var ConnectionRecord[] $connections */
        $connections = ConnectionRecord::find()
            ->where([
                'provider' => YouTubeProvider::handle(),
                'providerUserId' => $channelId,
            ])
            ->orderBy(['id' => SORT_ASC])
            ->all();

        // Lowest row wins, so every site subscribing arrives at the same answer
        // whatever order they do it in.
        $secret = null;

        foreach ($connections as $candidate) {
            $secret = $tokenService->decrypt($candidate->webhookSecret);

            if ($secret !== null) {
                break;
            }
        }

        $secret ??= Craft::$app->security->generateRandomString(32);
        $stored = true;

        foreach ($connections as $candidate) {
            if ($tokenService->decrypt($candidate->webhookSecret) === $secret) {
                continue;
            }

            $candidate->webhookSecret = $tokenService->encrypt($secret);

            if (!$candidate->save()) {
                SocialStream::error(
                    'Could not store the WebSub secret for site ' . $candidate->siteId . ': '
                    . json_encode($candidate->getErrors())
                );
                $stored = false;
            }
        }

        return $stored ? $secret : null;
    }
}
