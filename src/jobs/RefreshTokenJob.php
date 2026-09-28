<?php

namespace enovate\socialstream\jobs;

use Craft;
use craft\queue\BaseJob;
use enovate\socialstream\records\ConnectionRecord;
use enovate\socialstream\SocialStream;
use yii\base\Exception;

/**
 * Queue job that refreshes an Instagram long-lived token with exponential backoff.
 *
 * Retry schedule: 1 min → 5 min → 30 min, then fail the job so the exhausted
 * refresh is visible in the CP's Queue Manager.
 */
class RefreshTokenJob extends BaseJob
{
    use DedupedPushTrait;

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

    /**
     * @throws Exception if the refresh fails on the final attempt.
     */
    public function execute($queue): void
    {
        // The queue mutex guards which runner reserves a job, not how many run at once:
        // of two concurrent refreshes the loser is refused and flags a healthy
        // connection for re-auth, which the cron then skips permanently.
        $mutex = Craft::$app->getMutex();
        $lockName = 'social-stream:token-refresh:' . ($this->siteId ?? 0) . ':' . $this->provider;

        if (!$mutex->acquire($lockName)) {
            SocialStream::info(
                'Token refresh for site ' . $this->siteId . ' (' . $this->provider . ') '
                . 'skipped: another refresh for this connection is already running.'
            );

            return;
        }

        try {
            $this->refresh();
        } finally {
            $mutex->release($lockName);
        }
    }

    /**
     * @throws Exception if the refresh fails on the final attempt.
     */
    private function refresh(): void
    {
        $result = SocialStream::$plugin->token->refreshToken($this->siteId, $this->provider);

        if ($result['success']) {
            SocialStream::info('Token refresh succeeded for site ' . $this->siteId . ' (attempt ' . ($this->attempt + 1) . ')');
            return;
        }

        // The provider rejected the credential outright (refreshToken() sets the
        // flag on OAuthException 190). Retrying replays a request Meta has already
        // refused, so stop here — the CP banner is the signal, and the cron skips
        // this connection from now on.
        if ($this->needsReauth()) {
            SocialStream::error(
                'Token refresh for site ' . $this->siteId . ' (' . $this->provider . ') '
                . 'was rejected outright; re-authorisation is required. '
                . 'Abandoning the retry schedule. Error: ' . $result['error']
            );

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

        // All retries exhausted. Throw rather than return: a silently "completed"
        // job leaves no trace in the CP, which is how expired tokens went unnoticed
        // for weeks. Failing here surfaces it on the Queue Manager instead.
        $message = 'Token refresh failed for site ' . $this->siteId
            . ' (' . $this->provider . ') after ' . ($this->attempt + 1) . ' attempts: '
            . $result['error'];

        SocialStream::error($message);

        throw new Exception($message);
    }

    /**
     * Whether the provider has flagged this connection as needing re-authorisation.
     */
    private function needsReauth(): bool
    {
        $connection = ConnectionRecord::findOne([
            'siteId' => $this->siteId,
            'provider' => $this->provider,
        ]);

        return $connection !== null && $connection->needsReauthAt !== null;
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
     *
     * @return bool Whether a job was pushed.
     */
    public static function pushIfNotQueued(int $siteId, string $provider): bool
    {
        $tag = self::dedupTag($siteId, $provider);

        return self::withPushLock($tag, static function () use ($siteId, $provider, $tag): bool {
            if (!self::queueIsClear($tag)) {
                return false;
            }

            Craft::$app->queue->push(new static([
                'siteId' => $siteId,
                'provider' => $provider,
            ]));

            return true;
        });
    }

    private static function dedupTag(int $siteId, string $provider): string
    {
        return "social-stream:refresh-token:{$siteId}:{$provider}";
    }
}
