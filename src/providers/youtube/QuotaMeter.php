<?php

namespace enovate\socialstream\providers\youtube;

use Craft;
use DateTime;
use DateTimeZone;

/**
 * Counts YouTube Data API units spent today.
 *
 * The quota is 10,000 units per day per Google Cloud project — not per site or per
 * connection — and it resets at midnight Pacific Time regardless of the server's
 * timezone. The count is a diagnostic for the CP and for the cooldown decision, not
 * a ledger: Craft's cache offers no atomic increment, so two simultaneous requests
 * can lose a unit between them. Google's own figure in the Cloud Console is
 * authoritative; this is here to make "have we run out?" answerable without it.
 */
class QuotaMeter
{
    public const DAILY_LIMIT = 10000;

    private const CACHE_PREFIX = 'social-stream:youtube-quota:';

    private const RESET_TIMEZONE = 'America/Los_Angeles';

    public function record(int $units = 1): void
    {
        $key = $this->key();
        $current = Craft::$app->cache->get($key);
        $used = $current === false ? 0 : (int) $current;

        // Kept alive past the reset so the CP can still show the day's figure for a
        // while after midnight PT, rather than flicking to zero mid-evening.
        Craft::$app->cache->set($key, $used + $units, $this->secondsUntilReset() + 3600);
    }

    public function used(): int
    {
        $current = Craft::$app->cache->get($this->key());

        return $current === false ? 0 : (int) $current;
    }

    public function isExhausted(): bool
    {
        return $this->used() >= self::DAILY_LIMIT;
    }

    /**
     * Seconds until the quota resets — the only sensible cooldown length for a
     * quota error, since every call before then fails the same way.
     */
    public function secondsUntilReset(): int
    {
        $timezone = new DateTimeZone(self::RESET_TIMEZONE);
        $now = new DateTime('now', $timezone);
        $reset = (clone $now)->modify('tomorrow midnight');

        return max(60, $reset->getTimestamp() - $now->getTimestamp());
    }

    private function key(): string
    {
        return self::CACHE_PREFIX . (new DateTime('now', new DateTimeZone(self::RESET_TIMEZONE)))->format('Y-m-d');
    }
}
