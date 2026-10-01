<?php

namespace enovate\socialstream\services;

use Craft;
use craft\base\Component;
use craft\helpers\Json;
use enovate\socialstream\base\Provider;
use enovate\socialstream\base\ProviderInterface;
use enovate\socialstream\models\Post;
use enovate\socialstream\records\SettingsRecord;
use enovate\socialstream\SocialStream;

/**
 * Wraps Craft's cache with stream-specific keys, tag-based invalidation,
 * stale-while-revalidate, and stampede protection.
 *
 * Cache entries store a wrapper array:
 *   ['data' => ..., 'freshUntil' => timestamp, 'staleUntil' => timestamp]
 *
 * - Within freshUntil: return immediately (HIT).
 * - Between freshUntil and staleUntil: return stale data, push a queue job (STALE).
 * - Past staleUntil or missing: synchronous fetch with mutex lock (COLD MISS).
 */
class CacheService extends Component
{
    /**
     * Stale window multiplier — stale data lives for this many times the TTL
     * beyond the fresh window, giving background refresh time to complete.
     */
    private const STALE_MULTIPLIER = 2;

    /**
     * How long (in seconds) to wait for a mutex lock before giving up.
     */
    private const MUTEX_TIMEOUT = 5;

    /**
     * How long to remember the connected account's name for the CP (30 days).
     */
    private const REMEMBERED_ACCOUNT_TTL = 2592000;

    // -------------------------------------------------------------------------
    // Stream cache
    // -------------------------------------------------------------------------

    /**
     * Get a cached stream response, or null on cold miss.
     *
     * @return array{data: array|null, status: 'hit'|'stale'|'miss'}
     */
    public function getStream(array $options): array
    {
        $key = $this->streamKey($options);
        $entry = Craft::$app->cache->get($key);

        if ($entry === false) {
            return ['data' => null, 'status' => 'miss'];
        }

        $now = time();

        if ($now <= $entry['freshUntil']) {
            $response = $entry['data'];
            $response['cached'] = true;
            return ['data' => $response, 'status' => 'hit'];
        }

        if ($now <= $entry['staleUntil']) {
            $response = $entry['data'];
            $response['cached'] = true;
            return ['data' => $response, 'status' => 'stale'];
        }

        return ['data' => null, 'status' => 'miss'];
    }

    /**
     * Store a stream response in the cache.
     */
    public function setStream(array $options, array $response, ?int $siteId = null): void
    {
        $key = $this->streamKey($options);
        $ttl = $this->_getTtlSeconds($siteId ?? ($options['siteId'] ?? null));
        $staleTtl = $ttl * self::STALE_MULTIPLIER;

        $entry = [
            'data' => $this->_serializeStreamResponse($response),
            'freshUntil' => time() + $ttl,
            'staleUntil' => time() + $ttl + $staleTtl,
        ];

        $provider = $options['provider'];
        $dependency = $siteId !== null
            ? $this->_siteCacheDependency($siteId, $provider)
            : $this->_providerCacheDependency($provider);

        Craft::$app->cache->set($key, $entry, $ttl + $staleTtl, $dependency);
    }

    // -------------------------------------------------------------------------
    // Profile cache
    // -------------------------------------------------------------------------

    /**
     * @return array{data: array|null, status: 'hit'|'stale'|'miss'}
     */
    public function getProfile(int $siteId, string $provider): array
    {
        $key = $this->profileKey($siteId, $provider);
        $entry = Craft::$app->cache->get($key);

        if ($entry === false) {
            return ['data' => null, 'status' => 'miss'];
        }

        $now = time();

        if ($now <= $entry['freshUntil']) {
            return ['data' => $entry['data'], 'status' => 'hit'];
        }

        if ($now <= $entry['staleUntil']) {
            return ['data' => $entry['data'], 'status' => 'stale'];
        }

        return ['data' => null, 'status' => 'miss'];
    }

    public function setProfile(int $siteId, array $response, string $provider): void
    {
        $key = $this->profileKey($siteId, $provider);
        $ttl = $this->_getTtlSeconds($siteId);
        $staleTtl = $ttl * self::STALE_MULTIPLIER;

        $entry = [
            'data' => $response,
            'freshUntil' => time() + $ttl,
            'staleUntil' => time() + $ttl + $staleTtl,
        ];

        Craft::$app->cache->set($key, $entry, $ttl + $staleTtl, $this->_siteCacheDependency($siteId, $provider));
        $this->rememberAccount($siteId, $provider, $response['data'] ?? null);
    }

