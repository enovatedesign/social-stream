<?php

namespace enovate\socialstream\controllers;

use Craft;
use craft\helpers\DateTimeHelper;
use craft\web\Controller;
use enovate\socialstream\auth\WebSubSignature;
use enovate\socialstream\jobs\RefreshStreamJob;
use enovate\socialstream\providers\YouTubeProvider;
use enovate\socialstream\providers\youtube\WebSubSubscriber;
use enovate\socialstream\records\ConnectionRecord;
use enovate\socialstream\SocialStream;
use SimpleXMLElement;
use yii\web\Response;

/**
 * Receives YouTube's WebSub (PubSubHubbub) push notifications.
 *
 * One action, two methods — the hub uses a single callback URL for both the
 * subscription handshake (GET, echo the challenge) and the notifications that follow
 * (POST, an Atom document). Splitting them across two routes would mean handing the
 * hub a URL it then posts notifications to anyway.
 */
class WebhookController extends Controller
{
    protected array|int|bool $allowAnonymous = ['youtube'];

    /**
     * The caller is not a browser and carries no session.
     *
     * Deliberately untyped: yii\web\Controller declares this property without a type,
     * and PHP treats a typed redeclaration as a fatal error at class load — which is a
     * 500 on every request this controller handles.
     */
    public $enableCsrfValidation = false;

    /**
     * YouTube's Atom notifications are a few hundred bytes. Anything wildly larger
     * is not one, and is refused before being handed to the XML parser.
     */
    private const MAX_BODY_BYTES = 262144;

    private const ATOM_YT_NAMESPACE = 'http://www.youtube.com/xml/schemas/2015';

    public function actionYoutube(): Response
    {
        return Craft::$app->request->getIsPost()
            ? $this->handleNotification()
            : $this->verifySubscription();
    }

    /**
     * The subscription handshake: echo `hub.challenge` back as plain text.
     *
     * The topic is checked against a connected channel first — the endpoint is
     * public, and confirming a lease nobody asked for would let anyone point the hub
     * at this site.
     */
    private function verifySubscription(): Response
    {
        $mode = $this->hubParam('mode');
        $topic = $this->hubParam('topic');
        $challenge = $this->hubParam('challenge');
        $leaseSeconds = (int) ($this->hubParam('lease_seconds') ?? WebSubSubscriber::DEFAULT_LEASE_SECONDS);

        $channelId = $this->channelIdFromTopic($topic);
        $connections = $channelId === null ? [] : $this->connectionsForChannel($channelId);

        if ($connections === []) {
            SocialStream::warning(
                'Rejected a WebSub verification for an unknown topic: ' . ($topic ?? '(none)')
            );

            return $this->plainText('Unknown topic.', 404);
        }

        if ($mode === 'denied') {
            // The hub refuses a subscription this way rather than in the POST reply.
            foreach ($connections as $connection) {
                $connection->websubExpiresAt = null;
                $connection->save();
            }

            SocialStream::warning(
                'The WebSub hub denied the subscription for channel ' . $channelId . ': '
                . ($this->hubParam('reason') ?? 'no reason given')
            );

            return $this->plainText('Denial acknowledged.');
        }

        if (!in_array($mode, ['subscribe', 'unsubscribe'], true) || $challenge === null) {
            return $this->plainText('Unsupported hub request.', 404);
        }

        // The lease the hub actually granted arrives here, and it can be shorter than
        // the one asked for — so this, not the request, is what the CP and the renewal
        // schedule should believe.
        $expiresAt = $mode === 'subscribe'
            ? DateTimeHelper::currentUTCDateTime()->modify("+{$leaseSeconds} seconds")->format('Y-m-d H:i:s')
            : null;

        foreach ($connections as $connection) {
            $connection->websubExpiresAt = $expiresAt;
            $connection->save();
        }

        SocialStream::info(
            'WebSub ' . $mode . ' verified for channel ' . $channelId
            . ($expiresAt === null ? '.' : '; lease expires ' . $expiresAt . '.')
        );

        return $this->plainText($challenge);
    }

