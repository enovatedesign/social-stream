<?php

namespace enovate\socialstream\jobs;

use Craft;
use craft\queue\BaseJob;
use enovate\socialstream\events\StreamRefreshedEvent;
use enovate\socialstream\models\Post;
use enovate\socialstream\SocialStream;
use yii\base\Event;

/**
 * Queue job that refreshes cached stream data in the background.
 *
 * Calls the configured provider's fetchStream() directly (bypassing the cache read)
 * and stores the result in the cache, so the next front-end request gets fresh
 * data without waiting for an API call.
 */
class RefreshStreamJob extends BaseJob
{
    use DedupedPushTrait;

    public const EVENT_AFTER_REFRESH_STREAM = 'afterRefreshStream';

    public ?int $siteId = null;

    public string $provider;

    public array $options = [];

    public function execute($queue): void
    {
        $options = array_merge($this->options, [
            'siteId' => $this->siteId,
            'provider' => $this->provider,
        ]);

        $provider = SocialStream::$plugin->providers->getProviderByHandle($this->provider);

        if ($provider === null) {
            SocialStream::warning("RefreshStreamJob: no provider registered for '{$this->provider}'");
            return;
        }

        $response = $provider->fetchStream($options);

        if ($response['success']) {
            // Capture the previous payload before overwriting it so we can detect
            // whether the refresh actually produced different data. Skip the read
            // entirely when nothing is subscribed to the event.
            //
            // Queue jobs extend BaseObject rather than Component, so they have no
            // instance-level event methods — the class-level Event API is used instead.
            $hasHandlers = Event::hasHandlers($this, self::EVENT_AFTER_REFRESH_STREAM);
            $previousFingerprint = $hasHandlers
                ? $this->fingerprintResponse(SocialStream::$plugin->streamCache->getStream($options)['data'])
                : null;

            SocialStream::$plugin->streamCache->setStream($options, $response, $this->siteId);
            SocialStream::info('Background stream refresh completed for site ' . $this->siteId . ' (' . $this->provider . ')');

            if ($hasHandlers && $this->fingerprintResponse($response) !== $previousFingerprint) {
                Event::trigger($this, self::EVENT_AFTER_REFRESH_STREAM, new StreamRefreshedEvent([
                    'siteId' => (int) $this->siteId,
                    'provider' => $this->provider,
                    'options' => $options,
                    'response' => $response,
                ]));
            }
        } else {
            SocialStream::warning(
                'Background stream refresh failed for site ' . $this->siteId
                . ' (' . $this->provider . '): ' . ($response['error'] ?? 'unknown error')
            );
        }
    }

    /**
     * Hash of the visible response data, used to skip firing the refresh event
     * when a background refresh produced an identical payload to what was
     * already cached. Returns null when there is no previous payload (cold cache).
     */
    private function fingerprintResponse(?array $response): ?string
    {
        if ($response === null || !isset($response['data']) || !is_array($response['data'])) {
            return null;
        }

        $data = array_map(
            fn($item) => $item instanceof Post ? $item->toArray() : $item,
            $response['data'],
        );

        return md5(json_encode($data));
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('social-stream', 'Refreshing Social Stream for site {siteId} ({provider})', [
            'siteId' => $this->siteId ?? 'all',
            'provider' => $this->provider,
        ]) . ' [' . self::dedupTag($this->siteId ?? 0, $this->provider) . ']';
    }

    /**
     * Push this job to the queue, but only if an identical job isn't already queued
     * or running. Safe to call from every web host in a load-balanced setup.
     *
     * @return bool Whether a job was pushed.
     */
    public static function pushIfNotQueued(int $siteId, array $options, string $provider): bool
    {
        $fingerprint = md5(json_encode([
            'class' => static::class,
            'siteId' => $siteId,
            'provider' => $provider,
            'options' => $options,
        ]));

        // Per-host cooldown: Craft's default cache isn't shared, so this damps local
        // churn only — the fleet-wide guard is the lock below.
        $cacheKey = 'social-stream:job-dedup:' . $fingerprint;
        $tag = self::dedupTag($siteId, $provider);

        return self::withPushLock($tag, static function () use ($siteId, $options, $provider, $cacheKey, $tag): bool {
            if (Craft::$app->cache->get($cacheKey) !== false) {
                return false;
            }

            if (!self::queueIsClear($tag)) {
                return false;
            }

            Craft::$app->queue->push(new static([
                'siteId' => $siteId,
                'provider' => $provider,
                'options' => $options,
            ]));

            Craft::$app->cache->set($cacheKey, true, 60);

            return true;
        });
    }

    private static function dedupTag(int $siteId, string $provider): string
    {
        return "social-stream:refresh-stream:{$siteId}:{$provider}";
    }
}
