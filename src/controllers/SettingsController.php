<?php

namespace enovate\socialstream\controllers;

use Craft;
use craft\helpers\App;
use craft\helpers\DateTimeHelper;
use craft\helpers\UrlHelper;
use craft\models\Site;
use craft\web\Controller;
use enovate\socialstream\cp\ProviderPanel;
use enovate\socialstream\jobs\RefreshStreamJob;
use enovate\socialstream\jobs\RenewWebSubJob;
use enovate\socialstream\models\Post;
use enovate\socialstream\providers\InstagramProvider;
use enovate\socialstream\providers\YouTubeProvider;
use enovate\socialstream\records\ConnectionRecord;
use enovate\socialstream\records\SettingsRecord;
use enovate\socialstream\SocialStream;
use yii\web\BadRequestHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * CP settings: the provider overview, each provider's own page, and the shared
 * configuration.
 *
 * Providers get a page each rather than a tab each, so the settings screen doesn't
 * grow a tab per provider as more are registered — including ones this plugin knows
 * nothing about.
 */
class SettingsController extends Controller
{
    /**
     * Whether this request failed to resolve the channel it was given, so the save
     * can decline to also report success. Set by {@see applyChannel()}.
     */
    private bool $channelError = false;

    /**
     * The Providers table, plus the settings that apply across all of them.
     */
    public function actionIndex(?string $siteHandle = null): Response
    {
        $site = $this->site($siteHandle);
        $siteId = $site->id;

        $settingsRecord = SettingsRecord::findOne(['siteId' => $siteId]);

        if ($settingsRecord === null) {
            $settingsRecord = new SettingsRecord();
            $settingsRecord->siteId = $siteId;
        }

        $panel = new ProviderPanel();
        $rows = $panel->rows($siteId, $site->handle);

        return $this->renderTemplate('social-stream/settings/index', [
            'site' => $site,
            'allSites' => Craft::$app->sites->getAllSites(),
            'settingsRecord' => $settingsRecord,
            'providerRows' => $rows,
            // Only a provider that can actually serve posts belongs in the preview
            // picker; the rest would just produce an error someone has to interpret.
            'previewProviders' => array_values(array_filter(
                $rows,
                fn(array $row) => $row['connected'] && $row['status'] !== 'off',
            )),
        ]);
    }

    /**
     * One provider's own settings page.
     *
     * @throws NotFoundHttpException if the handle isn't registered.
     */
    public function actionProvider(?string $siteHandle = null, ?string $provider = null): Response
    {
        $site = $this->site($siteHandle);
        $instance = SocialStream::$plugin->providers->getProviderByHandle((string) $provider);

        if ($instance === null) {
            throw new NotFoundHttpException('No Social Stream provider registered with that handle.');
        }

        $handle = $instance->getHandle();
        $panel = new ProviderPanel();

        return $this->renderTemplate('social-stream/settings/provider', [
            'site' => $site,
            'allSites' => Craft::$app->sites->getAllSites(),
            'providerHandle' => $handle,
            'providerName' => $instance->getDisplayName(),
            'row' => $panel->row($handle, $instance->getDisplayName(), $site->id, $site->handle),
            'redirectUri' => SocialStream::$plugin->token->getRedirectUri(),
            'credentials' => $panel->credentials($site->id, $handle),
            'instagram' => $handle === InstagramProvider::handle() ? $panel->instagram($site->id) : null,
            'youtube' => $handle === YouTubeProvider::handle() ? $panel->youtube($site->id) : null,
        ]);
    }

    /**
     * AJAX: Test a provider's connection by fetching its profile.
     */
    public function actionTestConnection(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $siteId = (int) Craft::$app->request->getRequiredBodyParam('siteId');
        $handle = $this->requestedProvider();

        // No pre-flight check on the stored credential: each provider knows which one
        // it needs and says so precisely — a missing API key, an unresolvable channel
        // or an unauthorised token are three different problems, and a generic
        // "authorise first" would be wrong for two of them.
        $result = SocialStream::$plugin->providers->requireProviderByHandle($handle)->fetchProfile($siteId);

        if (!$result['success']) {
            return $this->asJson([
                'success' => false,
                'error' => $result['error'] ?? Craft::t('social-stream', 'Unknown error.'),
            ]);
        }

        $data = $result['data'] ?? [];

        // The call has already been made and paid for, so cache it: it saves the next
        // getProfile() an API call, and it is what puts the account's name in front of
        // whoever just pressed the button.
        SocialStream::$plugin->streamCache->setProfile($siteId, $result, $handle);

        return $this->asJson([
            'success' => true,
            'data' => $data,
            // Normalised here so the CP's JavaScript doesn't need a branch per
            // provider to render the account it just proved it can reach.
            'label' => (new ProviderPanel())->accountLabel($data),
            'detail' => $this->connectionDetail($data),
        ]);
    }

