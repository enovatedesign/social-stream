<?php

namespace enovate\socialstream\jobs;

use Craft;
use craft\db\Query;
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
        return Craft::t('social-stream', 'Refreshing Social Stream for site {siteId} ({provider})', [
            'siteId' => $this->siteId ?? 'all',
            'provider' => $this->provider,
        ]) . ' [' . self::dedupTag($this->siteId ?? 0, $this->provider) . ']';
    }

    /**
     * Push this job to the queue, but only if an identical job isn't already queued
     * or running. Safe to call from every web host in a load-balanced setup.
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

        $tag = self::dedupTag($siteId, $provider);

        if (!self::queueIsClear($tag)) {
            return;
        }

        Craft::$app->queue->push(new static([
            'siteId' => $siteId,
            'provider' => $provider,
            'options' => $options,
        ]));

        Craft::$app->cache->set($cacheKey, true, 60);
    }

    /**
     * Check the Craft queue table for a pending / running / recently-failed job
     * with the same dedup tag. Reads go through the primary DB so replica lag
     * can't mislead a host into queueing a duplicate.
     */
    private static function queueIsClear(string $tag): bool
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

    private static function dedupTag(int $siteId, string $provider): string
    {
        return "social-stream:refresh-stream:{$siteId}:{$provider}";
    }
}