    /**
     * A push notification: verify it, then queue a refresh.
     *
     * Nothing is read out of the payload beyond the channel it concerns. The video's
     * details come from the API on the refresh that follows, which keeps one code
     * path building posts instead of two that can disagree.
     */
    private function handleNotification(): Response
    {
        $body = Craft::$app->request->getRawBody();

        if ($body === '' || strlen($body) > self::MAX_BODY_BYTES) {
            return $this->plainText('Unexpected payload.', 400);
        }

        $parsed = $this->parseNotification($body);

        if ($parsed === null) {
            // 200, not an error: a payload shaped differently (a deletion, say) is not
            // the hub's fault, and a non-2xx makes it retry and eventually drop the
            // subscription. Scheduled refreshes catch whatever this misses.
            SocialStream::info('Ignored a WebSub notification with no channel ID.');

            return $this->plainText('Ignored.');
        }

        $connections = $this->connectionsForChannel($parsed['channelId']);

        if ($connections === []) {
            SocialStream::warning(
                'Received a WebSub notification for unconnected channel ' . $parsed['channelId'] . '.'
            );

            return $this->plainText('Unknown channel.', 404);
        }

        $signature = Craft::$app->request->headers->get('X-Hub-Signature');
        $verified = false;

        foreach ($connections as $connection) {
            if (!$this->signatureIsValid($connection, $signature, $body)) {
                SocialStream::warning(
                    'Rejected a WebSub notification for site ' . $connection->siteId
                    . ': the signature did not match.'
                );

                continue;
            }

            $verified = true;
            $this->refreshSite((int) $connection->siteId, $parsed['videoId']);

            $connection->webhookLastReceivedAt = DateTimeHelper::currentUTCDateTime()->format('Y-m-d H:i:s');
            $connection->save();
        }

        return $verified
            ? $this->plainText('Accepted.')
            : $this->plainText('Invalid signature.', 403);
    }

    /**
     * Verify the HMAC over the raw body, against the secret this connection
     * subscribed with.
     */
    private function signatureIsValid(ConnectionRecord $connection, ?string $signature, string $body): bool
    {
        return WebSubSignature::matches(
            SocialStream::$plugin->token->decrypt($connection->webhookSecret),
            $signature,
            $body,
        );
    }

    private function refreshSite(int $siteId, ?string $videoId): void
    {
        SocialStream::$plugin->streamCache->invalidateForSiteAndProvider($siteId, YouTubeProvider::handle());
        RefreshStreamJob::pushIfNotQueued($siteId, [], YouTubeProvider::handle());

        SocialStream::info(
            'WebSub notification accepted for site ' . $siteId
            . ($videoId === null ? '' : ' (video ' . $videoId . ')') . '; stream refresh queued.'
        );
    }

    /**
     * @return array{channelId: string, videoId: string|null}|null
     */
    private function parseNotification(string $body): ?array
    {
        $previous = libxml_use_internal_errors(true);

        try {
            // LIBXML_NONET blocks network fetches for anything the document
            // references; PHP 8 already refuses external entities by default.
            $feed = simplexml_load_string($body, SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if ($feed === false || !isset($feed->entry)) {
            return null;
        }

        $yt = $feed->entry->children(self::ATOM_YT_NAMESPACE);
        $channelId = isset($yt->channelId) ? trim((string) $yt->channelId) : '';
        $videoId = isset($yt->videoId) ? trim((string) $yt->videoId) : '';

        if ($channelId === '') {
            return null;
        }

        return [
            'channelId' => $channelId,
            'videoId' => $videoId === '' ? null : $videoId,
        ];
    }

    /**
     * Every connection watching this channel. More than one site can point at the
     * same channel, and they all need refreshing.
     *
     * @return ConnectionRecord[]
     */
    private function connectionsForChannel(string $channelId): array
    {
        return ConnectionRecord::findAll([
            'provider' => YouTubeProvider::handle(),
            'providerUserId' => $channelId,
        ]);
    }

    private function channelIdFromTopic(?string $topic): ?string
    {
        if ($topic === null || $topic === '') {
            return null;
        }

        $query = parse_url($topic, PHP_URL_QUERY);

        if (!is_string($query)) {
            return null;
        }

        parse_str($query, $params);
        $channelId = $params['channel_id'] ?? null;

        return is_string($channelId) && $channelId !== '' ? $channelId : null;
    }

    /**
     * Read a `hub.*` parameter.
     *
     * PHP rewrites the dot in a query-string name to an underscore when it builds
     * $_GET, so `hub.mode` arrives as `hub_mode`. Both spellings are accepted rather
     * than betting on that behaviour never changing — and neither can go through
     * Yii's getQueryParam(), which reads a dot as a nested-array path.
     */
    private function hubParam(string $name): ?string
    {
        $params = Craft::$app->request->getQueryParams();
        $value = $params['hub_' . $name] ?? $params['hub.' . $name] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function plainText(string $body, int $statusCode = 200): Response
    {
        $response = Craft::$app->response;
        $response->format = Response::FORMAT_RAW;
        $response->statusCode = $statusCode;
        $response->headers->set('Content-Type', 'text/plain; charset=UTF-8');
        $response->content = $body;

        return $response;
    }
}
