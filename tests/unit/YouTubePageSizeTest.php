<?php

namespace enovate\socialstream\tests\unit;

use enovate\socialstream\providers\YouTubeProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Tests how YouTube's API page size is derived from the caller's limit.
 *
 * `maxResults` caps at 50, so the page size is per request and not the whole job: a
 * limit above 50 has to be reached across pages. Treating it as the whole job — one
 * page of `min($limit, 50)` — is what made `limit: 100` return 50 posts and report
 * success, while the same limit on Instagram returned 100.
 */
class YouTubePageSizeTest extends TestCase
{
    private function resolvePageSize(int $limit, int $collected, bool $needsFiltering): int
    {
        $method = new ReflectionMethod(YouTubeProvider::class, 'resolvePageSize');

        return $method->invoke(new YouTubeProvider(), $limit, $collected, $needsFiltering);
    }

    public function testUnfilteredRequestAsksOnlyForWhatItStillNeeds(): void
    {
        // A bigger page is wasted transfer when nothing is discarded.
        self::assertSame(10, $this->resolvePageSize(10, 0, false));
    }

    /**
     * The regression this guards: a limit above the API's per-page ceiling.
     */
    public function testUnfilteredRequestAboveTheCeilingFillsAPage(): void
    {
        self::assertSame(50, $this->resolvePageSize(100, 0, false));
    }

    public function testASecondPageAsksOnlyForTheShortfall(): void
    {
        // 50 collected of 60 wanted: the last page is 10, not another 50.
        self::assertSame(10, $this->resolvePageSize(60, 50, false));
    }

    public function testASecondPageIsStillCappedAtTheCeiling(): void
    {
        self::assertSame(50, $this->resolvePageSize(200, 50, false));
    }

    public function testFilteredRequestOverFetchesRegardlessOfTheLimit(): void
    {
        // Most candidates are discarded, so a page sized to the limit would starve the
        // filter — the same reasoning as Instagram's, with no configurable page size.
        self::assertSame(50, $this->resolvePageSize(3, 0, true));
        self::assertSame(50, $this->resolvePageSize(3, 2, true));
    }

    /**
     * The loop only runs while the limit is unmet, so this cannot arise — but a page
     * size of 0 or less is rejected by the API outright, and a guard costs nothing.
     */
    public function testNeverAsksForAnEmptyPage(): void
    {
        self::assertSame(1, $this->resolvePageSize(10, 10, false));
        self::assertSame(1, $this->resolvePageSize(10, 25, false));
    }
}
