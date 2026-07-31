<?php

namespace enovate\socialstream\tests\unit;

use enovate\socialstream\providers\InstagramProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Tests how the API page size is derived from the caller's limit.
 *
 * The two are deliberately independent whenever a filter is active. `excludeNonFeed`
 * and `mediaType` are applied by the plugin, not by Instagram, so a page sized to the
 * limit hands the filter exactly as many candidates as the caller wants to keep — and
 * every rejection is then a shortfall. On an account where the filter rejects most
 * posts, a homepage asking for 3 got 1.
 */
class InstagramPageSizeTest extends TestCase
{
    private function resolvePageSize(int $limit, bool $needsFiltering, int $configuredPageSize): int
    {
        $method = new ReflectionMethod(InstagramProvider::class, 'resolvePageSize');

        return $method->invoke(new InstagramProvider(), $limit, $needsFiltering, $configuredPageSize);
    }

    public function testUnfilteredRequestUsesTheLimitAsThePageSize(): void
    {
        // Nothing is discarded, so a bigger page would only be wasted transfer.
        self::assertSame(3, $this->resolvePageSize(3, false, 25));
    }

    public function testFilteredRequestOverFetchesRatherThanSizingToTheLimit(): void
    {
        // The regression this guards: a page of 3 gave the filter 3 candidates.
        self::assertSame(25, $this->resolvePageSize(3, true, 25));
    }

    public function testFilteredRequestNeverPagesSmallerThanTheLimit(): void
    {
        // A page below the limit would burn the page budget before meeting it.
        self::assertSame(50, $this->resolvePageSize(50, true, 25));
    }

    public function testConfiguredPageSizeIsClampedToInstagramsMaximum(): void
    {
        self::assertSame(100, $this->resolvePageSize(3, true, 500));
    }

    public function testNonPositiveConfiguredPageSizeFallsBackToAValidRequest(): void
    {
        // 0 is what a mistyped or empty config value casts to; sending limit=0
        // would fail the request outright.
        self::assertSame(1, $this->resolvePageSize(1, true, 0));
    }
}
