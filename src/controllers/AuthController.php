<?php

namespace enovate\socialstream\controllers;

use Craft;
use craft\helpers\App;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use enovate\socialstream\SocialStream;
use yii\web\Response;

/**
 * Handles the Instagram OAuth flow: initiation and callback.
 */
class AuthController extends Controller
{
    /**
     * Allow the callback action to be hit anonymously (Instagram redirects here).
     */
    protected array|int|bool $allowAnonymous = ['callback'];

    /**
     * Redirect the admin to Instagram's OAuth authorisation screen.
     *
     * Triggered from the CP settings page "Authorise" button.
     */
    public function actionHandleAuth(): Response
    {
        $this->requireCpRequest();

        $siteId = (int) Craft::$app->request->getRequiredQueryParam('siteId');
        $connection = SocialStream::$plugin->token->getConnection($siteId, 'instagram');

        $appId = SocialStream::$plugin->token->decrypt($connection->appId);

        if (!$appId) {
            Craft::$app->session->setError(
                Craft::t('social-stream', 'An App ID must be saved before authorising.')
            );
            return $this->redirect($this->_settingsUrl($siteId));
        }

        $appId = App::parseEnv($appId);
        $redirectUri = rtrim(App::parseEnv(Craft::$app->sites->primarySite->baseUrl), '/')
            . '/actions/social-stream/auth/callback';

        $params = http_build_query([
            'enable_fb_login' => 0,
            'force_authentication' => 1,
            'client_id' => $appId,
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => 'instagram_business_basic',
            'state' => $siteId,
        ]);

        return $this->redirect('https://www.instagram.com/oauth/authorize?' . $params);
    }

    /**
     * Handle the OAuth callback from Instagram.
     *
     * Receives the authorisation code, exchanges it for tokens,
     * and redirects back to the CP settings page.
     */
    public function actionCallback(): Response
    {
        $code = Craft::$app->request->getQueryParam('code');
        $siteId = (int) Craft::$app->request->getQueryParam('state');
        $error = Craft::$app->request->getQueryParam('error');
        $errorReason = Craft::$app->request->getQueryParam('error_reason');

        if ($error) {
            SocialStream::warning('OAuth callback received error: ' . ($errorReason ?? $error));
            Craft::$app->session->setError(
                Craft::t('social-stream', 'Instagram authorisation was denied: {error}', [
                    'error' => $errorReason ?? $error,
                ])
            );
            return $this->redirect($this->_settingsUrl($siteId));
        }

        if (!$code) {
            Craft::$app->session->setError(
                Craft::t('social-stream', 'No authorisation code received from Instagram.')
            );
            return $this->redirect($this->_settingsUrl($siteId));
        }

        // Exchange the code for tokens
        $result = SocialStream::$plugin->token->exchangeAuthCode($code, $siteId);

        if ($result['success']) {
            // Validate the account is Business/Creator by making a test /me call
            $validation = $this->_validateAccountType($siteId, $result['token']);

            if ($validation !== null) {
                Craft::$app->session->setError($validation);
                return $this->redirect($this->_settingsUrl($siteId));
            }

            Craft::$app->session->setNotice(
                Craft::t('social-stream', 'Instagram account connected successfully.')
            );
        } else {
            Craft::$app->session->setError($result['error']);
        }

        return $this->redirect($this->_settingsUrl($siteId));
    }

    /**
     * Validate that the connected account is a Business or Creator account.
     *
     * @return string|null Error message if validation fails, null on success.
     */
    private function _validateAccountType(int $siteId, ?string $token = null): ?string
    {
        if (!$token) {
            $token = SocialStream::$plugin->token->getAccessToken($siteId, 'instagram');
        }

        if (!$token) {
            return Craft::t('social-stream', 'Could not retrieve access token for validation.');
        }

        try {
            $client = Craft::createGuzzleClient();
            $url = \enovate\socialstream\providers\InstagramProvider::API_BASE_URL
                . '/' . \enovate\socialstream\providers\InstagramProvider::API_VERSION
                . '/me';

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
                $connection = SocialStream::$plugin->token->getConnection($siteId, 'instagram');
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
     * Build the CP settings URL for a given site, including the site handle.
     */
    private function _settingsUrl(int $siteId): string
    {
        $site = Craft::$app->sites->getSiteById($siteId);

        return $site
            ? UrlHelper::cpUrl('social-stream/settings/' . $site->handle)
            : UrlHelper::cpUrl('social-stream/settings');
    }
}
