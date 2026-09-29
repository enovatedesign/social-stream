<?php

namespace enovate\socialstream\controllers;

use Craft;
use craft\helpers\App;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use enovate\socialstream\auth\OAuthState;
use enovate\socialstream\providers\InstagramProvider;
use enovate\socialstream\SocialStream;
use yii\web\Response;

/**
 * Handles the OAuth flows for the providers that have one: initiation and callback.
 *
 * A provider declaring {@see \enovate\socialstream\base\Provider::usesOAuth()}
 * false — YouTube, which uses an API key — never reaches here, and is turned away
 * rather than sent to an authorisation URL it has no client ID for.
 *
 * All providers share the one callback URL — it has to be registered verbatim with
 * each provider's app, and asking an admin to register a different one per provider
 * invites the mismatch that breaks the flow. The `state` parameter says which
 * provider is coming back; see {@see OAuthState}.
 */
class AuthController extends Controller
{
    /**
     * Allow the callback action to be hit anonymously (providers redirect here).
     */
    protected array|int|bool $allowAnonymous = ['callback'];

    /**
     * Redirect the admin to the provider's OAuth authorisation screen.
     *
     * Triggered from the CP settings page's "Authorise" / "Connect" button.
     */
    public function actionHandleAuth(): Response
    {
        $this->requireCpRequest();

        $request = Craft::$app->request;
        $siteId = (int) $request->getRequiredQueryParam('siteId');
        $provider = $this->_resolveProvider($request->getQueryParam('provider'));

        if (!$this->_usesOAuth($provider)) {
            Craft::$app->session->setError(
                Craft::t('social-stream', '{provider} does not use authorisation — configure it on its own page.', [
                    'provider' => $this->_displayName($provider),
                ])
            );

            return $this->redirect($this->_settingsUrl($siteId));
        }

        $tokenService = SocialStream::$plugin->token;
        $connection = $tokenService->getConnection($siteId, $provider);
        $appId = $tokenService->decrypt($connection->appId);

        if (!$appId) {
            Craft::$app->session->setError(
                Craft::t('social-stream', 'An App ID must be saved before authorising.')
            );

            return $this->redirect($this->_settingsUrl($siteId));
        }

        return $this->redirect($this->_instagramAuthUrl(
            App::parseEnv($appId),
            $tokenService->getRedirectUri(),
            OAuthState::encode($siteId, $provider),
        ));
    }

    /**
     * Handle the OAuth callback.
     *
     * Receives the authorisation code, exchanges it for tokens, runs the provider's
     * post-exchange checks, and redirects back to the CP settings page.
     */
    public function actionCallback(): Response
    {
        $request = Craft::$app->request;
        $state = OAuthState::decode($request->getQueryParam('state'));
        $siteId = $state['siteId'];
        $provider = $state['provider'];

        $code = $request->getQueryParam('code');
        $error = $request->getQueryParam('error');
        $errorReason = $request->getQueryParam('error_reason') ?? $request->getQueryParam('error_description');
        $name = $this->_displayName($provider);

        if ($error) {
            SocialStream::warning('OAuth callback received error: ' . ($errorReason ?? $error));
            Craft::$app->session->setError(
                Craft::t('social-stream', '{provider} authorisation was denied: {error}', [
                    'provider' => $name,
                    'error' => $errorReason ?? $error,
                ])
            );

            return $this->redirect($this->_settingsUrl($siteId));
        }

        if (!$code) {
            Craft::$app->session->setError(
                Craft::t('social-stream', 'No authorisation code received from {provider}.', [
                    'provider' => $name,
                ])
            );

            return $this->redirect($this->_settingsUrl($siteId));
        }

        $result = SocialStream::$plugin->token->exchangeAuthCode($code, $siteId, $provider);

        if (!$result['success']) {
            Craft::$app->session->setError($result['error']);

            return $this->redirect($this->_settingsUrl($siteId));
        }

        $validation = $this->_validateAccountType($siteId, $result['token'] ?? null);

        if ($validation !== null) {
            Craft::$app->session->setError($validation);

            return $this->redirect($this->_settingsUrl($siteId));
        }

        Craft::$app->session->setNotice(
            Craft::t('social-stream', '{provider} account connected successfully.', ['provider' => $name])
        );

        return $this->redirect($this->_settingsUrl($siteId));
    }

