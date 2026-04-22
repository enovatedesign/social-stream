<?php

namespace enovate\socialstream\controllers;

use Craft;
use craft\web\Controller;
use enovate\socialstream\jobs\RefreshStreamJob;
use enovate\socialstream\models\Post;
use enovate\socialstream\providers\InstagramProvider;
use enovate\socialstream\records\SettingsRecord;
use enovate\socialstream\SocialStream;
use yii\web\Response;

/**
 * Handles CP settings display and save actions.
 */
class SettingsController extends Controller
{
    public function actionIndex(?string $siteHandle = null): Response
    {
        $site = $siteHandle
            ? Craft::$app->sites->getSiteByHandle($siteHandle)
            : Craft::$app->sites->currentSite;

        $siteId = $site->id;
        $tokenService = SocialStream::$plugin->token;

        $connection = $tokenService->getConnection($siteId, 'instagram');

        $settingsRecord = SettingsRecord::findOne(['siteId' => $siteId]);
        if ($settingsRecord === null) {
            $settingsRecord = new SettingsRecord();
            $settingsRecord->siteId = $siteId;
        }

        $decryptedAppId = $tokenService->decrypt($connection->appId);
        $decryptedAppSecret = $tokenService->decrypt($connection->appSecret);
        $appSecretIsEnvVar = $decryptedAppSecret !== null && str_starts_with($decryptedAppSecret, '$');
        $decryptedToken = $tokenService->decrypt($connection->accessToken);
        $maskedToken = $tokenService->maskToken($decryptedToken);

        $isExpiringSoon = $tokenService->isTokenExpiringSoon($siteId, 'instagram');
        $isExpired = $tokenService->isTokenExpired($siteId, 'instagram');
        $hasToken = $decryptedToken !== null;

        $rateLimitKey = 'social-stream:rate-limited:instagram:' . $siteId;
        $isRateLimited = Craft::$app->cache->get($rateLimitKey) !== false;

        return $this->renderTemplate('social-stream/settings/index', [
            'site' => $site,
            'allSites' => Craft::$app->sites->getAllSites(),
            'connection' => $connection,
            'settingsRecord' => $settingsRecord,
            'decryptedAppId' => $decryptedAppId,
            'decryptedAppSecret' => $appSecretIsEnvVar ? $decryptedAppSecret : null,
            'appSecretIsEnvVar' => $appSecretIsEnvVar,
            'maskedToken' => $maskedToken,
            'hasToken' => $hasToken,
            'isExpiringSoon' => $isExpiringSoon,
            'isExpired' => $isExpired,
            'isRateLimited' => $isRateLimited,
            'apiVersion' => InstagramProvider::API_VERSION,
        ]);
    }

    /**
     * AJAX: Test the Instagram connection by calling GET /me.
     */
    public function actionTestConnection(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $siteId = (int) Craft::$app->request->getRequiredBodyParam('siteId');

        $token = SocialStream::$plugin->token->getAccessToken($siteId, 'instagram');
        if (!$token) {
            return $this->asJson([
                'success' => false,
                'error' => Craft::t('social-stream', 'No access token available. Please authorise first.'),
            ]);
        }

        $provider = SocialStream::$plugin->providers->requireProviderByHandle('instagram');
        $result = $provider->fetchProfile($siteId);

        if ($result['success']) {
            return $this->asJson([
                'success' => true,
                'data' => $result['data'],
            ]);
        }

        return $this->asJson([
            'success' => false,
            'error' => $result['error'] ?? Craft::t('social-stream', 'Unknown error.'),
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

        $settingsRecord = SettingsRecord::findOne(['siteId' => $siteId]);
        $limit = $settingsRecord->defaultLimit ?? 25;

        RefreshStreamJob::pushIfNotQueued($siteId, ['limit' => $limit], 'instagram');

        return $this->asJson([
            'success' => true,
            'message' => Craft::t('social-stream', 'Stream refresh job has been queued.'),
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
     * AJAX: Return the current stream data for preview in the CP.
     */
    public function actionPreviewStream(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $siteId = (int) Craft::$app->request->getRequiredBodyParam('siteId');
        $settingsRecord = SettingsRecord::findOne(['siteId' => $siteId]);
        $limit = $settingsRecord->defaultLimit ?? 25;

        $options = [
            'siteId' => $siteId,
            'limit' => $limit,
            'provider' => 'instagram',
        ];

        $cached = false;
        $cacheResult = SocialStream::$plugin->streamCache->getStream($options);

        if ($cacheResult['data'] !== null) {
            $streamResponse = SocialStream::$plugin->streamCache->deserializeStreamResponse($cacheResult['data']);
            $cached = true;
        } else {
            $provider = SocialStream::$plugin->providers->requireProviderByHandle('instagram');
            $streamResponse = $provider->fetchStream($options);
        }

        if (!($streamResponse['success'] ?? false)) {
            return $this->asJson([
                'success' => false,
                'error' => $streamResponse['error'] ?? Craft::t('social-stream', 'Failed to load stream.'),
            ]);
        }

        $posts = array_map(
            fn($post) => $post instanceof Post ? $post->toArray() : $post,
            $streamResponse['data'] ?? [],
        );

        return $this->asJson([
            'success' => true,
            'data' => $posts,
            'cached' => $cached,
        ]);
    }

    /**
     * Save connection settings (App ID, App Secret) and site settings.
     */
    public function actionSave(): ?Response
    {
        $this->requirePostRequest();

        $request = Craft::$app->request;
        $siteId = (int) $request->getRequiredBodyParam('siteId');
        $tokenService = SocialStream::$plugin->token;

        $connection = $tokenService->getConnection($siteId, 'instagram');
        $connection->appId = $tokenService->encrypt($request->getBodyParam('appId'));
        $appSecret = $request->getBodyParam('appSecret');
        if ($appSecret !== null && $appSecret !== '') {
            $connection->appSecret = $tokenService->encrypt($appSecret);
        }

        if (!$connection->save()) {
            Craft::$app->session->setError(Craft::t('social-stream', 'Couldn\'t save settings.'));
            return null;
        }

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

        $site = Craft::$app->sites->getSiteById($siteId);
        return $this->redirect(
            \craft\helpers\UrlHelper::cpUrl('social-stream/settings/' . $site->handle)
        );
    }
}
