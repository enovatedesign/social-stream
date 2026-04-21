<?php

namespace enovate\socialstream\variables;

use Craft;
use enovate\socialstream\jobs\RefreshStreamJob;
use enovate\socialstream\SocialStream;

class SocialStreamVariable
{
    /**
     * Fetch social stream posts — served from cache when possible.
     *
     * Cache behaviour:
     * - HIT (within TTL): returns cached data immediately.
     * - STALE (past TTL, within stale window): returns cached data, queues background refresh.
     * - COLD MISS: acquires mutex, fetches synchronously, stores in cache.
     *
     * @param array $options {
     *     @type string      $provider       Provider handle (required, e.g. 'instagram')
     *     @type int         $limit          Number of posts to return (default: CP setting)
     *     @type string|null $mediaType      Filter: IMAGE, VIDEO, CAROUSEL_ALBUM, or null for all
     *     @type bool        $excludeNonFeed Exclude posts where is_shared_to_feed is false
     *     @type int         $siteId         Which site's connection to use (default: current site)
     *     @type string|null $after          Pagination cursor from a previous response's nextCursor
     * }
     * @return array{success: bool, data: array, nextCursor: string|null, error: string|null, cached: bool}
     */
    public function getStream(array $options = []): array
    {
        if (!isset($options['siteId'])) {
            $options['siteId'] = Craft::$app->sites->currentSite->id;
        }

        if (empty($options['provider'])) {
            return [
                'success' => false,
                'data' => [],
                'nextCursor' => null,
                'error' => "social-stream: the 'provider' option is required (e.g. 'instagram').",
                'cached' => false,
            ];
        }

        try {
            $cache = SocialStream::$plugin->streamCache;
            $cacheResult = $cache->getStream($options);

            if ($cacheResult['status'] === 'hit') {
                return $cache->deserializeStreamResponse($cacheResult['data']);
            }

            if ($cacheResult['status'] === 'stale') {
                $this->_queueStreamRefresh($options);
                return $cache->deserializeStreamResponse($cacheResult['data']);
            }

            $cacheKey = $cache->streamKey($options);
            $lockAcquired = $cache->acquireLock($cacheKey);

            try {
                if (!$lockAcquired) {
                    $retryResult = $cache->getStream($options);
                    if ($retryResult['data'] !== null) {
                        return $cache->deserializeStreamResponse($retryResult['data']);
                    }
                }

                $provider = SocialStream::$plugin->providers->requireProviderByHandle($options['provider']);
                $response = $provider->fetchStream($options);

                if ($response['success']) {
                    $cache->setStream($options, $response, $options['siteId']);
                }

                return $response;
            } finally {
                if ($lockAcquired) {
                    $cache->releaseLock($cacheKey);
                }
            }
        } catch (\Throwable $e) {
            SocialStream::error('Twig getStream() error: ' . $e->getMessage());

            return [
                'success' => false,
                'data' => [],
                'nextCursor' => null,
                'error' => $e->getMessage(),
                'cached' => false,
            ];
        }
    }

    /**
     * Fetch profile information for the site's connected account — served from cache when possible.
     *
     * @param array $options {
     *     @type string $provider Provider handle (required, e.g. 'instagram')
     *     @type int    $siteId   Which site's connection to use (default: current site)
     * }
     * @return array{success: bool, data: array|null, error: string|null}
     */
    public function getProfile(array $options = []): array
    {
        $siteId = $options['siteId'] ?? Craft::$app->sites->currentSite->id;
        $providerHandle = $options['provider'] ?? null;

        if (empty($providerHandle)) {
            return [
                'success' => false,
                'data' => null,
                'error' => "social-stream: the 'provider' option is required (e.g. 'instagram').",
            ];
        }

        try {
            $cache = SocialStream::$plugin->streamCache;
            $cacheResult = $cache->getProfile($siteId, $providerHandle);

            if ($cacheResult['data'] !== null) {
                return $cacheResult['data'];
            }

            $cacheKey = $cache->profileKey($siteId, $providerHandle);
            $lockAcquired = $cache->acquireLock($cacheKey);

            try {
                if (!$lockAcquired) {
                    $retryResult = $cache->getProfile($siteId, $providerHandle);
                    if ($retryResult['data'] !== null) {
                        return $retryResult['data'];
                    }
                }

                $provider = SocialStream::$plugin->providers->requireProviderByHandle($providerHandle);
                $response = $provider->fetchProfile($siteId);

                if ($response['success']) {
                    $cache->setProfile($siteId, $response, $providerHandle);
                }

                return $response;
            } finally {
                if ($lockAcquired) {
                    $cache->releaseLock($cacheKey);
                }
            }
        } catch (\Throwable $e) {
            SocialStream::error('Twig getProfile() error: ' . $e->getMessage());

            return [
                'success' => false,
                'data' => null,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Queue a background stream refresh job (deduplicated).
     */
    private function _queueStreamRefresh(array $options): void
    {
        try {
            RefreshStreamJob::pushIfNotQueued(
                $options['siteId'] ?? Craft::$app->sites->currentSite->id,
                $options,
                $options['provider'],
            );
        } catch (\Throwable $e) {
            SocialStream::warning('Failed to queue stream refresh: ' . $e->getMessage());
        }
    }
}