    /**
     * AJAX: Push a RefreshStreamJob to the queue.
     */
    public function actionRefreshStream(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $siteId = (int) Craft::$app->request->getRequiredBodyParam('siteId');
        $handle = $this->requestedProvider();

        $settingsRecord = SettingsRecord::findOne(['siteId' => $siteId]);
        $limit = $settingsRecord->defaultLimit ?? 25;

        $queued = RefreshStreamJob::pushIfNotQueued($siteId, ['limit' => $limit], $handle);

        return $this->asJson([
            'success' => true,
            'message' => $queued
                ? Craft::t('social-stream', 'Stream refresh job has been queued.')
                : Craft::t('social-stream', 'A stream refresh is already queued for this site.'),
        ]);
    }

    /**
     * AJAX: Generate a new API bearer token.
     */
    public function actionGenerateApiToken(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $siteId = (int) Craft::$app->request->getRequiredBodyParam('siteId');
        $rawToken = Craft::$app->security->generateRandomString(48);

        $settingsRecord = SettingsRecord::findOne(['siteId' => $siteId]);

        if ($settingsRecord === null) {
            $settingsRecord = new SettingsRecord();
            $settingsRecord->siteId = $siteId;
        }

        $settingsRecord->apiToken = SocialStream::$plugin->token->encrypt($rawToken);

        if (!$settingsRecord->save()) {
            return $this->asJson([
                'success' => false,
                'error' => Craft::t('social-stream', 'Couldn\'t save API token.'),
            ]);
        }

        return $this->asJson([
            'success' => true,
            'token' => $rawToken,
        ]);
    }

    /**
     * AJAX: Subscribe (or re-subscribe) to a provider's push notifications.
     *
     * Subscribing happens automatically when a channel is connected, and the lease is
     * renewed without anyone asking — but a hub that was unreachable at that moment
     * would otherwise leave the connection with no way back except a console command.
     */
    public function actionSubscribeWebSub(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $siteId = (int) Craft::$app->request->getRequiredBodyParam('siteId');
        $provider = SocialStream::$plugin->providers->getProviderByHandle($this->requestedProvider());

        if (!$provider instanceof YouTubeProvider) {
            return $this->asJson([
                'success' => false,
                'error' => Craft::t('social-stream', 'This provider does not support push notifications.'),
            ]);
        }

        $result = $provider->subscribeWebSub($siteId);

        if (!$result['success']) {
            return $this->asJson([
                'success' => false,
                'error' => $result['error'] ?? Craft::t('social-stream', 'Unknown error.'),
            ]);
        }

        // The hub answers immediately but verifies out of band, so the lease is not
        // confirmed yet — saying so is more honest than reporting success outright.
        return $this->asJson([
            'success' => true,
            'message' => Craft::t(
                'social-stream',
                'The hub accepted the subscription. It confirms out of band, so reload in a moment to see the lease.'
            ),
        ]);
    }