    /**
     * Remember which account a profile response described, well beyond the profile
     * cache's own TTL.
     *
     * The CP names the connected account, and the name only ever arrives in a profile
     * response. Tying that to the stream TTL would blank it out an hour after the last
     * profile call on a connection that is working perfectly.
     *
     * Called from {@see \enovate\socialstream\base\Provider::fetchProfile()} as well
     * as from here, so a caller that goes straight to the provider and never caches the
     * response still leaves the name behind.
     */
    public function rememberAccount(int $siteId, string $provider, mixed $profile): void
    {
        $label = self::accountLabel(is_array($profile) ? $profile : null);

        if ($label === null) {
            return;
        }

        Craft::$app->cache->set(
            $this->accountKey($siteId, $provider),
            $label,
            self::REMEMBERED_ACCOUNT_TTL,
            $this->_siteCacheDependency($siteId, $provider),
        );
    }

    /**
     * The account name last seen for this connection, if it is still remembered.
     */
    public function rememberedAccount(int $siteId, string $provider): ?string
    {
        $label = Craft::$app->cache->get($this->accountKey($siteId, $provider));

        return is_string($label) && $label !== '' ? $label : null;
    }

    /**
     * Pull a display name out of a profile response.
     *
     * Providers disagree on the key — Instagram says `username`, YouTube says
     * `customUrl` — and a provider from another plugin may use neither, in which case
     * there is simply no name to show.
     *
     * Handles come before titles: the label stands in the CP's "Account" column, and
     * `@foxesfarmfields` identifies an account in a way a display name does not.
     */
    public static function accountLabel(?array $profile): ?string
    {
        foreach (['username', 'customUrl', 'title', 'name'] as $key) {
            $value = $profile[$key] ?? null;

            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    private function accountKey(int $siteId, string $provider): string
    {
        return 'social-stream:account:' . $provider . ':' . $siteId;
    }

    // -------------------------------------------------------------------------
    // Stampede protection
    // -------------------------------------------------------------------------

    public function acquireLock(string $cacheKey): bool
    {
        $lockKey = 'social-stream-lock:' . md5($cacheKey);
        return Craft::$app->mutex->acquire($lockKey, self::MUTEX_TIMEOUT);
    }

    public function releaseLock(string $cacheKey): void
    {
        $lockKey = 'social-stream-lock:' . md5($cacheKey);
        Craft::$app->mutex->release($lockKey);
    }

    // -------------------------------------------------------------------------
    // Invalidation
    // -------------------------------------------------------------------------

    public function invalidateAll(): void
    {
        \yii\caching\TagDependency::invalidate(Craft::$app->cache, 'social-stream');
        SocialStream::info('All Social Stream cache entries invalidated.');
    }

    public function invalidateForSite(int $siteId): void
    {
        \yii\caching\TagDependency::invalidate(Craft::$app->cache, 'social-stream:site:' . $siteId);
        SocialStream::info('Social Stream cache invalidated for site ' . $siteId);
    }

    public function invalidateForProvider(string $provider): void
    {
        \yii\caching\TagDependency::invalidate(Craft::$app->cache, 'social-stream:provider:' . $provider);
        SocialStream::info('Social Stream cache invalidated for provider ' . $provider);
    }

    public function invalidateForSiteAndProvider(int $siteId, string $provider): void
    {
        \yii\caching\TagDependency::invalidate(
            Craft::$app->cache,
            'social-stream:site-provider:' . $siteId . ':' . $provider,
        );
        SocialStream::info("Social Stream cache invalidated for site {$siteId} / provider {$provider}");
    }

    // -------------------------------------------------------------------------
    // Key generation
    // -------------------------------------------------------------------------

    /**
     * Generate a normalised cache key for a stream request.
     *
     * Includes a hash of the effective plugin settings (defaultLimit, excludeNonFeed)
     * so changing those in the CP naturally invalidates all existing entries without
     * needing an explicit cache clear.
     *
     * An option the provider ignores is normalised away rather than keyed on — both
     * in the segment and in the settings hash. Keying on it would split one stream
     * across two identical entries, doubling the API calls that fill them.
     *
     * Format: social-stream:{siteId}:{provider}:{limit}:{mediaType}:{excludeNonFeed}:{page}:{settingsHash}
     */
    public function streamKey(array $options): string
    {
        $siteId = $options['siteId'] ?? 0;
        $provider = $options['provider'] ?? '';
        $limit = $options['limit'] ?? 25;
        $mediaType = strtoupper($options['mediaType'] ?? 'ALL');
        $usesExcludeNonFeed = $this->_providerUsesExcludeNonFeed((string) $provider);
        $excludeNonFeed = ($usesExcludeNonFeed && !empty($options['excludeNonFeed'])) ? '1' : '0';
        $after = $options['after'] ?? '0';
        $settingsHash = $this->_settingsHash(
            is_int($siteId) ? $siteId : (int) $siteId,
            $usesExcludeNonFeed,
        );

        return implode(':', [
            'social-stream',
            $siteId,
            $provider,
            $limit,
            $mediaType,
            $excludeNonFeed,
            $after,
            $settingsHash,
        ]);
    }

    public function profileKey(int $siteId, string $provider): string
    {
        return 'social-stream:profile:' . $provider . ':' . $siteId;
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    /**
     * Get the cache TTL in seconds for a site.
     */
    private function _getTtlSeconds(?int $siteId): int
    {
        $minutes = 60;

        if ($siteId !== null) {
            $record = SettingsRecord::findOne(['siteId' => $siteId]);
            if ($record && $record->cacheDuration) {
                $minutes = (int) $record->cacheDuration;
            }
        }

        $config = Craft::$app->config->getConfigFromFile('social-stream');
        if (isset($config['cacheDuration'])) {
            $minutes = (int) $config['cacheDuration'];
        }

        return $minutes * 60;
    }

    /**
     * Short hash of the settings that affect stream shape, used in cache keys so
     * edits to defaultLimit / excludeNonFeed in the CP invalidate existing entries.
     *
     * @param bool $includeExcludeNonFeed Whether the requesting provider acts on the
     *                                    setting. When it doesn't, folding it in
     *                                    would make an unrelated CP edit invalidate
     *                                    that provider's cache for no reason.
     */
    private function _settingsHash(int $siteId, bool $includeExcludeNonFeed = true): string
    {
        $record = $siteId > 0 ? SettingsRecord::findOne(['siteId' => $siteId]) : null;
        $pluginSettings = SocialStream::$plugin->getSettings();

        $values = [
            'defaultLimit' => $record->defaultLimit
                ?? $pluginSettings->defaultLimit
                ?? 25,
        ];

        if ($includeExcludeNonFeed) {
            $values['excludeNonFeed'] = $record !== null
                ? (bool) $record->excludeNonFeed
                : (bool) ($pluginSettings->excludeNonFeed ?? false);
        }

        return substr(md5(Json::encode($values)), 0, 8);
    }

    /**
     * Whether the provider behind this request acts on `excludeNonFeed`.
     *
     * An unregistered or unknown handle is assumed to use it: that is the
     * pre-existing key shape, and guessing otherwise would silently change the keys
     * of a third-party provider.
     *
     * Only a missing provider is guessed at now. This used to also guess for anything
     * that wasn't a {@see Provider} subclass, because the method was declared there
     * rather than on the contract — it is on {@see ProviderInterface} now, so every
     * registered provider answers for itself.
     */
    private function _providerUsesExcludeNonFeed(string $provider): bool
    {
        if ($provider === '') {
            return true;
        }

        $instance = SocialStream::$plugin->providers->getProviderByHandle($provider);

        return $instance === null || $instance::usesExcludeNonFeed();
    }

    private function _cacheDependency(): \yii\caching\TagDependency
    {
        return new \yii\caching\TagDependency([
            'tags' => ['social-stream'],
        ]);
    }

    private function _providerCacheDependency(string $provider): \yii\caching\TagDependency
    {
        return new \yii\caching\TagDependency([
            'tags' => [
                'social-stream',
                'social-stream:provider:' . $provider,
            ],
        ]);
    }

    private function _siteCacheDependency(int $siteId, string $provider): \yii\caching\TagDependency
    {
        return new \yii\caching\TagDependency([
            'tags' => [
                'social-stream',
                'social-stream:site:' . $siteId,
                'social-stream:provider:' . $provider,
                'social-stream:site-provider:' . $siteId . ':' . $provider,
            ],
        ]);
    }

    /**
     * Convert Post objects in the response's data array to their plain-array
     * representation for cache storage. Children nest through Post::toArray().
     */
    private function _serializeStreamResponse(array $response): array
    {
        if (isset($response['data']) && is_array($response['data'])) {
            $response['data'] = array_map(
                fn($post) => $post instanceof Post ? $post->toArray() : $post,
                $response['data'],
            );
        }

        return $response;
    }

    /**
     * Reconstruct Post objects from cached plain-array data.
     */
    public function deserializeStreamResponse(array $cachedResponse): array
    {
        if (isset($cachedResponse['data']) && is_array($cachedResponse['data'])) {
            $cachedResponse['data'] = array_map(
                fn($item) => is_array($item) ? Post::fromArray($item) : $item,
                $cachedResponse['data'],
            );
        }

        return $cachedResponse;
    }
}
