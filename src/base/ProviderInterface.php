<?php

namespace enovate\socialstream\base;

/**
 * Contract every Social Stream provider must satisfy.
 *
 * Concrete providers should extend {@see Provider} rather than implement this
 * interface directly — the base class handles rate-limit state, error recording,
 * and lifecycle events.
 */
interface ProviderInterface
{
    /**
     * The stable string handle identifying this provider (e.g. `'instagram'`).
     * Used in cache keys, ConnectionRecord rows, and the `provider` option.
     */
    public function getHandle(): string;

    /**
     * Human-readable name shown in the control panel.
     */
    public function getDisplayName(): string;

    /**
     * Fetch a stream of posts.
     *
     * @param array $options Provider-specific options. Common keys: `siteId`, `limit`, `after`.
     * @return array{success: bool, data: array, nextCursor: string|null, error: string|null, cached: bool}
     *         where `data` is an array of {@see \enovate\socialstream\models\Post}.
     */
    public function fetchStream(array $options): array;

    /**
     * Fetch profile information for this site's connected account.
     *
     * @return array{success: bool, data: array|null, error: string|null}
     */
    public function fetchProfile(int $siteId): array;

    /**
     * Whether the provider has credentials configured for the given site and
     * can make API calls (i.e. is connected / authorised).
     */
    public function isConfigured(int $siteId): bool;

    /**
     * Whether this provider authenticates with OAuth.
     *
     * A provider returning `false` has no authorisation flow, no tokens to store and
     * nothing to refresh, so the control panel hides the connect button and the token
     * refresh cron and console command skip it rather than reporting a failure once a
     * run. YouTube reads a public channel with an API key, which is why this exists.
     *
     * Declared on the contract rather than only on {@see Provider} because the control
     * panel, the auth flow, the token service and both console commands call it
     * statically on whatever the registry hands them. A provider that satisfied this
     * interface without extending the base class did not answer it, and the call took
     * the settings page down with a fatal error rather than skipping the provider.
     */
    public static function usesOAuth(): bool;

    /**
     * Whether the `excludeNonFeed` option means anything to this provider.
     *
     * It is Instagram's "was this shared to the main feed?" flag, and a provider that
     * ignores the option must say so — otherwise {@see \enovate\socialstream\services\CacheService}
     * keys two identical entries on a flag that changed nothing about either.
     */
    public static function usesExcludeNonFeed(): bool;
}
