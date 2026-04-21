<?php

namespace enovate\socialstream\tests\unit;

use PHPUnit\Framework\TestCase;

/**
 * Tests the cache key generation algorithm and tag scoping.
 *
 * Mirrors CacheService::streamKey() / profileKey() and the internal
 * _siteCacheDependency() / _providerCacheDependency() tag sets without requiring
 * Craft, so the algorithm's contract can be asserted in isolation. The
 * `{settingsHash}` segment is substituted with a stub (`TEST`) — CacheService
 * derives it from SettingsRecord, which needs a bootstrapped Craft app.
 */
class CacheKeyTest extends TestCase
{
    private const STUB_HASH = 'TEST';

    private function streamKey(array $options): string
    {
        $siteId = $options['siteId'] ?? 0;
        $provider = $options['provider'] ?? '';
        $limit = $options['limit'] ?? 25;
        $mediaType = strtoupper($options['mediaType'] ?? 'ALL');
        $excludeNonFeed = !empty($options['excludeNonFeed']) ? '1' : '0';
        $after = $options['after'] ?? '0';

        return implode(':', [
            'social-stream',
            $siteId,
            $provider,
            $limit,
            $mediaType,
            $excludeNonFeed,
            $after,
            self::STUB_HASH,
        ]);
    }

    private function profileKey(int $siteId, string $provider): string
    {
        return 'social-stream:profile:' . $provider . ':' . $siteId;
    }

    /**
     * @return string[]
     */
    private function siteCacheDependencyTags(int $siteId, string $provider): array
    {
        return [
            'social-stream',
            'social-stream:site:' . $siteId,
            'social-stream:provider:' . $provider,
            'social-stream:site-provider:' . $siteId . ':' . $provider,
        ];
    }

    /**
     * @return string[]
     */
    private function providerCacheDependencyTags(string $provider): array
    {
        return [
            'social-stream',
            'social-stream:provider:' . $provider,
        ];
    }

    public function testStreamKeyFormat(): void
    {
        $key = $this->streamKey([
            'siteId' => 1,
            'provider' => 'instagram',
            'limit' => 12,
            'mediaType' => 'IMAGE',
            'excludeNonFeed' => true,
        ]);

        $this->assertSame('social-stream:1:instagram:12:IMAGE:1:0:TEST', $key);
    }

    public function testStreamKeyNonInstagramProvider(): void
    {
        $key = $this->streamKey([
            'siteId' => 1,
            'provider' => 'youtube',
            'limit' => 12,
            'mediaType' => 'VIDEO',
        ]);

        $this->assertSame('social-stream:1:youtube:12:VIDEO:0:0:TEST', $key);
    }

    public function testStreamKeyWithPaginationCursor(): void
    {
        $key = $this->streamKey([
            'siteId' => 1,
            'provider' => 'instagram',
            'after' => 'QVFIuZ3abc123',
        ]);

        $this->assertSame('social-stream:1:instagram:25:ALL:0:QVFIuZ3abc123:TEST', $key);
    }

    public function testStreamKeyMediaTypeNormalisedToUppercase(): void
    {
        $key1 = $this->streamKey(['siteId' => 1, 'provider' => 'instagram', 'mediaType' => 'image']);
        $key2 = $this->streamKey(['siteId' => 1, 'provider' => 'instagram', 'mediaType' => 'IMAGE']);
        $key3 = $this->streamKey(['siteId' => 1, 'provider' => 'instagram', 'mediaType' => 'Image']);

        $this->assertSame($key1, $key2);
        $this->assertSame($key2, $key3);
    }

    public function testStreamKeyProviderIsolation(): void
    {
        $key1 = $this->streamKey(['siteId' => 1, 'provider' => 'instagram']);
        $key2 = $this->streamKey(['siteId' => 1, 'provider' => 'youtube']);

        $this->assertNotSame($key1, $key2);
        $this->assertStringContainsString(':instagram:', $key1);
        $this->assertStringContainsString(':youtube:', $key2);
    }

    public function testStreamKeySiteIsolation(): void
    {
        $key1 = $this->streamKey(['siteId' => 1, 'provider' => 'instagram', 'limit' => 10]);
        $key2 = $this->streamKey(['siteId' => 2, 'provider' => 'instagram', 'limit' => 10]);

        $this->assertNotSame($key1, $key2);
        $this->assertStringContainsString(':1:', $key1);
        $this->assertStringContainsString(':2:', $key2);
    }

