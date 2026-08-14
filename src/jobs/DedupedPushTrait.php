<?php

namespace enovate\socialstream\jobs;

use Craft;

/**
 * Serialises a job's dedupe-then-push across every host sharing the database.
 * Unlocked, the queue-table check is check-then-act: hosts on one crontab all
 * see an empty queue and all push.
 */
trait DedupedPushTrait
{
    /**
     * Zero timeout is deliberate: a caller that loses the race gives up, since waiting
     * would just serialise the duplicate it was avoiding.
     *
     * @param callable(): bool $push Returns whether it pushed a job.
     */
    protected static function withPushLock(string $tag, callable $push): bool
    {
        $mutex = Craft::$app->getMutex();
        $lockName = 'social-stream:push:' . $tag;

        if (!$mutex->acquire($lockName)) {
            return false;
        }

        try {
            return $push();
        } finally {
            $mutex->release($lockName);
        }
    }
}
