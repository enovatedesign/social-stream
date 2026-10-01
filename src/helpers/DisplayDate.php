<?php

namespace enovate\socialstream\helpers;

use DateTimeImmutable;
use DateTimeZone;
use enovate\socialstream\SocialStream;
use Throwable;

/**
 * Turns a stored timestamp into a date the control panel can format honestly.
 *
 * Connection diagnostics are written as UTC strings carrying no offset
 * (`DateTimeHelper::currentUTCDateTime()->format('Y-m-d H:i:s')`), so handing one
 * straight to Twig's `|date` filter reads it in PHP's default timezone instead —
 * reporting a fetch an hour early through British summer, and further out the
 * further the site sits from Greenwich.
 *
 * Putting UTC back on, then converting to the timezone the control panel displays
 * in, is what makes the rendered time the one the reader expects.
 */
final class DisplayDate
{
    /**
     * @param string|null $storedUtc A stored timestamp, UTC unless it says otherwise
     * @param DateTimeZone|string $displayTimeZone The timezone to show it in
     * @return DateTimeImmutable|null The date to format, or null if there isn't one
     */
    public static function fromStoredUtc(
        ?string $storedUtc,
        DateTimeZone|string $displayTimeZone,
    ): ?DateTimeImmutable {
        if ($storedUtc === null || trim($storedUtc) === '' || str_starts_with($storedUtc, '0000-00-00')) {
            return null;
        }

        try {
            return (new DateTimeImmutable($storedUtc, new DateTimeZone('UTC')))
                ->setTimezone(is_string($displayTimeZone) ? new DateTimeZone($displayTimeZone) : $displayTimeZone);
        } catch (Throwable $e) {
            // A value from a column an older release wrote, or a timezone the site has
            // mis-set, is not worth taking the settings screen down for: the row shows
            // no date, and the reason is in the log.
            SocialStream::warning(
                'Could not read the stored date "' . $storedUtc . '": ' . $e->getMessage()
            );

            return null;
        }
    }
}