    /**
     * Instagram's authorisation URL.
     */
    private function _instagramAuthUrl(string $appId, string $redirectUri, string $state): string
    {
        $params = http_build_query([
            'enable_fb_login' => 0,
            'force_authentication' => 1,
            'client_id' => $appId,
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => 'instagram_business_basic',
            'state' => $state,
        ]);

        return 'https://www.instagram.com/oauth/authorize?' . $params;
    }

    /**
     * Validate that the connected Instagram account is a Business or Creator account.
     *
     * @return string|null Error message if validation fails, null on success.
     */
    private function _validateAccountType(int $siteId, ?string $token = null): ?string
    {
        if (!$token) {
            $token = SocialStream::$plugin->token->getAccessToken($siteId, InstagramProvider::handle());
        }

        if (!$token) {
            return Craft::t('social-stream', 'Could not retrieve access token for validation.');
        }

        try {
            $client = Craft::createGuzzleClient();
            $url = InstagramProvider::API_BASE_URL . '/' . InstagramProvider::API_VERSION . '/me';

            $response = $client->get($url, [
                'query' => [
                    'fields' => 'id,user_id,username,account_type',
                    'access_token' => $token,
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            // Persist the user ID so stream refreshes survive cache flushes
            $userId = $data['user_id'] ?? $data['id'] ?? null;
            if ($userId) {
                $connection = SocialStream::$plugin->token->getConnection($siteId, InstagramProvider::handle());
                if ($connection) {
                    $connection->providerUserId = $userId;
                    $connection->save();
                }
            }

            $accountType = $data['account_type'] ?? null;

            if ($accountType && !in_array(strtoupper($accountType), ['BUSINESS', 'CREATOR', 'MEDIA_CREATOR'], true)) {
                SocialStream::warning('Connected account type is "' . $accountType . '" — expected Business or Creator.');
                return Craft::t('social-stream', 'The connected Instagram account must be a Business or Creator account. Detected: {type}', [
                    'type' => $accountType,
                ]);
            }

            return null;
        } catch (\Exception $e) {
            SocialStream::error('Account type validation failed: ' . $e->getMessage());
            return null; // Don't block the flow — token is stored, warn separately
        }
    }

    /**
     * Resolve a provider handle from the request, falling back to Instagram so links
     * predating multi-provider support keep working.
     */
    private function _resolveProvider(mixed $handle): string
    {
        if (!is_string($handle) || $handle === '') {
            return InstagramProvider::handle();
        }

        return SocialStream::$plugin->providers->getProviderByHandle($handle) === null
            ? InstagramProvider::handle()
            : $handle;
    }

    /**
     * Whether a provider has an authorisation flow at all. An unregistered handle is
     * assumed to, matching {@see _resolveProvider()}'s fallback.
     */
    private function _usesOAuth(string $provider): bool
    {
        $registered = SocialStream::$plugin->providers->getProviderByHandle($provider);

        return $registered === null || $registered::usesOAuth();
    }

    private function _displayName(string $provider): string
    {
        return SocialStream::$plugin->providers->getProviderByHandle($provider)?->getDisplayName()
            ?? ucfirst($provider);
    }

    /**
     * Build the CP settings URL for a given site, including the site handle.
     */
    private function _settingsUrl(int $siteId): string
    {
        $site = $siteId > 0 ? Craft::$app->sites->getSiteById($siteId) : null;

        return $site
            ? UrlHelper::cpUrl('social-stream/settings/' . $site->handle)
            : UrlHelper::cpUrl('social-stream/settings');
    }
}
