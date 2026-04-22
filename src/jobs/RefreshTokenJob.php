<?php

namespace enovate\socialstream\jobs;

use Craft;
use craft\db\Query;
use craft\queue\BaseJob;
use enovate\socialstream\SocialStream;

/**
 * Queue job that refreshes an Instagram long-lived token with exponential backoff.
 *
 * Retry schedule: 1 min → 5 min → 30 min, then give up.
 */
class RefreshTokenJob extends BaseJob
{
    public ?int $siteId = null;

    public string $provider;

    /**
     * Current attempt number (0-indexed).
     */
    public int $attempt = 0;

    /**
     * Backoff delays in seconds for each retry attempt.
     */
    private const BACKOFF_DELAYS = [60, 300, 1800];

    public function execute($queue): void
    {
        $result = SocialStream::$plugin->token->refreshToken($this->siteId, $this->provider);

        if ($result['success']) {
            SocialStream::info('Token refresh succeeded for site ' . $this->siteId . ' (attempt ' . ($this->attempt + 1) . ')');
            return;
        }

        // Retry with backoff if we haven't exhausted attempts
        if ($this->attempt < count(self::BACKOFF_DELAYS)) {
            $delay = self::BACKOFF_DELAYS[$this->attempt];

            SocialStream::warning(
                'Token refresh failed for site ' . $this->siteId
                . ' (attempt ' . ($this->attempt + 1) . '). '
                . 'Retrying in ' . ($delay / 60) . ' minutes. '
                . 'Error: ' . $result['error']
            );

            Craft::$app->queue->delay($delay)->push(new static([
                'siteId' => $this->siteId,
                'provider' => $this->provider,
                'attempt' => $this->attempt + 1,
            ]));

            return;
        }

        // All retries exhausted
        SocialStream::error(
            'Token refresh failed for site ' . $this->siteId
            . ' after ' . ($this->attempt + 1) . ' attempts. Giving up. '
            . 'Error: ' . $result['error']
        );
    }

    protected function defaultDescription(): ?string
    {
        $desc = Craft::t('social-stream', 'Refreshing Social Stream token for site {siteId} ({provider})', [
            'siteId' => $this->siteId ?? 'all',
            'provider' => $this->provider,
        ]);

        if ($this->attempt > 0) {
            $desc .= ' (retry ' . $this->attempt . ')';
        }

        return $desc . ' [' . self::dedupTag($this->siteId ?? 0, $this->provider) . ']';
    }

    /**
     * Push a token refresh job, but only if an identical job isn't already queued
     * or running. Safe to call from every web host in a load-balanced setup.
     *
     * Used by the cron entry path. The job's own retry self-reschedule in execute()
     * bypasses this check on purpose — retries must push even while the previous
     * attempt is still in the queue with fail=true.
     */
    public static function pushIfNotQueued(int $siteId, string $provider): void
    {
        $tag = self::dedupTag($siteId, $provider);
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
            return;
        }

        $recentlyFailed = Craft::$app->getDb()->usePrimary(fn() => (new Query())
            ->from('{{%queue}}')
            ->where($like)
            ->andWhere(['fail' => true])
            ->andWhere(['>=', 'timePushed', time() - 7200])
            ->exists());

        if ($recentlyFailed) {
            return;
        }

        Craft::$app->queue->push(new static([
            'siteId' => $siteId,
            'provider' => $provider,
        ]));
    }

    private static function dedupTag(int $siteId, string $provider): string
    {
        return "social-stream:refresh-token:{$siteId}:{$provider}";
    }
}
