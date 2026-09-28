<?php

namespace enovate\socialstream\tests\unit;

use enovate\socialstream\providers\youtube\ShortsResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Tests the two signals that decide whether a video is a Short.
 *
 * The Data API has no field for it, so both signals are inferences from
 * youtube.com's behaviour. What matters most is the third state: neither signal may
 * guess. An unresolved video is treated as a regular video for one fetch and retried,
 * whereas a wrong answer is cached forever.
 */
class YouTubeShortsSignalTest extends TestCase
{
    public function testPortraitOembedDimensionsMeanShort(): void
    {
        self::assertTrue(ShortsResolver::classifyOembed(113, 200));
    }

    public function testLandscapeOembedDimensionsMeanRegularVideo(): void
    {
        self::assertFalse(ShortsResolver::classifyOembed(200, 113));
    }

    /**
     * @return array<string, array{0: int, 1: int}>
     */
    public static function unusableDimensions(): array
    {
        return [
            'both missing' => [0, 0],
            'no height' => [200, 0],
            'no width' => [0, 200],
            'negative' => [-1, -1],
        ];
    }

    /**
     * oEmbed answers with an error document for a private video or one with
     * embedding disabled. Reading that as landscape would file it as a regular video
     * permanently.
     */
    #[DataProvider('unusableDimensions')]
    public function testMissingOembedDimensionsResolveNothing(int $width, int $height): void
    {
        self::assertNull(ShortsResolver::classifyOembed($width, $height));
    }

    public function testASquareVideoIsNotAShort(): void
    {
        self::assertFalse(
            ShortsResolver::classifyOembed(200, 200),
            'Shorts are taller than they are wide; equal sides are not portrait.'
        );
    }

    public function testTheShortsPageAnsweringDirectlyMeansShort(): void
    {
        self::assertTrue(ShortsResolver::classifyShortsPage(200, ''));
    }

    public function testARedirectToWatchMeansRegularVideo(): void
    {
        self::assertFalse(
            ShortsResolver::classifyShortsPage(303, 'https://www.youtube.com/watch?v=dQw4w9WgXcQ')
        );
    }

    public function testARedirectSomewhereElseResolvesNothing(): void
    {
        self::assertNull(
            ShortsResolver::classifyShortsPage(302, 'https://consent.youtube.com/m?continue=...'),
            'A consent interstitial says nothing about the video.'
        );
    }

    /**
     * @return array<string, array{0: int}>
     */
    public static function unhelpfulStatuses(): array
    {
        return [
            'not found' => [404],
            'forbidden' => [403],
            'server error' => [500],
            'no content' => [204],
        ];
    }

    #[DataProvider('unhelpfulStatuses')]
    public function testANonRedirectFailureResolvesNothing(int $statusCode): void
    {
        self::assertNull(ShortsResolver::classifyShortsPage($statusCode, ''));
    }
}
