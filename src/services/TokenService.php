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
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Handles OAuth token exchange, refresh, encryption, and storage.
 *
 * Only providers that authenticate with OAuth have anything here — Instagram, whose
 * durable credential is the 60-day access token itself, refreshed in place before it
 * expires. A provider declaring {@see \enovate\socialstream\base\Provider::usesOAuth()}
 * false stores an API key instead, read through {@see getApiKey()}, and is turned
 * away by the exchange and refresh paths rather than silently doing nothing.
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
     * expiry is renewed here rather than handed out to fail. No provider currently
     * ships one, but the path is kept: it is the only safe way to hand out a
     * short-lived credential, and removing it would have to be rediscovered.
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
     * Concurrent requests all arrive at expiry together, and letting every one of
     * them post to the token endpoint is wasted latency. The loser of the race waits,
     * then re-reads: by then the winner has usually stored a fresh token and no
     * second call is made.
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

    /**
     * Get the decrypted API key for a connection, resolving env vars.
     *
     * The credential for a provider that doesn't use OAuth. It is a secret in the
     * sense that it spends the project's quota, so it is stored encrypted and is
     * never handed back to the browser.
     */
    public function getApiKey(int $siteId, string $provider): ?string
    {
        $record = ConnectionRecord::findOne([
            'siteId' => $siteId,
            'provider' => $provider,
        ]);

        if ($record === null) {
            return null;
        }

        $raw = $this->decrypt($record->apiKey);

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
        if (!$this->usesOAuth($provider)) {
            return ['success' => false, 'error' => $this->notAnOAuthProvider($provider)];
        }

        $connection = $this->getConnection($siteId, $provider);
        $appId = $this->decrypt($connection->appId);
        $appSecret = $this->decrypt($connection->appSecret);

        if (!$appId || !$appSecret) {
            return [
                'success' => false,
                'error' => 'App ID and App Secret must be configured before authorising.',
            ];
        }

        // Instagram's two-step exchange is Instagram's, not a shared default. Running it
        // for another provider would post that provider's code to Instagram's token
        // endpoint and report the refusal as a failed exchange.
        if ($provider !== InstagramProvider::handle()) {
            return [
                'success' => false,
                'error' => $this->noAuthCodeExchange($provider),
            ];
        }

        return $this->_exchangeInstagramAuthCode(
            $code,
            $siteId,
            $connection,
            App::parseEnv($appId),
            App::parseEnv($appSecret),
        );
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
     * Renew the credential a provider expects to be renewed — Instagram's
     * long-lived access token, in place.
     *
     * @return array{success: bool, error: string|null}
     */
    public function refreshToken(int $siteId, string $provider): array
    {
        if (!$this->usesOAuth($provider)) {
            return ['success' => false, 'error' => $this->notAnOAuthProvider($provider)];
        }

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
     * Whether a provider authenticates with OAuth. An unregistered handle is
     * assumed to, since that is what every path here was written for.
     */
    private function usesOAuth(string $provider): bool
    {
        $registered = SocialStream::$plugin->providers->getProviderByHandle($provider);

        return $registered === null || $registered::usesOAuth();
    }

    /**
     * A provider that uses OAuth but whose code exchange this service does not know.
     *
     * Instagram's is the only one implemented, and it is specific to Instagram: two
     * steps, its endpoints, its long-lived token. Saying so is better than posting
     * somebody else's authorisation code to graph.instagram.com.
     */
    private function noAuthCodeExchange(string $provider): string
    {
        $name = SocialStream::$plugin->providers->getProviderByHandle($provider)?->getDisplayName()
            ?? ucfirst($provider);

        return 'Social Stream does not know how to exchange an authorisation code for '
            . $name . '. Only Instagram\'s OAuth flow is implemented.';
    }

    private function notAnOAuthProvider(string $provider): string
    {
        $name = SocialStream::$plugin->providers->getProviderByHandle($provider)?->getDisplayName()
            ?? ucfirst($provider);

        return $name . ' does not use OAuth — it authenticates with an API key, which never expires '
            . 'and has nothing to refresh.';
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
     * A provider with no OAuth has nothing to refresh, and reports success rather
     * than an error: it is called for every registered provider by the cron, and a
     * red line about YouTube on every run would be noise, not a fault.
     *
     * @return array{success: bool, results: array}
     */
    public function refreshAllTokens(string $provider): array
    {
        if (!$this->usesOAuth($provider)) {
            return ['success' => true, 'results' => []];
        }

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