    /**
     * AJAX: Forget a provider's credentials.
     *
     * The app credentials are kept: they identify the app, not the account, and
     * clearing them would mean re-entering a client ID to reconnect the same account.
     * An API key is kept for the same reason — it belongs to the Google Cloud
     * project, not the channel, and the same key serves whichever channel comes
     * next.
     */
    public function actionDisconnect(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $siteId = (int) Craft::$app->request->getRequiredBodyParam('siteId');
        $handle = $this->requestedProvider();
        $provider = SocialStream::$plugin->providers->getProviderByHandle($handle);

        // Tell the hub first — once the credentials are gone the channel ID it is keyed
        // on goes with them, and it would keep pushing to a site that can no longer
        // serve the feed.
        if ($provider instanceof YouTubeProvider) {
            $provider->unsubscribeWebSub($siteId);
            $provider->forgetChannel($siteId);
        }

        $connection = SocialStream::$plugin->token->getConnection($siteId, $handle);
        $connection->accessToken = null;
        $connection->refreshToken = null;
        $connection->tokenExpiresAt = null;
        $connection->providerUserId = null;
        $connection->channelRef = null;
        $connection->websubExpiresAt = null;
        $connection->webhookSecret = null;
        $connection->webhookLastReceivedAt = null;
        $connection->needsReauthAt = null;
        $connection->lastError = null;
        $connection->lastErrorAt = null;

        if (!$connection->save()) {
            return $this->asJson([
                'success' => false,
                'error' => Craft::t('social-stream', 'Couldn\'t disconnect the account.'),
            ]);
        }

        SocialStream::$plugin->streamCache->invalidateForSiteAndProvider($siteId, $handle);

        return $this->asJson([
            'success' => true,
            'message' => Craft::t('social-stream', 'Account disconnected.'),
        ]);
    }

    /**
     * AJAX: Return the current stream data for preview in the CP.
     */
    public function actionPreviewStream(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $siteId = (int) Craft::$app->request->getRequiredBodyParam('siteId');
        $handle = $this->requestedProvider();
        $settingsRecord = SettingsRecord::findOne(['siteId' => $siteId]);

        $options = [
            'siteId' => $siteId,
            'limit' => $settingsRecord->defaultLimit ?? 25,
            'provider' => $handle,
        ];

        $cached = false;
        $cacheResult = SocialStream::$plugin->streamCache->getStream($options);

        if ($cacheResult['data'] !== null) {
            $streamResponse = SocialStream::$plugin->streamCache->deserializeStreamResponse($cacheResult['data']);
            $cached = true;
        } else {
            $streamResponse = SocialStream::$plugin->providers
                ->requireProviderByHandle($handle)
                ->fetchStream($options);
        }

        if (!($streamResponse['success'] ?? false)) {
            return $this->asJson([
                'success' => false,
                'error' => $streamResponse['error'] ?? Craft::t('social-stream', 'Failed to load stream.'),
            ]);
        }

        return $this->asJson([
            'success' => true,
            'data' => array_map(
                fn($post) => $post instanceof Post ? $post->toArray() : $post,
                $streamResponse['data'] ?? [],
            ),
            'cached' => $cached,
        ]);
    }

    /**
     * Save the settings that apply across every provider.
     */
    public function actionSave(): ?Response
    {
        $this->requirePostRequest();

        $request = Craft::$app->request;
        $siteId = (int) $request->getRequiredBodyParam('siteId');

        $settingsRecord = SettingsRecord::findOne(['siteId' => $siteId]);

        if ($settingsRecord === null) {
            $settingsRecord = new SettingsRecord();
            $settingsRecord->siteId = $siteId;
        }

        $settingsRecord->defaultLimit = (int) ($request->getBodyParam('defaultLimit') ?? 25);
        $settingsRecord->excludeNonFeed = (bool) $request->getBodyParam('excludeNonFeed');
        $settingsRecord->cacheDuration = (int) ($request->getBodyParam('cacheDuration') ?? 60);
        $settingsRecord->secureApiEndpoint = (bool) $request->getBodyParam('secureApiEndpoint');

        if (!$settingsRecord->save()) {
            Craft::$app->session->setError(Craft::t('social-stream', 'Couldn\'t save settings.'));

            return null;
        }

        Craft::$app->session->setNotice(Craft::t('social-stream', 'Settings saved.'));

        return $this->redirect($this->settingsUrl($siteId));
    }

