<?php

namespace enovate\socialstream\base;

use Craft;
use craft\base\Component;
use enovate\socialstream\events\FetchStreamEvent;
use enovate\socialstream\records\ConnectionRecord;
use enovate\socialstream\SocialStream;

/**
 * Base class for Social Stream providers.
 *
 * Subclasses implement the provider-specific {@see doFetchStream()} and
 * {@see doFetchProfile()} hooks; the base class wraps those calls with
 * rate-limit suppression, error recording, last-fetch timestamp updates,
 * and lifecycle event emission.
 */
abstract class Provider extends Component implements ProviderInterface
{
    public const EVENT_BEFORE_FETCH_STREAM = 'beforeFetchStream';

    public const EVENT_AFTER_FETCH_STREAM = 'afterFetchStream';

    /**
     * How long to suppress API calls after a rate-limit hit (seconds).
     */
    protected const RATE_LIMIT_TTL = 900;

    // Abstract static metadata
    // =========================================================================

    abstract public static function handle(): string;

    abstract public static function displayName(): string;

    // Instance delegates — cheap sugar so callers can work with instances.
    // =========================================================================

    public function getHandle(): string
    {
        return static::handle();
    }

    public function getDisplayName(): string
    {
        return static::displayName();
    }

    // Template methods
    // =========================================================================

    /**
     * Fetch a stream. Wraps {@see doFetchStream()} with rate-limit checks and events.
     */
    public function fetchStream(array $options): array
    {
        $siteId = (int) ($options['siteId'] ?? Craft::$app->sites->currentSite->id);

        $before = new FetchStreamEvent([
            'provider' => $this,
            'siteId' => $siteId,
            'options' => $options,
        ]);
        $this->trigger(self::EVENT_BEFORE_FETCH_STREAM, $before);

        if ($before->handled && $before->result !== null) {
            return $before->result;
        }

        if ($this->isRateLimited($siteId)) {
            return $this->streamErrorResponse('API rate limit active. Please try again later.');
        }

        $result = $this->doFetchStream($options);

        if (($result['success'] ?? false) === true) {
            $this->updateLastFetch($siteId);
        }

        $after = new FetchStreamEvent([
            'provider' => $this,
            'siteId' => $siteId,
            'options' => $options,
            'result' => $result,
        ]);
        $this->trigger(self::EVENT_AFTER_FETCH_STREAM, $after);

        return $after->result ?? $result;
    }

    public function fetchProfile(int $siteId): array
    {
        if ($this->isRateLimited($siteId)) {
            return $this->errorResponse('API rate limit active. Please try again later.');
        }

        $result = $this->doFetchProfile($siteId);

        if (($result['success'] ?? false) === true) {
            $this->updateLastFetch($siteId);
        }

        return $result;
    }

    public function isConfigured(int $siteId): bool
    {
        $token = SocialStream::$plugin->token->getAccessToken($siteId, $this->getHandle());

        return $token !== null && $token !== '';
    }

    // Provider-specific work
    // =========================================================================

    /**
     * Provider-specific stream fetching. Must return the stream response contract.
     *
     * @return array{success: bool, data: array, nextCursor: string|null, error: string|null, cached: bool}
     */
    abstract protected function doFetchStream(array $options): array;

    /**
     * Provider-specific profile fetching.
     *
     * @return array{success: bool, data: array|null, error: string|null}
     */
    abstract protected function doFetchProfile(int $siteId): array;

    // Rate-limit state (per provider + site)
    // =========================================================================

    protected function isRateLimited(int $siteId): bool
    {
        $key = $this->rateLimitKey($siteId);
        $wasLimitedKey = $this->rateLimitExpiryKey($siteId);

        $isLimited = Craft::$app->cache->get($key) !== false;

        if (!$isLimited && Craft::$app->cache->get($wasLimitedKey) !== false) {
            SocialStream::info('Rate-limit cooldown expired for ' . $this->getHandle() . ' site ' . $siteId . '. API calls resumed.');
            Craft::$app->cache->delete($wasLimitedKey);
        }

        return $isLimited;
    }

    protected function enterRateLimitCooldown(int $siteId): void
    {
        $key = $this->rateLimitKey($siteId);
        $wasLimitedKey = $this->rateLimitExpiryKey($siteId);

        if (Craft::$app->cache->get($key) === false) {
            SocialStream::warning(
                'Rate limit hit for ' . $this->getHandle() . ' site ' . $siteId
                . '. Entering ' . (static::RATE_LIMIT_TTL / 60) . '-minute cooldown.'
            );
        }

        Craft::$app->cache->set($key, true, static::RATE_LIMIT_TTL);
        Craft::$app->cache->set($wasLimitedKey, true, static::RATE_LIMIT_TTL * 2);
    }

    private function rateLimitKey(int $siteId): string
    {
        return 'social-stream:rate-limited:' . $this->getHandle() . ':' . $siteId;
    }

    private function rateLimitExpiryKey(int $siteId): string
    {
        return 'social-stream:was-rate-limited:' . $this->getHandle() . ':' . $siteId;
    }

    // Connection record — error and last-fetch tracking
    // =========================================================================

    protected function recordError(int $siteId, string $message): void
    {
        $connection = ConnectionRecord::findOne([
            'siteId' => $siteId,
            'provider' => $this->getHandle(),
        ]);

        if ($connection) {
            $connection->lastError = $message;
            $connection->lastErrorAt = (new \DateTime())->format('Y-m-d H:i:s');
            $connection->save();
        }

        SocialStream::error($message);
    }

    protected function updateLastFetch(int $siteId): void
    {
        $connection = ConnectionRecord::findOne([
            'siteId' => $siteId,
            'provider' => $this->getHandle(),
        ]);

        if ($connection) {
            $connection->lastFetchAt = (new \DateTime())->format('Y-m-d H:i:s');
            $connection->lastError = null;
            $connection->lastErrorAt = null;
            $connection->save();
        }
    }

    // Response shapes
    // =========================================================================

    protected function errorResponse(string $error): array
    {
        return [
            'success' => false,
            'data' => null,
            'error' => $error,
        ];
    }

    protected function streamErrorResponse(string $error): array
    {
        return [
            'success' => false,
            'data' => [],
            'nextCursor' => null,
            'error' => $error,
            'cached' => false,
        ];
    }
}
