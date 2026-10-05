<?php

namespace enovate\socialstream\tests\unit;

use DateTime;
use DateTimeZone;
use enovate\socialstream\providers\youtube\QuotaMeter;
use PHPUnit\Framework\TestCase;

/**
 * Tests the quota reset window.
 *
 * A daily quota is what the cooldown length hangs off: retrying an exhausted quota
 * before it resets fails identically every time, so the provider suppresses calls
 * until midnight Pacific Time — not for the fifteen minutes a burst limit gets.
 */
class YouTubeQuotaResetTest extends TestCase
{
    public function testTheResetIsWithinTheNextDay(): void
    {
        $seconds = (new QuotaMeter())->secondsUntilReset();

        self::assertGreaterThan(0, $seconds);
        self::assertLessThanOrEqual(86400, $seconds);
    }

    public function testTheResetLandsOnMidnightPacific(): void
    {
        $pacific = new DateTimeZone('America/Los_Angeles');
        $now = new DateTime('now', $pacific);

        $reset = (clone $now)->modify('+' . (new QuotaMeter())->secondsUntilReset() . ' seconds');
        $reset->setTimezone($pacific);

        self::assertSame(
            '00:00',
            $reset->format('H:i'),
            'The quota resets on Pacific midnight regardless of the server timezone.'
        );
    }

    public function testTheCooldownIsNeverInstant(): void
    {
        self::assertGreaterThanOrEqual(
            60,
            (new QuotaMeter())->secondsUntilReset(),
            'A cooldown measured seconds before midnight must still be long enough to hold.'
        );
    }
}