    /**
     * Save one provider's credentials.
     *
     * An OAuth provider stores an app ID and secret in the generic `appId` /
     * `appSecret` fields; one without OAuth stores an API key and the channel it
     * should read, and connects there and then — there is no authorisation round
     * trip to come back from, so saving the form is the whole connection.
     */
    public function actionSaveProvider(): ?Response
    {
        $this->requirePostRequest();

        $request = Craft::$app->request;
        $siteId = (int) $request->getRequiredBodyParam('siteId');
        $handle = $this->requestedProvider();
        $provider = SocialStream::$plugin->providers->getProviderByHandle($handle);

        $tokenService = SocialStream::$plugin->token;
        $connection = $tokenService->getConnection($siteId, $handle);
        $previousChannel = $connection->providerUserId;
        $this->channelError = false;

        if ($provider !== null && !$provider::usesOAuth()) {
            // The record drops writes to columns that don't exist yet, so saving into
            // an un-migrated table would discard the key and then report a missing
            // one. Refuse, and name the actual problem.
            if (!$connection->hasAttribute('apiKey') || !$connection->hasAttribute('channelRef')) {
                Craft::$app->session->setError(
                    Craft::t('social-stream', 'The database is out of date — run `php craft up`, then configure {provider}.', [
                        'provider' => $provider->getDisplayName(),
                    ])
                );

                return null;
            }

            // An empty key field means "leave the stored one alone" — it renders blank
            // for a saved key, so treating blank as a clear would wipe it on
            // every save of the channel field.
            $apiKey = $request->getBodyParam('apiKey');

            if ($apiKey !== null && $apiKey !== '') {
                $connection->apiKey = $tokenService->encrypt($apiKey);
            }

            $this->applyChannel($connection, (string) ($request->getBodyParam('channelRef') ?? ''));
        } else {
            $connection->appId = $tokenService->encrypt($request->getBodyParam('appId'));

            // An empty secret field means "leave the stored one alone" — it renders blank
            // for a saved secret, so treating blank as a clear would wipe it on every save.
            $appSecret = $request->getBodyParam('appSecret');

            if ($appSecret !== null && $appSecret !== '') {
                $connection->appSecret = $tokenService->encrypt($appSecret);
            }
        }

        if (!$connection->save()) {
            Craft::$app->session->setError(Craft::t('social-stream', 'Couldn\'t save settings.'));

            return null;
        }

        // Only when the channel actually changed: re-subscribing and dropping the
        // cache on every unrelated save would cost a hub round trip and a cold fetch
        // for nothing.
        if (
            $provider instanceof YouTubeProvider
            && $connection->providerUserId !== null
            && $connection->providerUserId !== $previousChannel
        ) {
            $this->connectYouTube($provider, $siteId);
        }

        // A channel that failed to resolve has already flashed its own error, and
        // "Settings saved" on top of it reads as though it worked. Tracked on the
        // request rather than read back from the session, which would also pick up an
        // unrelated error left over from the request before this one.
        if (!$this->channelError) {
            Craft::$app->session->setNotice(Craft::t('social-stream', 'Settings saved.'));
        }

        $site = Craft::$app->sites->getSiteById($siteId);

        return $this->redirect(UrlHelper::cpUrl(
            'social-stream/settings/' . $site->handle . '/provider/' . $handle
        ));
    }

    /**
     * Store the channel an API-key provider should read, resolving it to a channel ID.
     *
     * Resolution happens here, once, rather than on each fetch: the admin finds out
     * immediately whether what they pasted is a channel, and the stored ID is
     * immune to the handle later being renamed or reassigned.
     */
    private function applyChannel(ConnectionRecord $connection, string $input): void
    {
        $input = trim($input);
        $tokenService = SocialStream::$plugin->token;

        if ($input === '') {
            $connection->channelRef = null;
            $connection->providerUserId = null;
            $this->forgetCachedChannel($connection);

            return;
        }

        // Nothing to do for a save that didn't touch the field — re-resolving would
        // spend a quota unit on every unrelated settings change.
        if ($input === $connection->channelRef && $connection->providerUserId !== null) {
            return;
        }

        $connection->channelRef = $input;
        $connection->providerUserId = null;
        $this->forgetCachedChannel($connection);

        $provider = SocialStream::$plugin->providers->getProviderByHandle($connection->provider);

        if (!$provider instanceof YouTubeProvider) {
            return;
        }

        // The key may have been entered on this same submission, so it is read from
        // the unsaved record rather than the database.
        $stored = $tokenService->decrypt($connection->apiKey);
        $apiKey = $stored ? App::parseEnv($stored) : null;

        if (!$apiKey) {
            $message = Craft::t('social-stream', 'Enter an API key as well — the channel can\'t be looked up without one.');
            Craft::$app->session->setError($message);
            $this->channelError = true;
            $connection->lastError = $message;
            $connection->lastErrorAt = DateTimeHelper::currentUTCDateTime()->format('Y-m-d H:i:s');

            return;
        }

        $resolved = $provider->resolveChannelReference($input, $apiKey, (int) $connection->siteId);

        if ($resolved['id'] === null) {
            Craft::$app->session->setError($resolved['error']);
            $this->channelError = true;

            // The provider records its own errors against a separately loaded copy of
            // this row, and the save that follows would overwrite it — so the failure
            // is written here too, where it survives to reach the panel.
            $connection->lastError = $resolved['error'];
            $connection->lastErrorAt = DateTimeHelper::currentUTCDateTime()->format('Y-m-d H:i:s');

            return;
        }

        $connection->providerUserId = $resolved['id'];
        $connection->lastError = null;
        $connection->lastErrorAt = null;
    }