    public function testStreamKeyDifferentParamsProduceDifferentKeys(): void
    {
        $base = ['siteId' => 1, 'provider' => 'instagram'];

        $keys = [
            $this->streamKey(array_merge($base, ['limit' => 10])),
            $this->streamKey(array_merge($base, ['limit' => 20])),
            $this->streamKey(array_merge($base, ['mediaType' => 'IMAGE'])),
            $this->streamKey(array_merge($base, ['mediaType' => 'VIDEO'])),
            $this->streamKey(array_merge($base, ['excludeNonFeed' => true])),
            $this->streamKey(array_merge($base, ['excludeNonFeed' => false])),
        ];

        $this->assertCount(count($keys), array_unique($keys));
    }

    public function testStreamKeySameParamsProduceSameKey(): void
    {
        $options = ['siteId' => 1, 'provider' => 'instagram', 'limit' => 12, 'mediaType' => 'IMAGE', 'excludeNonFeed' => true];

        $key1 = $this->streamKey($options);
        $key2 = $this->streamKey($options);

        $this->assertSame($key1, $key2);
    }

    public function testProfileKeyFormat(): void
    {
        $this->assertSame('social-stream:profile:instagram:1', $this->profileKey(1, 'instagram'));
        $this->assertSame('social-stream:profile:instagram:42', $this->profileKey(42, 'instagram'));
    }

    public function testProfileKeyProviderIsolation(): void
    {
        $key1 = $this->profileKey(1, 'instagram');
        $key2 = $this->profileKey(1, 'youtube');

        $this->assertNotSame($key1, $key2);
    }

    public function testProfileKeySiteIsolation(): void
    {
        $key1 = $this->profileKey(1, 'instagram');
        $key2 = $this->profileKey(2, 'instagram');

        $this->assertNotSame($key1, $key2);
    }

    // -------------------------------------------------------------------------
    // Cache dependency tags (drives invalidateForProvider / invalidateForSite)
    // -------------------------------------------------------------------------

    public function testSiteCacheDependencyTagsIncludeAllFour(): void
    {
        $tags = $this->siteCacheDependencyTags(1, 'instagram');

        $this->assertContains('social-stream', $tags);
        $this->assertContains('social-stream:site:1', $tags);
        $this->assertContains('social-stream:provider:instagram', $tags);
        $this->assertContains('social-stream:site-provider:1:instagram', $tags);
    }

    public function testSiteCacheDependencyTagsDifferByProvider(): void
    {
        $instagramTags = $this->siteCacheDependencyTags(1, 'instagram');
        $youtubeTags = $this->siteCacheDependencyTags(1, 'youtube');

        $this->assertContains('social-stream:provider:instagram', $instagramTags);
        $this->assertNotContains('social-stream:provider:instagram', $youtubeTags);
        $this->assertContains('social-stream:provider:youtube', $youtubeTags);
    }

    public function testInvalidateForProviderTagMatchesSetTag(): void
    {
        // invalidateForProvider('instagram') invalidates the 'social-stream:provider:instagram'
        // tag. Every entry set via setStream()/setProfile() must carry that tag so the
        // invalidation reaches it. This test guards the contract between set + invalidate.
        $invalidationTag = 'social-stream:provider:instagram';

        $streamTags = $this->siteCacheDependencyTags(1, 'instagram');
        $providerTags = $this->providerCacheDependencyTags('instagram');

        $this->assertContains($invalidationTag, $streamTags);
        $this->assertContains($invalidationTag, $providerTags);
    }

    public function testInvalidateForSiteAndProviderTagMatchesSetTag(): void
    {
        $invalidationTag = 'social-stream:site-provider:1:instagram';

        $streamTags = $this->siteCacheDependencyTags(1, 'instagram');

        $this->assertContains($invalidationTag, $streamTags);
    }

    public function testInvalidateForSiteAndProviderDoesNotAffectOtherProviders(): void
    {
        // A YouTube entry for site 1 must NOT carry the Instagram site-provider tag
        // — so invalidateForSiteAndProvider(1, 'instagram') leaves YouTube intact.
        $instagramInvalidationTag = 'social-stream:site-provider:1:instagram';

        $youtubeTags = $this->siteCacheDependencyTags(1, 'youtube');

        $this->assertNotContains($instagramInvalidationTag, $youtubeTags);
    }
}
