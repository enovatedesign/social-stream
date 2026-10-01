<?php

namespace enovate\socialstream\jobs;

use Craft;
use craft\queue\BaseJob;
use enovate\socialstream\providers\YouTubeProvider;
use enovate\socialstream\SocialStream;

/**
 * Renews a site's YouTube WebSub subscription, then queues its own successor.
 *
 * The hub grants a lease of at most 10 days and stops delivering when it lapses, so
 * something has to re-subscribe indefinitely. The `social-stream/web-sub/renew`
 * command is the primary mechanism; this job is the belt and braces for sites with
 * no cron configured, which is why it reschedules itself rather than relying on one.
 *
 * Re-subscribing is idempotent — it resets the lease — so the two overlapping
 * mechanisms are harmless. The dedupe on push is what stops the self-reschedule from
 * multiplying: two copies in the queue would otherwise become four.
 */
class RenewWebSubJob extends BaseJob
{
    use DedupedPushTrait;

    public ?int $siteId = null;

    /**
     * A day short of the 10-day lease, so a missed run still has a window left.
     */
    public const RENEW_DELAY = 9 * 24 * 3600;

    /**
     * A hub that refused the subscription is usually briefly unavailable rather than
     * permanently unwilling, so retry in an hour instead of burning the lease.
     */
    public const RETRY_DELAY = 3600;

    public function execute($queue): void
    {
        $siteId = (int) $this->siteId;
        $provider = SocialStream::$plugin->providers->getProviderByHandle(YouTubeProvider::handle());

        if (!$provider instanceof YouTubeProvider) {
            SocialStream::warning('RenewWebSubJob: the YouTube provider is not registered.');

            return;
        }

        $result = $provider->subscribeWebSub($siteId);

        if ($result['success']) {
            SocialStream::info('WebSub subscription renewed for site ' . $siteId . '.');
            $this->reschedule($siteId, self::RENEW_DELAY);

            return;
        }

        SocialStream::warning(
            'WebSub renewal failed for site ' . $siteId . ': ' . ($result['error'] ?? 'unknown error')
            . '. Retrying in an hour.'
        );

        $this->reschedule($siteId, self::RETRY_DELAY);
    }

    /**
     * Queue the successor, deliberately skipping the dedupe check.
     *
     * This job's own row is still in the queue table while it runs, so the check
     * would see it as pending and the chain would end here. Duplicate chains are
     * additive rather than compounding — each execution pushes exactly one
     * successor — and {@see pushRenewal()} guards every entry point that could start
     * a second one.
     */
    private function reschedule(int $siteId, int $delay): void
    {
        Craft::$app->queue->delay($delay)->push(new static(['siteId' => $siteId]));
    }

    /**
     * Queue a renewal, unless an identical one is already waiting.
     *
     * @return bool Whether a job was pushed.
     */
    public static function pushRenewal(int $siteId, int $delay = self::RENEW_DELAY): bool
    {
        $tag = self::dedupTag($siteId);

        return self::withPushLock($tag, static function () use ($siteId, $delay, $tag): bool {
            if (!self::queueIsClear($tag)) {
                return false;
            }

            Craft::$app->queue->delay($delay)->push(new static(['siteId' => $siteId]));

            return true;
        });
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('social-stream', 'Renewing the YouTube WebSub subscription for site {siteId}', [
            'siteId' => $this->siteId ?? 'all',
        ]) . ' [' . self::dedupTag((int) $this->siteId) . ']';
    }

    private static function dedupTag(int $siteId): string
    {
        return "social-stream:renew-websub:{$siteId}";
    }
}
