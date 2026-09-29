<?php

namespace enovate\socialstream\cp;

use Craft;
use craft\helpers\DateTimeHelper;
use DateTime;
use craft\helpers\UrlHelper;
use enovate\socialstream\providers\InstagramProvider;
use enovate\socialstream\providers\youtube\QuotaMeter;
use enovate\socialstream\providers\youtube\WebSubSubscriber;
use enovate\socialstream\providers\YouTubeProvider;
use enovate\socialstream\records\ConnectionRecord;
use enovate\socialstream\services\CacheService;
use enovate\socialstream\SocialStream;

/**
 * Assembles what the control panel shows about a connection.
 *
 * Reads stored and cached state only — opening the settings page must never spend a
 * provider's API quota, so an account name appears once something has fetched the
 * profile and is simply absent until then.
 */
class ProviderPanel
{
    /**
     * Days before expiry at which a token is reported as expiring soon.
     */
    private const EXPIRY_WARNING_DAYS = 7;

    /**
     * One row per registered provider, for the Providers table.
     *
     * Deliberately generic: a provider registered by another plugin appears here with
     * a real status and a working link, without this class knowing anything about it.
     *
     * @return array<int, array>
     */
    public function rows(int $siteId, string $siteHandle): array
    {
        $rows = [];

        foreach (SocialStream::$plugin->providers->getAllProviders() as $handle => $provider) {
            $rows[] = $this->row($handle, $provider->getDisplayName(), $siteId, $siteHandle);
        }

        return $rows;
    }

    /**
     * @return array{handle: string, name: string, configured: bool, connected: bool, status: string, statusLabel: string, account: string|null, lastFetchAt: string|null, lastError: string|null, lastErrorAt: string|null, actionLabel: string, url: string}
     */
    public function row(string $handle, string $name, int $siteId, string $siteHandle): array
    {
        $connection = SocialStream::$plugin->token->getConnection($siteId, $handle);
        $provider = SocialStream::$plugin->providers->getProviderByHandle($handle);
        $usesOAuth = $provider === null || $provider::usesOAuth();

        // Without OAuth there is no token to hold: the key is the configuration and
        // the resolved channel is the connection.
        $configured = $this->isPresent($usesOAuth ? $connection->appId : $connection->apiKey);
        $connected = $this->isPresent($usesOAuth ? $connection->accessToken : $connection->providerUserId);

        [$status, $statusLabel] = $this->status($connection, $configured, $connected);

        return [
            'handle' => $handle,
            'name' => $name,
            'configured' => $configured,
            'connected' => $connected,
            'status' => $status,
            'statusLabel' => $statusLabel,
            'account' => $this->account($siteId, $handle, $connection->providerUserId),
            'lastFetchAt' => $connection->lastFetchAt,
            'lastError' => $connection->lastError,
            'lastErrorAt' => $connection->lastErrorAt,
            // "Connect" is the honest label while there is nothing to configure yet,
            // and it is the action an admin is actually looking for.
            'actionLabel' => $connected
                ? Craft::t('social-stream', 'Configure')
                : Craft::t('social-stream', 'Connect'),
            'url' => UrlHelper::cpUrl(
                'social-stream/settings/' . $siteHandle . '/provider/' . $handle
            ),
        ];
    }

    /**
     * The status dot and its label.
     *
     * Expiry only means anything where a credential expires. An API key doesn't, so
     * those connections stop at "Connected" and never show a token warning.
     *
     * @return array{0: string, 1: string} [status class, label]
     */
    private function status(ConnectionRecord $connection, bool $configured, bool $connected): array
    {
        if ($connection->needsReauthAt !== null) {
            return ['off', Craft::t('social-stream', 'Rejected — reconnect required')];
        }

        if (!$configured) {
            return ['', Craft::t('social-stream', 'Not configured')];
        }

        if (!$connected) {
            return ['pending', Craft::t('social-stream', 'Not connected')];
        }

        $expiresAt = $connection->tokenExpiresAt === null
            ? null
            : (DateTimeHelper::toDateTime($connection->tokenExpiresAt) ?: null);

        if ($expiresAt === null) {
            return ['active', Craft::t('social-stream', 'Connected')];
        }

        if ($expiresAt <= new DateTime()) {
            return ['off', Craft::t('social-stream', 'Token expired')];
        }

        if ($expiresAt <= (new DateTime())->modify('+' . self::EXPIRY_WARNING_DAYS . ' days')) {
            return ['pending', Craft::t('social-stream', 'Token expires {date}', [
                'date' => $expiresAt->format('j M Y'),
            ])];
        }

        return ['active', Craft::t('social-stream', 'Connected')];
    }

