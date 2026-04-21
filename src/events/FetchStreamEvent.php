<?php

namespace enovate\socialstream\events;

use enovate\socialstream\base\ProviderInterface;
use yii\base\Event;

/**
 * Fired before and after a provider fetches a stream.
 *
 * - BEFORE: set `$handled = true` and `$result` to skip the API call and return
 *   your own result to the caller.
 * - AFTER: mutate `$result` to transform the data before it is cached / returned.
 */
class FetchStreamEvent extends Event
{
    public ProviderInterface $provider;

    public int $siteId = 0;

    public array $options = [];

    /**
     * @var array|null Null before the fetch completes; populated after.
     *                 Shape: {success, data, nextCursor, error, cached}.
     */
    public ?array $result = null;
}
