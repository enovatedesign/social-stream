<?php

namespace enovate\socialstream\tests\unit;

use DateTimeZone;
use enovate\socialstream\helpers\DisplayDate;
use PHPUnit\Framework\TestCase;

/**
 * Tests how a stored timestamp is prepared for display.
 *
 * Connections record their diagnostics as UTC strings with no offset in them, so the
 * zone has to be put back before the control panel formats one. Nothing about a
 * wrong answer looks wrong — "Last Successful Fetch" simply reads an hour out
 * through British summer, and further out the further the site is from Greenwich.
 */
class DisplayDateTest extends TestCase
{
    public function testAStoredTimestampIsReadAsUtc(): void
    {
        $date = DisplayDate::fromStoredUtc('2026-10-01 14:30:00', 'UTC');

        self::assertNotNull($date);
        self::assertSame('2026-10-01 14:30:00', $date->format('Y-m-d H:i:s'));
    }

    /**
     * The point of the helper: the clock time moves to the zone the reader is in.
     */
    public function testTheTimeIsConvertedToTheDisplayTimeZone(): void
    {
        $date = DisplayDate::fromStoredUtc('2026-10-01 14:30:00', 'Europe/London');

        self::assertNotNull($date);
        self::assertSame('2026-10-01 15:30:00', $date->format('Y-m-d H:i:s'));
        self::assertSame('Europe/London', $date->getTimezone()->getName());
    }

    /**
     * A conversion that crosses midnight changes the date as well as the time, which
     * is the case a naive read gets most visibly wrong.
     */
    public function testAConversionMayCrossIntoTheNextDay(): void
    {
        $date = DisplayDate::fromStoredUtc('2026-10-01 23:15:00', 'Australia/Sydney');

        self::assertNotNull($date);
        self::assertSame('2026-10-02 09:15:00', $date->format('Y-m-d H:i:s'));
    }

    public function testAStoredOffsetIsHonouredRatherThanOverridden(): void
    {
        $date = DisplayDate::fromStoredUtc('2026-10-01T14:30:00+02:00', 'UTC');

        self::assertNotNull($date);
        self::assertSame('2026-10-01 12:30:00', $date->format('Y-m-d H:i:s'));
    }

    /**
     * The settings screen asks for every provider's dates at once, including the ones
     * that have never fetched.
     */
    public function testNothingStoredDisplaysNothing(): void
    {
        self::assertNull(DisplayDate::fromStoredUtc(null, 'Europe/London'));
        self::assertNull(DisplayDate::fromStoredUtc('', 'Europe/London'));
        self::assertNull(DisplayDate::fromStoredUtc('   ', 'Europe/London'));
    }

    /**
     * Neither a column written by an older release nor a mistyped timezone setting is
     * worth a 500 on the settings screen.
     */
    public function testUnreadableInputDisplaysNothing(): void
    {
        self::assertNull(DisplayDate::fromStoredUtc('not a date', 'Europe/London'));
        self::assertNull(DisplayDate::fromStoredUtc('2026-10-01 14:30:00', 'Nowhere/Fictional'));
    }

    /**
     * Zero dates are what MySQL leaves behind, and they are not a fetch that happened.
     */
    public function testAZeroTimestampDisplaysNothing(): void
    {
        self::assertNull(DisplayDate::fromStoredUtc('0000-00-00 00:00:00', 'Europe/London'));
    }

    /**
     * Degrading to no date must not be silent — the column is the only clue to why
     * the row reads empty.
     */
    public function testUnreadableInputIsLogged(): void
    {
        if (!property_exists('Craft', 'log')) {
            // The real Craft is in play, so there is no in-memory log to read back.
            self::markTestSkipped('Only the bootstrap stand-in for Craft records its log.');
        }

        \Craft::$log = [];

        DisplayDate::fromStoredUtc('not a date', 'Europe/London');

        self::assertCount(1, \Craft::$log);
        self::assertSame('warning', \Craft::$log[0]['level']);
        self::assertSame('social-stream', \Craft::$log[0]['category']);
        self::assertStringContainsString('not a date', \Craft::$log[0]['message']);
    }

    public function testTheStoredValueIsLeftUnchanged(): void
    {
        $stored = '2026-10-01 14:30:00';

        DisplayDate::fromStoredUtc($stored, 'Australia/Sydney');

        self::assertSame('2026-10-01 14:30:00', $stored);
    }

    public function testTheTimeZoneObjectFormIsAccepted(): void
    {
        $date = DisplayDate::fromStoredUtc('2026-10-01 14:30:00', new DateTimeZone('Europe/London'));

        self::assertNotNull($date);
        self::assertSame('2026-10-01 15:30:00', $date->format('Y-m-d H:i:s'));
    }
}