    /**
     * The app credentials, shaped for the CP's fields.
     *
     * A secret stored as an environment variable is shown as typed, since the name is
     * not the secret; a literal one is never sent back to the browser — the field
     * renders empty with a placeholder, and an empty submission leaves it alone.
     */
    public function credentials(int $siteId, string $handle): array
    {
        $tokenService = SocialStream::$plugin->token;
        $connection = $tokenService->getConnection($siteId, $handle);
        $appSecret = $tokenService->decrypt($connection->appSecret);
        $isEnvVar = $appSecret !== null && str_starts_with($appSecret, '$');

        return [
            'appId' => $tokenService->decrypt($connection->appId),
            'appSecret' => $isEnvVar ? $appSecret : null,
            'appSecretIsEnvVar' => $isEnvVar,
            'hasAppSecret' => $connection->appSecret !== null,
        ];
    }

    /**
     * Everything the Instagram section renders.
     */
    public function instagram(int $siteId): array
    {
        $handle = InstagramProvider::handle();
        $tokenService = SocialStream::$plugin->token;
        $connection = $tokenService->getConnection($siteId, $handle);
        $accessToken = $tokenService->decrypt($connection->accessToken);

        return $this->credentials($siteId, $handle) + [
            'connection' => $connection,
            'hasToken' => $accessToken !== null,
            'maskedToken' => $tokenService->maskToken($accessToken),
            'isExpiringSoon' => $tokenService->isTokenExpiringSoon($siteId, $handle),
            'isExpired' => $tokenService->isTokenExpired($siteId, $handle),
            'needsReauth' => $connection->needsReauthAt !== null,
            'isRateLimited' => $this->isRateLimited($siteId, $handle),
            'apiVersion' => InstagramProvider::API_VERSION,
            'profile' => $this->cachedProfile($siteId, $handle),
        ];
    }

    /**
     * Everything the YouTube section renders.
     *
     * The API key is never sent back to the browser unless it is an environment
     * variable name, which is not itself a secret — the same rule the app secret
     * follows.
     */
    public function youtube(int $siteId): array
    {
        $handle = YouTubeProvider::handle();
        $tokenService = SocialStream::$plugin->token;
        $connection = $tokenService->getConnection($siteId, $handle);
        $provider = SocialStream::$plugin->providers->getProviderByHandle($handle);
        $apiKey = $tokenService->decrypt($connection->apiKey);
        $keyIsEnvVar = $apiKey !== null && str_starts_with($apiKey, '$');

        return [
            'connection' => $connection,
            'apiKey' => $keyIsEnvVar ? $apiKey : null,
            'apiKeyIsEnvVar' => $keyIsEnvVar,
            'hasApiKey' => $this->isPresent($connection->apiKey),
            'channelRef' => $connection->channelRef,
            'channelId' => $connection->providerUserId,
            'isConnected' => $this->isPresent($connection->providerUserId),
            'websubExpiresAt' => $connection->websubExpiresAt,
            'websubActive' => $this->websubIsActive($connection),
            'websubPending' => WebSubSubscriber::isPending($siteId),
            'webhookLastReceivedAt' => $connection->webhookLastReceivedAt,
            'webhookCallbackUrl' => $provider instanceof YouTubeProvider
                ? $provider->websubCallbackUrl()
                : null,
            'isRateLimited' => $this->isRateLimited($siteId, $handle),
            'quotaUsed' => $provider instanceof YouTubeProvider ? $provider->quota()->used() : 0,
            'quotaLimit' => QuotaMeter::DAILY_LIMIT,
            'profile' => $this->cachedProfile($siteId, $handle),
        ];
    }

    /**
     * What to show in the table's Account column.
     *
     * The name only exists in a profile response, and a stream fetch doesn't make one
     * — so a site that never calls getProfile() would show nothing at all for a
     * perfectly healthy connection. The order is: a profile still in cache, then the
     * name remembered from the last profile fetch, then the identifier stored on the
     * connection, which is at least something to check against the provider.
     */
    private function account(int $siteId, string $handle, ?string $providerUserId): ?string
    {
        return $this->accountLabel($this->cachedProfile($siteId, $handle))
            ?? SocialStream::$plugin->streamCache->rememberedAccount($siteId, $handle)
            ?? ($providerUserId ?: null);
    }

    /**
     * A human label for the connected account, from whichever key the provider's
     * profile response happens to use.
     */
    public function accountLabel(?array $profile): ?string
    {
        return CacheService::accountLabel($profile);
    }

    /**
     * The provider's last profile response, if one is still cached. Never fetches.
     */
    private function cachedProfile(int $siteId, string $handle): ?array
    {
        $cached = SocialStream::$plugin->streamCache->getProfile($siteId, $handle);
        $profile = $cached['data']['data'] ?? null;

        return is_array($profile) ? $profile : null;
    }

    private function isRateLimited(int $siteId, string $provider): bool
    {
        return Craft::$app->cache->get('social-stream:rate-limited:' . $provider . ':' . $siteId) !== false;
    }

    private function websubIsActive(ConnectionRecord $connection): bool
    {
        if ($connection->websubExpiresAt === null) {
            return false;
        }

        $expiresAt = DateTimeHelper::toDateTime($connection->websubExpiresAt);

        return $expiresAt !== false && $expiresAt > new DateTime();
    }

    private function isPresent(?string $value): bool
    {
        return $value !== null && $value !== '';
    }
}