    private function forgetCachedChannel(ConnectionRecord $connection): void
    {
        $provider = SocialStream::$plugin->providers->getProviderByHandle($connection->provider);

        if ($provider instanceof YouTubeProvider) {
            $provider->forgetChannel((int) $connection->siteId);
        }
    }

    /**
     * Finish a YouTube connection once a channel is stored: clear anything cached
     * against the previous channel, name it, and start push notifications.
     *
     * Push notifications are an optimisation on top of the refresh cron, so a hub
     * that refuses the subscription must not fail the save — it is reported in the
     * CP's Push Notifications row, which has a button to try again.
     */
    private function connectYouTube(YouTubeProvider $provider, int $siteId): void
    {
        SocialStream::$plugin->streamCache->invalidateForSiteAndProvider($siteId, $provider->getHandle());

        // Name the channel now, while an admin is watching. The CP never fetches a
        // profile itself — opening a settings page must not spend quota — so without
        // this the panel and the Providers table would identify a perfectly healthy
        // connection by its raw `UC…` ID until something else happened to fetch one.
        //
        // fetchProfile() remembers the account's name but does not cache the response,
        // so the panel's avatar, handle and subscriber count need the second step.
        $profile = $provider->fetchProfile($siteId);

        if ($profile['success'] ?? false) {
            SocialStream::$plugin->streamCache->setProfile($siteId, $profile, $provider->getHandle());
        }

        $subscription = $provider->subscribeWebSub($siteId);

        if ($subscription['success']) {
            RenewWebSubJob::pushRenewal($siteId);

            return;
        }

        SocialStream::warning(
            'YouTube channel saved for site ' . $siteId . ' but the WebSub subscription failed: '
            . ($subscription['error'] ?? 'unknown error')
        );
    }

    /**
     * A second line for the Test Connection result, where the provider gives one worth
     * showing — Instagram's account type, or a channel's subscriber count.
     */
    private function connectionDetail(array $profile): ?string
    {
        if (isset($profile['account_type'])) {
            return (string) $profile['account_type'];
        }

        if (!empty($profile['hiddenSubscriberCount'])) {
            return null;
        }

        if (isset($profile['subscriberCount'])) {
            return Craft::t('social-stream', '{count} subscribers', [
                'count' => number_format((int) $profile['subscriberCount']),
            ]);
        }

        return null;
    }

    /**
     * The provider a CP action is about, defaulting to Instagram so any bookmarked
     * request predating multi-provider support still resolves.
     *
     * @throws BadRequestHttpException if the handle isn't registered.
     */
    private function requestedProvider(): string
    {
        $handle = Craft::$app->request->getBodyParam('provider') ?? InstagramProvider::handle();

        if (!is_string($handle) || SocialStream::$plugin->providers->getProviderByHandle($handle) === null) {
            throw new BadRequestHttpException('Unknown Social Stream provider.');
        }

        return $handle;
    }

    /**
     * @throws NotFoundHttpException if the handle doesn't match a site.
     */
    private function site(?string $siteHandle): Site
    {
        if ($siteHandle === null) {
            return Craft::$app->sites->currentSite;
        }

        $site = Craft::$app->sites->getSiteByHandle($siteHandle);

        if ($site === null) {
            throw new NotFoundHttpException('Site not found: ' . $siteHandle);
        }

        return $site;
    }

    private function settingsUrl(int $siteId): string
    {
        $site = Craft::$app->sites->getSiteById($siteId);

        return $site
            ? UrlHelper::cpUrl('social-stream/settings/' . $site->handle)
            : UrlHelper::cpUrl('social-stream/settings');
    }
}
