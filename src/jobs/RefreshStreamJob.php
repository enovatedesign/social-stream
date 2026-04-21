<?php

namespace enovate\socialstream\jobs;

use Craft;
use craft\queue\BaseJob;
use enovate\socialstream\SocialStream;

/**
 * Queue job that refreshes cached stream data in the background.
 *
 * Calls the configured provider's fetchStream() directly (bypassing the cache read)
 * and stores the result in the cache, so the next front-end request gets fresh
 * data without waiting for an API call.
 */
class RefreshStreamJob extends BaseJob
{
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
            SocialStream::$plugin->streamCache->setStream($options, $response, $this->siteId);
            SocialStream::info('Background stream refresh completed for site ' . $this->siteId . ' (' . $this->provider . ')');
        } else {
            SocialStream::warning(
                'Background stream refresh failed for site ' . $this->siteId
                . ' (' . $this->provider . '): ' . ($response['error'] ?? 'unknown error')
            );
        }
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('social-stream', 'Refreshing Social Stream for site {siteId}', [
            'siteId' => $this->siteId ?? 'all',
        ]);
    }

    /**
     * Push this job to the queue, but only if an identical job isn't already queued.
     */
    public static function pushIfNotQueued(int $siteId, array $options, string $provider): void
    {
        $fingerprint = md5(json_encode([
            'class' => static::class,
            'siteId' => $siteId,
            'provider' => $provider,
            'options' => $options,
        ]));

        $cacheKey = 'social-stream:job-dedup:' . $fingerprint;

        if (Craft::$app->cache->get($cacheKey) !== false) {
            return;
        }

        Craft::$app->cache->set($cacheKey, true, 60);

        Craft::$app->queue->push(new static([
            'siteId' => $siteId,
            'provider' => $provider,
            'options' => $options,
        ]));
    }
}
