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
}
