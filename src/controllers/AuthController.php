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
 *
 * Nothing provider-specific lives here. Where to send the admin, and what to check
 * once the tokens are stored, come from the provider itself — this controller only
 * sequences them.
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
        $instance = SocialStream::$plugin->providers->getProviderByHandle($provider);

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

        $authUrl = $instance?->authorizationUrl(
            App::parseEnv($appId),
            $tokenService->getRedirectUri(),
            OAuthState::encode($siteId, $provider),
        );

        // Each provider says where its own authorisation screen is. Building one
        // provider's URL for all of them would send the rest somewhere that has never
        // heard of their client ID.
        if ($authUrl === null) {
            Craft::$app->session->setError(
                Craft::t('social-stream', '{provider} does not publish an authorisation URL.', [
                    'provider' => $this->_displayName($provider),
                ])
            );

            return $this->redirect($this->_settingsUrl($siteId));
        }

        return $this->redirect($authUrl);
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

        // The provider's own post-exchange checks. Instagram's used to run for every
        // provider, which meant asking Instagram about another provider's token and
        // writing the answer to Instagram's connection row.
        $validation = SocialStream::$plugin->providers
            ->getProviderByHandle($provider)
            ?->completeAuthorization($siteId, $result['token'] ?? null);

        if ($validation !== null) {
            Craft::$app->session->setError($validation);

            return $this->redirect($this->_settingsUrl($siteId));
        }

        $this->_cacheProfile($siteId, $provider);

        Craft::$app->session->setNotice(
            Craft::t('social-stream', '{provider} account connected successfully.', ['provider' => $name])
        );

        return $this->redirect($this->_settingsUrl($siteId));
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
     * Name the account now, while the admin is here.
     *
     * The CP never fetches a profile itself — opening a settings page must not spend a
     * provider's quota — so without this the Providers table would identify a healthy
     * connection by its raw numeric ID, and the provider's own panel would show no
     * avatar or username, until something else happened to fetch one.
     *
     * Never fatal. The tokens are already stored and validated by this point, so a
     * failure here must not turn a connection that worked into an error page the
     * admin will respond to by connecting again.
     */
    private function _cacheProfile(int $siteId, string $provider): void
    {
        try {
            $result = SocialStream::$plugin->providers->requireProviderByHandle($provider)->fetchProfile($siteId);

            // fetchProfile() remembers the account's name but does not cache the
            // response, so the panel's avatar and username need this second step.
            if ($result['success'] ?? false) {
                SocialStream::$plugin->streamCache->setProfile($siteId, $result, $provider);
            }
        } catch (\Throwable $e) {
            SocialStream::error('Could not read the profile after connecting: ' . $e->getMessage());
        }
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
