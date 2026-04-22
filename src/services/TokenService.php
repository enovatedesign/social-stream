<?php

namespace enovate\socialstream\services;

use Craft;
use craft\base\Component;
use craft\helpers\App;
use craft\helpers\DateTimeHelper;
use DateTime;
use enovate\socialstream\providers\InstagramProvider;
use enovate\socialstream\records\ConnectionRecord;
use enovate\socialstream\SocialStream;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Handles OAuth token exchange, refresh, encryption, and storage.
 */
class TokenService extends Component
{
    /**
     * Days before a token's expiry at which the consolidated cron should
     * proactively queue a refresh.
     */
    public const REFRESH_THRESHOLD_DAYS = 7;

    // -------------------------------------------------------------------------
    // Encryption helpers
    // -------------------------------------------------------------------------

    /**
     * Encrypt a value for database storage.
     */
    public function encrypt(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return base64_encode(Craft::$app->security->encryptByKey($value));
    }

    /**
     * Decrypt a value read from the database.
     */
    public function decrypt(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $decrypted = Craft::$app->security->decryptByKey(base64_decode($value));

        return $decrypted === false ? null : $decrypted;
    }

    // -------------------------------------------------------------------------
    // Connection record helpers
    // -------------------------------------------------------------------------

    /**
     * Find or create a connection record for a given site and provider.
     */
    public function getConnection(int $siteId, string $provider): ConnectionRecord
    {
        $record = ConnectionRecord::findOne([
            'siteId' => $siteId,
            'provider' => $provider,
        ]);

        if ($record === null) {
            $record = new ConnectionRecord();
            $record->siteId = $siteId;
            $record->provider = $provider;
        }

        return $record;
    }

    /**
     * Get the decrypted access token for a site.
     */
    public function getAccessToken(int $siteId, string $provider): ?string
    {
        $record = ConnectionRecord::findOne([
            'siteId' => $siteId,
            'provider' => $provider,
        ]);

        if ($record === null) {
            return null;
        }

        return $this->decrypt($record->accessToken);
    }

    /**
     * Get the decrypted App ID for a connection, resolving env vars.
     */
    public function getAppId(int $siteId, string $provider): ?string
    {
        $record = ConnectionRecord::findOne([
            'siteId' => $siteId,
            'provider' => $provider,
        ]);

        if ($record === null) {
            return null;
        }

        $raw = $this->decrypt($record->appId);

        return $raw ? App::parseEnv($raw) : null;
    }

    /**
     * Get the decrypted App Secret for a connection, resolving env vars.
     */
    public function getAppSecret(int $siteId, string $provider): ?string
    {
        $record = ConnectionRecord::findOne([
            'siteId' => $siteId,
            'provider' => $provider,
        ]);

        if ($record === null) {
            return null;
        }

        $raw = $this->decrypt($record->appSecret);

        return $raw ? App::parseEnv($raw) : null;
    }

    // -------------------------------------------------------------------------
    // OAuth token exchange
    // -------------------------------------------------------------------------

    /**
     * Exchange an authorisation code for a short-lived token, then immediately
     * exchange that for a long-lived token and store it encrypted.
     *
     * @return array{success: bool, error: string|null}
     */
    public function exchangeAuthCode(string $code, int $siteId): array
    {
        $connection = $this->getConnection($siteId, 'instagram');
        $appId = $this->decrypt($connection->appId);
        $appSecret = $this->decrypt($connection->appSecret);

        if (!$appId || !$appSecret) {
            return ['success' => false, 'error' => 'App ID and App Secret must be configured before authorising.'];
        }

        $appId = App::parseEnv($appId);
        $appSecret = App::parseEnv($appSecret);

        // Step 1: Exchange code for short-lived token
        $shortToken = $this->_getShortAccessToken($code, $appId, $appSecret, $siteId);

        if ($shortToken === null) {
            return ['success' => false, 'error' => 'Failed to obtain short-lived token from Instagram.'];
        }

        // Step 2: Exchange short-lived for long-lived token
        $result = $this->_getLongAccessToken($shortToken, $appSecret);

        if ($result === null) {
            return ['success' => false, 'error' => 'Failed to exchange for long-lived token.'];
        }

        // Step 3: Store encrypted token and expiry
        $connection->accessToken = $this->encrypt($result['token']);
        $connection->tokenExpiresAt = $result['expiresAt'];
        $connection->lastError = null;
        $connection->lastErrorAt = null;

        if (!$connection->save()) {
            SocialStream::error('Failed to save connection record after token exchange.');
            return ['success' => false, 'error' => 'Failed to save token to database.'];
        }

        SocialStream::info('Successfully obtained and stored long-lived token for site ' . $siteId);
        return ['success' => true, 'error' => null, 'token' => $result['token']];
    }

