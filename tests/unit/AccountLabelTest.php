<?php

namespace enovate\socialstream\tests\unit;

use enovate\socialstream\services\CacheService;
use PHPUnit\Framework\TestCase;

/**
 * Tests how a connected account is named in the control panel.
 *
 * Providers disagree on the key — Instagram returns `username`, YouTube `title` —
 * and getting this wrong is not loud: the CP falls back to a raw provider
 * identifier, so a healthy connection reads as `UCtljUyou0OSIYBc0ajeSgDg` rather
 * than the channel's name.
 */
class AccountLabelTest extends TestCase
{
    public function testReadsInstagramsUsername(): void
    {
        self::assertSame(
            'foxesfarmfields',
            CacheService::accountLabel(['username' => 'foxesfarmfields', 'account_type' => 'BUSINESS'])
        );
    }

    public function testReadsAYouTubeChannelTitle(): void
    {
        self::assertSame(
            'Foxes Farm Fields',
            CacheService::accountLabel(['id' => 'UCtljUyou0OSIYBc0ajeSgDg', 'title' => 'Foxes Farm Fields'])
        );
    }

    public function testFallsBackToAGenericName(): void
    {
        self::assertSame('Some Account', CacheService::accountLabel(['name' => 'Some Account']));
    }

    public function testPrefersUsernameOverTheOtherKeys(): void
    {
        self::assertSame(
            'first',
            CacheService::accountLabel(['name' => 'third', 'title' => 'second', 'username' => 'first'])
        );
    }

    public function testAProfileWithNoRecognisedKeyHasNoLabel(): void
    {
        self::assertNull(CacheService::accountLabel(['id' => '123', 'followers_count' => 4]));
    }

    public function testAnEmptyValueIsNotALabel(): void
    {
        self::assertNull(CacheService::accountLabel(['username' => '']));
        self::assertSame(
            'fallback',
            CacheService::accountLabel(['username' => '', 'title' => 'fallback']),
            'An empty value must not stop the chain before a usable one.'
        );
    }

    public function testANonStringValueIsIgnored(): void
    {
        self::assertNull(CacheService::accountLabel(['title' => ['nested' => 'no']]));
    }

    public function testNoProfileHasNoLabel(): void
    {
        self::assertNull(CacheService::accountLabel(null));
        self::assertNull(CacheService::accountLabel([]));
    }
}
