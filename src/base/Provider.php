<?php

namespace enovate\socialstream\base;

use Craft;
use craft\base\Component;
use craft\helpers\DateTimeHelper;
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

    /**
     * How long to suppress API calls after a failed fetch (seconds).
     *
     * Failed responses aren't cached, so without this a persistent upstream
     * failure would mean one live API call per uncached request.
     */
    protected const FAILURE_BACKOFF_TTL = 300;

    /**
     * How often to let a single request through for a credential the provider has
     * rejected (seconds), so a transient rejection recovers without a human.
     */
    protected const REAUTH_PROBE_INTERVAL = 3600;

    // Static metadata
    // =========================================================================

    abstract public static function handle(): string;

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

        // The credential has been rejected outright — every call would fail the
        // same way, so fail fast rather than burning API quota on each request.
        // One request an hour is let through as a probe: if the rejection was
        // transient, that probe succeeds and clears the flag with no human involved.
        if ($this->needsReauth($siteId) && !$this->shouldProbeRejectedCredential($siteId)) {
            return $this->streamErrorResponse(
                'The ' . $this->getDisplayName() . ' connection needs re-authorising.'
            );
        }

        $backoffError = $this->failureBackoffError($siteId);

        if ($backoffError !== null) {
            return $this->streamErrorResponse($backoffError);
        }

        $result = $this->doFetchStream($options);

        if (($result['success'] ?? false) === true) {
            $this->updateLastFetch($siteId);
        } else {
            $this->enterFailureBackoff($siteId, $result['error'] ?? 'Unknown error.');
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

        // A profile call proves the credential works, so it clears the re-auth
        // flag — but it fetches no posts, so it must not stamp lastFetchAt or
        // clear a lastError raised by the stream. Both are stream diagnostics,
        // and wiping them here would erase the evidence someone opened the CP
        // to read.
        if (($result['success'] ?? false) === true) {
            $this->clearReauthFlag($siteId);
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

    // Failure backoff and re-auth probing
    // =========================================================================

    /**
     * The remembered error from a recent failed fetch, or null if the backoff
     * window has passed. Failures aren't cached as responses, so this is what
     * stops a persistent upstream failure becoming one API call per request.
     */
    protected function failureBackoffError(int $siteId): ?string
    {
        $error = Craft::$app->cache->get($this->failureBackoffKey($siteId));

        return $error === false ? null : $error;
    }

    protected function enterFailureBackoff(int $siteId, string $error): void
    {
        Craft::$app->cache->set($this->failureBackoffKey($siteId), $error, static::FAILURE_BACKOFF_TTL);
    }

    /**
     * Whether this request should be let through as a probe for a credential the
     * provider has rejected. Allows one attempt per {@see REAUTH_PROBE_INTERVAL};
     * if it succeeds, {@see updateLastFetch()} clears the flag.
     */
    private function shouldProbeRejectedCredential(int $siteId): bool
    {
        $key = 'social-stream:reauth-probe:' . $this->getHandle() . ':' . $siteId;

        if (Craft::$app->cache->get($key) !== false) {
            return false;
        }

        Craft::$app->cache->set($key, true, static::REAUTH_PROBE_INTERVAL);

        SocialStream::info(
            'Probing the rejected ' . $this->getDisplayName() . ' credential for site ' . $siteId
            . ' to see whether it has recovered.'
        );

        return true;
    }

    private function failureBackoffKey(int $siteId): string
    {
        return 'social-stream:failure-backoff:' . $this->getHandle() . ':' . $siteId;
    }

    private function rateLimitKey(int $siteId): string
    {
        return 'social-stream:rate-limited:' . $this->getHandle() . ':' . $siteId;
    }

    private function rateLimitExpiryKey(int $siteId): string
    {
        return 'social-stream:was-rate-limited:' . $this->getHandle() . ':' . $siteId;
    }

    // Connection record — error, re-auth and last-fetch tracking
    // =========================================================================

    protected function recordError(int $siteId, string $message): void
    {
        $connection = $this->connection($siteId);

        if ($connection) {
            $connection->lastError = $message;
            $connection->lastErrorAt = DateTimeHelper::currentUTCDateTime()->format('Y-m-d H:i:s');
            $connection->save();
        }

        SocialStream::error($message);
    }

    /**
     * Flag the connection as needing re-authorisation, e.g. after the provider
     * rejects the stored credential. Idempotent — the timestamp records when the
     * rejection was *first* seen, so the CP can report how long it has been broken.
     */
    protected function markNeedsReauth(int $siteId): void
    {
        $connection = $this->connection($siteId);

        if ($connection === null || $connection->needsReauthAt !== null) {
            return;
        }

        $connection->needsReauthAt = DateTimeHelper::currentUTCDateTime()->format('Y-m-d H:i:s');
        $connection->save();

        SocialStream::warning(
            $this->getDisplayName() . ' rejected the stored token for site ' . $siteId
            . '. Re-authorisation is required; API calls are suspended until then.'
        );
    }

    /**
     * Whether the provider has rejected this connection's credential.
     */
    public function needsReauth(int $siteId): bool
    {
        $connection = $this->connection($siteId);

        return $connection !== null && $connection->needsReauthAt !== null;
    }

    /**
     * Clear the re-auth flag after the provider accepts the credential again.
     * Leaves the stream diagnostics (lastError, lastFetchAt) untouched.
     */
    protected function clearReauthFlag(int $siteId): void
    {
        $connection = $this->connection($siteId);

        if ($connection === null || $connection->needsReauthAt === null) {
            return;
        }

        $connection->needsReauthAt = null;
        $connection->save();

        SocialStream::info(
            $this->getDisplayName() . ' accepted the stored token for site ' . $siteId
            . ' again. Re-auth flag cleared; API calls resumed.'
        );
    }

    /**
     * Record a successful stream fetch: stamp the timestamp and clear the error
     * state it supersedes.
     */
    protected function updateLastFetch(int $siteId): void
    {
        $connection = $this->connection($siteId);

        if ($connection) {
            $connection->lastFetchAt = DateTimeHelper::currentUTCDateTime()->format('Y-m-d H:i:s');
            $connection->lastError = null;
            $connection->lastErrorAt = null;
            $connection->needsReauthAt = null;
            $connection->save();
        }
    }

    private function connection(int $siteId): ?ConnectionRecord
    {
        return ConnectionRecord::findOne([
            'siteId' => $siteId,
            'provider' => $this->getHandle(),
        ]);
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
