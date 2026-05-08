<?php

namespace enovate\socialstream\events;

use yii\base\Event;

/**
 * Fired after RefreshStreamJob successfully replaces a cached stream payload.
 *
 * Listeners can use this to invalidate downstream caches (e.g. a CDN / Varnish
 * fronting the pages that render the stream).
 *
 * Not fired on cold-miss synchronous fetches — those happen inside a single
 * front-end request and don't represent a change in the underlying data.
 */
class StreamRefreshedEvent extends Event
{
    public int $siteId;

    public string $provider;

    /** @var array Options passed to the provider's fetchStream() call */
    public array $options = [];

    /** @var array{success: bool, data: array, nextCursor: ?string, error: ?string, cached: bool} */
    public array $response = [];
}