    /**
     * Exchange an authorisation code for a short-lived access token.
     */
    private function _getShortAccessToken(string $code, string $appId, string $appSecret, int $siteId): ?string
    {
        try {
            $client = Craft::createGuzzleClient();
            $response = $client->post('https://api.instagram.com/oauth/access_token', [
                'form_params' => [
                    'client_id' => $appId,
                    'client_secret' => $appSecret,
                    'grant_type' => 'authorization_code',
                    'redirect_uri' => $this->_getRedirectUri(),
                    'code' => $code,
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            return $data['access_token'] ?? null;
        } catch (GuzzleException $e) {
            SocialStream::error('Short-lived token exchange failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Exchange a short-lived token for a long-lived token (60-day validity).
     *
     * @return array{token: string, expiresAt: string}|null
     */
    private function _getLongAccessToken(string $shortToken, string $appSecret): ?array
    {
        try {
            $client = Craft::createGuzzleClient();
            $url = InstagramProvider::TOKEN_BASE_URL . '/' . InstagramProvider::API_VERSION . '/access_token';

            $response = $client->get($url, [
                'query' => [
                    'grant_type' => 'ig_exchange_token',
                    'client_secret' => $appSecret,
                    'access_token' => $shortToken,
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);
            $token = $data['access_token'] ?? null;
            $expiresIn = $data['expires_in'] ?? null;

            if ($token === null) {
                SocialStream::error('Long-lived token exchange returned no access_token.');
                return null;
            }

            $expiresAt = (new DateTime())->modify("+{$expiresIn} seconds")->format('Y-m-d H:i:s');

            return [
                'token' => $token,
                'expiresAt' => $expiresAt,
            ];
        } catch (\GuzzleHttp\Exception\ClientException $e) {
            $responseBody = $e->getResponse()->getBody()->getContents();
            SocialStream::error('Long-lived token exchange failed (HTTP ' . $e->getResponse()->getStatusCode() . '): ' . $responseBody);
            return null;
        } catch (GuzzleException $e) {
            SocialStream::error('Long-lived token exchange failed: ' . $e->getMessage());
            return null;
        }
    }

    // -------------------------------------------------------------------------
    // Token refresh
    // -------------------------------------------------------------------------

    /**
     * Refresh the long-lived token for a given site.
     *
     * @return array{success: bool, error: string|null}
     */
    public function refreshToken(int $siteId, string $provider): array
    {
        $connection = $this->getConnection($siteId, $provider);
        $currentToken = $this->decrypt($connection->accessToken);

        if (!$currentToken) {
            return ['success' => false, 'error' => 'No access token found for site ' . $siteId];
        }

        try {
            $client = Craft::createGuzzleClient();
            $url = InstagramProvider::TOKEN_BASE_URL . '/' . InstagramProvider::API_VERSION . '/refresh_access_token';

            $response = $client->get($url, [
                'query' => [
                    'grant_type' => 'ig_refresh_token',
                    'access_token' => $currentToken,
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);
            $newToken = $data['access_token'] ?? null;
            $expiresIn = $data['expires_in'] ?? null;

            if ($newToken === null) {
                $error = 'Instagram returned no access token during refresh.';
                $connection->lastError = $error;
                $connection->lastErrorAt = DateTimeHelper::currentUTCDateTime()->format('Y-m-d H:i:s');
                $connection->save();

                SocialStream::warning($error);
                return ['success' => false, 'error' => $error];
            }

            $expiresAt = (new DateTime())->modify("+{$expiresIn} seconds")->format('Y-m-d H:i:s');

            $connection->accessToken = $this->encrypt($newToken);
            $connection->tokenExpiresAt = $expiresAt;
            $connection->lastError = null;
            $connection->lastErrorAt = null;
            $connection->save();

            SocialStream::info('Successfully refreshed token for site ' . $siteId . '. Expires ' . $expiresAt);
            return ['success' => true, 'error' => null];
        } catch (GuzzleException $e) {
            $error = 'Token refresh failed: ' . $e->getMessage();
            $connection->lastError = $error;
            $connection->lastErrorAt = DateTimeHelper::currentUTCDateTime()->format('Y-m-d H:i:s');
            $connection->save();

            SocialStream::warning($error);
            return ['success' => false, 'error' => $error];
        }
    }

    /**
     * Refresh tokens for all sites that have a connection.
     *
     * @return array{success: bool, results: array}
     */
    public function refreshAllTokens(string $provider): array
    {
        $connections = ConnectionRecord::findAll(['provider' => $provider]);
        $results = [];
        $allSuccess = true;

        foreach ($connections as $connection) {
            $result = $this->refreshToken($connection->siteId, $provider);
            $results[$connection->siteId] = $result;

            if (!$result['success']) {
                $allSuccess = false;
            }
        }

        return ['success' => $allSuccess, 'results' => $results];
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Check whether a connection's token is expiring within a given number of days.
     */
    public function isTokenExpiringSoon(int $siteId, string $provider, int $days = 7): bool
    {
        $record = ConnectionRecord::findOne([
            'siteId' => $siteId,
            'provider' => $provider,
        ]);

        if ($record === null || $record->tokenExpiresAt === null) {
            return false;
        }

        $expiresAt = new DateTime($record->tokenExpiresAt);
        $warningDate = (new DateTime())->modify("+{$days} days");

        return $expiresAt <= $warningDate;
    }

    /**
     * Check whether a connection's token has expired.
     */
    public function isTokenExpired(int $siteId, string $provider): bool
    {
        $record = ConnectionRecord::findOne([
            'siteId' => $siteId,
            'provider' => $provider,
        ]);

        if ($record === null || $record->tokenExpiresAt === null) {
            return true;
        }

        return new DateTime($record->tokenExpiresAt) <= new DateTime();
    }

    /**
     * Mask a token for display in the CP (e.g. "IGQW...x7Zd").
     */
    public function maskToken(?string $token): string
    {
        if ($token === null || strlen($token) < 8) {
            return '****';
        }

        return substr($token, 0, 4) . '...' . substr($token, -4);
    }

    /**
     * Build the OAuth redirect URI for the callback.
     */
    private function _getRedirectUri(): string
    {
        return rtrim(App::parseEnv(Craft::$app->sites->primarySite->baseUrl), '/')
            . '/actions/social-stream/auth/callback';
    }
}
