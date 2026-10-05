<?php

namespace enovate\socialstream\jobs;

use Craft;
use craft\db\Query;

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

    /**
     * Check the Craft queue table for a pending / running / recently-failed job
     * with the same dedup tag. Reads go through the primary DB so replica lag
     * can't mislead a host into queueing a duplicate.
     *
     * Call inside {@see withPushLock()} — on its own it is check-then-act.
     */
    protected static function queueIsClear(string $tag): bool
    {
        $like = ['like', 'description', $tag];

        Craft::$app->getDb()->usePrimary(function () use ($like) {
            Craft::$app->getDb()->createCommand()
                ->delete('{{%queue}}', [
                    'and',
                    $like,
                    ['fail' => true],
                    ['<', 'timePushed', time() - 86400],
                ])
                ->execute();
        });

        $pending = Craft::$app->getDb()->usePrimary(fn() => (new Query())
            ->from('{{%queue}}')
            ->where($like)
            ->andWhere(['fail' => false])
            ->exists());

        if ($pending) {
            return false;
        }

        $recentlyFailed = Craft::$app->getDb()->usePrimary(fn() => (new Query())
            ->from('{{%queue}}')
            ->where($like)
            ->andWhere(['fail' => true])
            ->andWhere(['>=', 'timePushed', time() - 7200])
            ->exists());

        return !$recentlyFailed;
    }
}
