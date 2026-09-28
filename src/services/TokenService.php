<?php

namespace enovate\socialstream\services;

use Craft;
use craft\base\Component;
use craft\helpers\App;
use craft\helpers\DateTimeHelper;
use DateTime;
use enovate\socialstream\auth\GoogleTokenClient;
use enovate\socialstream\providers\InstagramProvider;
use enovate\socialstream\providers\YouTubeProvider;
use enovate\socialstream\records\ConnectionRecord;
use enovate\socialstream\SocialStream;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Handles OAuth token exchange, refresh, encryption, and storage.
 *
 * The two providers have opposite credential shapes, which is why several methods
 * here branch on the provider handle: Instagram's durable credential is the
 * 60-day access token itself, refreshed in place before it expires, while Google
 * issues a permanent refresh token plus an access token that lasts an hour and is
 * renewed inline on demand.
 */
class TokenService extends Component
{
    /**
     * Days before a token's expiry at which the consolidated cron should
     * proactively queue a refresh.
     */
    public const REFRESH_THRESHOLD_DAYS = 7;

    /**
     * How close to expiry (seconds) an access token has to be before
     * {@see getAccessToken()} renews it inline rather than handing it out.
     */
    public const INLINE_REFRESH_SKEW = 300;

    /**
     * What to tell an admin whose Google refresh token has stopped working. The
     * 7-day cadence is the giveaway for an app still in Testing mode, and saying
     * so here saves the guesswork — see the README.
     */
    public const GOOGLE_REAUTH_MESSAGE = 'YouTube refresh token expired or revoked. '
        . 'Please reconnect your YouTube channel. If this happens every 7 days, your Google app '
        . 'may be in Testing mode — see the documentation for how to publish it.';

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
     *
     * For a connection that holds a refresh token, an access token at or near
     * expiry is renewed here rather than handed out to fail — YouTube's lasts an
     * hour, so every provider call would otherwise have to handle expiry itself.
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

        if ($this->needsInlineRefresh($record)) {
            $record = $this->refreshInline($siteId, $provider) ?? $record;
        }

