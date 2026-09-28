<?php

namespace enovate\socialstream\tests\unit;

use enovate\socialstream\providers\youtube\VideoMapper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Tests ISO 8601 duration parsing and formatting.
 *
 * Durations are parsed with DateInterval rather than a regex because long
 * livestream replays carry a day component, and a malformed value has to degrade to
 * zero rather than break the whole fetch.
 */
class YouTubeDurationTest extends TestCase
{
    private function mapper(): VideoMapper
    {
        return new VideoMapper();
    }

    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public static function durations(): array
    {
        return [
            'zero' => ['PT0S', 0],
            'seconds only' => ['PT59S', 59],
            'a minute and a half' => ['PT1M30S', 90],
            'a Short over the old 60s cut-off' => ['PT1M4S', 64],
            'a whole hour' => ['PT1H', 3600],
            'hours, minutes and seconds' => ['PT2H15M3S', 8103],
            'a day-long livestream replay' => ['P1DT2H', 93600],
        ];
    }

    #[DataProvider('durations')]
    public function testParsesDurations(string $iso8601, int $expected): void
    {
        self::assertSame($expected, $this->mapper()->parseDuration($iso8601));
    }

    public function testAMalformedDurationBecomesZero(): void
    {
        self::assertSame(0, $this->mapper()->parseDuration('90 seconds'));
    }

    public function testAnEmptyDurationBecomesZero(): void
    {
        self::assertSame(0, $this->mapper()->parseDuration(''));
        self::assertSame(0, $this->mapper()->parseDuration(null));
    }

    /**
     * @return array<string, array{0: int, 1: string}>
     */
    public static function formats(): array
    {
        return [
            'zero' => [0, '0:00'],
            'under a minute pads the seconds' => [9, '0:09'],
            'a minute and a half' => [90, '1:30'],
            'exactly an hour' => [3600, '1:00:00'],
            'hours pad minutes and seconds' => [8103, '2:15:03'],
            'a negative value is clamped' => [-5, '0:00'],
        ];
    }

    #[DataProvider('formats')]
    public function testFormatsDurations(int $seconds, string $expected): void
    {
        self::assertSame($expected, $this->mapper()->formatDuration($seconds));
    }
}