        return $this->decrypt($record->accessToken);
    }

    /**
     * Get the decrypted refresh token for a connection, if it has one.
     */
    public function getRefreshToken(int $siteId, string $provider): ?string
    {
        $record = ConnectionRecord::findOne([
            'siteId' => $siteId,
            'provider' => $provider,
        ]);

        return $record === null ? null : $this->decrypt($record->refreshToken);
    }

    /**
     * Whether this connection's access token should be renewed before use.
     *
     * Only connections with a refresh token qualify: Instagram has none, and its
     * access token is refreshed by the cron well ahead of its 60-day expiry, so it
     * must never be renewed on a front-end request. A credential the provider has
     * already rejected is left alone — retrying it would fail the same way.
     */
    private function needsInlineRefresh(ConnectionRecord $record): bool
    {
        if ($record->refreshToken === null || $record->refreshToken === '') {
            return false;
        }

        if ($record->needsReauthAt !== null) {
            return false;
        }

        if ($record->tokenExpiresAt === null) {
            return true;
        }

        $expiresAt = DateTimeHelper::toDateTime($record->tokenExpiresAt);

        if ($expiresAt === false) {
            return true;
        }

        return $expiresAt <= (new DateTime())->modify('+' . self::INLINE_REFRESH_SKEW . ' seconds');
    }

    /**
     * Renew an access token in the middle of a request, under a lock.
     *
     * Concurrent requests all arrive at expiry together, and while Google tolerates
     * several live access tokens, letting every one of them post to the token
     * endpoint is wasted latency. The loser of the race waits, then re-reads: by
     * then the winner has usually stored a fresh token and no second call is made.
     *
     * @return ConnectionRecord|null The reloaded connection, or null if nothing was refreshed.
     */
    private function refreshInline(int $siteId, string $provider): ?ConnectionRecord
    {
        $mutex = Craft::$app->getMutex();
        $lockName = 'social-stream:token-refresh:' . $siteId . ':' . $provider;

        if (!$mutex->acquire($lockName, 5)) {
            SocialStream::info(
                'Waited for another inline token refresh for site ' . $siteId . ' (' . $provider
                . ') without acquiring the lock; using the stored token.'
            );

            return $this->reloadConnection($siteId, $provider);
        }

        try {
            $record = $this->reloadConnection($siteId, $provider);

            // Re-check under the lock: whoever held it may have just refreshed.
            if ($record === null || !$this->needsInlineRefresh($record)) {
                return $record;
            }

            $this->refreshToken($siteId, $provider);

            return $this->reloadConnection($siteId, $provider);
        } finally {
            $mutex->release($lockName);
        }
    }

    private function reloadConnection(int $siteId, string $provider): ?ConnectionRecord
    {
        return ConnectionRecord::findOne([
            'siteId' => $siteId,
            'provider' => $provider,
        ]);
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
     * Exchange an authorisation code for the credentials the provider issues, and
     * store them encrypted.
     *
     * @param string $provider Defaults to Instagram so pre-1.4 callers keep working.
     * @return array{success: bool, error: string|null, token?: string}
     */
    public function exchangeAuthCode(string $code, int $siteId, string $provider = 'instagram'): array
    {
        $connection = $this->getConnection($siteId, $provider);
        $appId = $this->decrypt($connection->appId);
        $appSecret = $this->decrypt($connection->appSecret);

        if (!$appId || !$appSecret) {
            return [
                'success' => false,
                'error' => $provider === YouTubeProvider::handle()
                    ? 'Client ID and Client Secret must be configured before authorising.'
                    : 'App ID and App Secret must be configured before authorising.',
            ];
        }

        $appId = App::parseEnv($appId);
        $appSecret = App::parseEnv($appSecret);

        if ($provider === YouTubeProvider::handle()) {
            return $this->_exchangeGoogleAuthCode($code, $siteId, $connection, $appId, $appSecret);
        }

        return $this->_exchangeInstagramAuthCode($code, $siteId, $connection, $appId, $appSecret);
    }

    /**
     * Instagram: exchange the code for a short-lived token, then immediately
     * exchange that for the 60-day long-lived token.
     *
     * @return array{success: bool, error: string|null, token?: string}
     */
    private function _exchangeInstagramAuthCode(
        string $code,
        int $siteId,
        ConnectionRecord $connection,
        string $appId,
        string $appSecret,
    ): array {
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
        $connection->needsReauthAt = null;

        if (!$connection->save()) {
            SocialStream::error('Failed to save connection record after token exchange.');
            return ['success' => false, 'error' => 'Failed to save token to database.'];
        }

        SocialStream::info('Successfully obtained and stored long-lived token for site ' . $siteId);
        return ['success' => true, 'error' => null, 'token' => $result['token']];
    }

    /**
     * Google: exchange the code for an access token and a refresh token.
     *
     * The channel ID is not fetched here — that is a YouTube Data API call, so it
     * belongs to the provider, which resolves and stores it on first use exactly as
     * Instagram's user ID is resolved.
     *
     * @return array{success: bool, error: string|null, token?: string}
     */
    private function _exchangeGoogleAuthCode(
        string $code,
        int $siteId,
        ConnectionRecord $connection,
        string $clientId,
        string $clientSecret,
    ): array {
        $result = (new GoogleTokenClient())->exchangeCode(
            $code,
            $clientId,
            $clientSecret,
            $this->getRedirectUri(),
        );

        if (!$result['success']) {
            return ['success' => false, 'error' => $result['error']];
        }

        // Google only issues a refresh token when it feels like it — on a repeat
        // authorisation with an existing grant it can return none at all. Keeping
        // the stored one is the difference between a connection that survives the
        // next hour and one that silently can't renew.
        $refreshToken = $result['refreshToken'] !== null
            ? $this->encrypt($result['refreshToken'])
            : $connection->refreshToken;

        if ($refreshToken === null) {
            return [
                'success' => false,
                'error' => 'Google returned no refresh token. Revoke the plugin\'s access in your '
                    . 'Google Account permissions and authorise again.',
            ];
        }

        $connection->accessToken = $this->encrypt($result['accessToken']);
        $connection->refreshToken = $refreshToken;
        $connection->tokenExpiresAt = $this->expiryFromNow($result['expiresIn'] ?? 3600);
        $connection->lastError = null;
        $connection->lastErrorAt = null;
        $connection->needsReauthAt = null;

        if (!$connection->save()) {
            SocialStream::error(
                'Google issued tokens but they could not be saved: ' . json_encode($connection->getErrors())
            );

            return ['success' => false, 'error' => 'Failed to save token to database.'];
        }

        SocialStream::info('Stored Google access and refresh tokens for site ' . $siteId);

        return ['success' => true, 'error' => null, 'token' => $result['accessToken']];
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
                    'redirect_uri' => $this->getRedirectUri(),
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

            $expiresAt = DateTimeHelper::currentUTCDateTime()
                ->modify("+{$expiresIn} seconds")
                ->format('Y-m-d H:i:s');

            return [
                'token' => $token,
                'expiresAt' => $expiresAt,
            ];
        } catch (ClientException $e) {
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
     * Renew the credential a provider expects to be renewed: Instagram's
     * long-lived access token in place, or a fresh Google access token from the
     * stored refresh token.
     *
     * @return array{success: bool, error: string|null}
     */
    public function refreshToken(int $siteId, string $provider): array
    {
        $connection = $this->getConnection($siteId, $provider);

        if ($provider === YouTubeProvider::handle()) {
            return $this->_refreshGoogleToken($siteId, $connection);
        }

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

            // UTC, to match how it is read back: the CP renders it and
            // RefreshController parses it with helpers that treat a bare DB string
            // as UTC, and its sibling columns are stored that way too.
            $expiresAt = DateTimeHelper::currentUTCDateTime()
                ->modify("+{$expiresIn} seconds")
                ->format('Y-m-d H:i:s');

            $connection->accessToken = $this->encrypt($newToken);
            $connection->tokenExpiresAt = $expiresAt;
            $connection->lastError = null;
            $connection->lastErrorAt = null;
            $connection->needsReauthAt = null;

            if (!$connection->save()) {
                $error = 'Instagram issued a refreshed token but it could not be saved: '
                    . json_encode($connection->getErrors());
                SocialStream::error($error);

                return ['success' => false, 'error' => $error];
            }

            SocialStream::info('Successfully refreshed token for site ' . $siteId . '. Expires ' . $expiresAt);
            return ['success' => true, 'error' => null];
        } catch (ClientException $e) {
            $body = json_decode($e->getResponse()->getBody()->getContents(), true);
            $error = 'Token refresh failed: ' . ($body['error']['message'] ?? $e->getMessage());

            $connection->lastError = $error;
            $connection->lastErrorAt = DateTimeHelper::currentUTCDateTime()->format('Y-m-d H:i:s');

            // Meta won't refresh a token it has already rejected — re-authorising
            // is the only route back, so say so rather than retrying forever.
            if (($body['error']['code'] ?? null) === InstagramProvider::ERROR_CODE_INVALID_TOKEN) {
                $connection->needsReauthAt ??= DateTimeHelper::currentUTCDateTime()->format('Y-m-d H:i:s');
            }

            if (!$connection->save()) {
                SocialStream::error(
                    'Could not record the rejected-token state for site ' . $siteId . ': '
                    . json_encode($connection->getErrors())
                );
            }

            SocialStream::warning($error);
            return ['success' => false, 'error' => $error];
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
     * Trade the stored Google refresh token for a new access token.
     *
     * @return array{success: bool, error: string|null}
     */
    private function _refreshGoogleToken(int $siteId, ConnectionRecord $connection): array
    {
        $refreshToken = $this->decrypt($connection->refreshToken);

        if (!$refreshToken) {
            $error = 'No YouTube refresh token stored for site ' . $siteId
                . '. Reconnect the channel in the control panel.';
            $this->recordConnectionError($connection, $error);

            return ['success' => false, 'error' => $error];
        }

        $clientId = $this->getAppId($siteId, YouTubeProvider::handle());
        $clientSecret = $this->getAppSecret($siteId, YouTubeProvider::handle());

        if (!$clientId || !$clientSecret) {
            $error = 'Google Client ID and Client Secret must be configured to refresh the YouTube token.';
            $this->recordConnectionError($connection, $error);

            return ['success' => false, 'error' => $error];
        }

        $result = (new GoogleTokenClient())->refresh($refreshToken, $clientId, $clientSecret);

        if (!$result['success']) {
            // A rejected refresh token never recovers — the grant is gone. Clearing
            // both tokens is what makes the CP show "reconnect" rather than a
            // connection that looks live and fails every call.
            if ($result['invalidGrant']) {
                $connection->accessToken = null;
                $connection->refreshToken = null;
                $connection->tokenExpiresAt = null;
                $connection->needsReauthAt ??= DateTimeHelper::currentUTCDateTime()->format('Y-m-d H:i:s');

                $this->recordConnectionError($connection, self::GOOGLE_REAUTH_MESSAGE);
                SocialStream::warning(self::GOOGLE_REAUTH_MESSAGE);

                return ['success' => false, 'error' => self::GOOGLE_REAUTH_MESSAGE];
            }

            $this->recordConnectionError($connection, $result['error']);

            return ['success' => false, 'error' => $result['error']];
        }

        $connection->accessToken = $this->encrypt($result['accessToken']);
        $connection->tokenExpiresAt = $this->expiryFromNow($result['expiresIn'] ?? 3600);
        $connection->lastError = null;
        $connection->lastErrorAt = null;
        $connection->needsReauthAt = null;

        if (!$connection->save()) {
            $error = 'Google issued a refreshed access token but it could not be saved: '
                . json_encode($connection->getErrors());
            SocialStream::error($error);

            return ['success' => false, 'error' => $error];
        }

        SocialStream::info(
            'Refreshed the YouTube access token for site ' . $siteId
            . '. Expires ' . $connection->tokenExpiresAt
        );

        return ['success' => true, 'error' => null];
    }

    /**
     * Record an error against a connection without disturbing its other state.
     */
    private function recordConnectionError(ConnectionRecord $connection, string $error): void
    {
        $connection->lastError = $error;
        $connection->lastErrorAt = DateTimeHelper::currentUTCDateTime()->format('Y-m-d H:i:s');

        if (!$connection->save()) {
            SocialStream::error(
                'Could not record the connection error for site ' . $connection->siteId . ': '
                . json_encode($connection->getErrors())
            );
        }
    }

    /**
     * UTC expiry timestamp for a lifetime in seconds, matching how the column is
     * read back: the CP renders it and RefreshController parses it with helpers
     * that treat a bare DB string as UTC.
     */
    private function expiryFromNow(int $seconds): string
    {
        return DateTimeHelper::currentUTCDateTime()
            ->modify("+{$seconds} seconds")
            ->format('Y-m-d H:i:s');
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

        // toDateTime() reads a bare DB string as UTC, matching how the column is
        // written. Comparing against a system-timezone "now" is safe: DateTime
        // comparisons are absolute instants, not wall-clock strings.
        $expiresAt = DateTimeHelper::toDateTime($record->tokenExpiresAt);

        if ($expiresAt === false) {
            return false;
        }

        return $expiresAt <= (new DateTime())->modify("+{$days} days");
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

        $expiresAt = DateTimeHelper::toDateTime($record->tokenExpiresAt);

        if ($expiresAt === false) {
            return true;
        }

        return $expiresAt <= new DateTime();
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
     *
     * One URL serves every provider — the `state` parameter carries which one is
     * coming back. It has to be registered verbatim with each provider's app, so it
     * is built in exactly one place.
     */
    public function getRedirectUri(): string
    {
        return rtrim(App::parseEnv(Craft::$app->sites->primarySite->baseUrl), '/')
            . '/actions/social-stream/auth/callback';
    }
}
