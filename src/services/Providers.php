<?php

namespace enovate\socialstream\services;

use Craft;
use craft\base\Component;
use craft\events\RegisterComponentTypesEvent;
use enovate\socialstream\base\ProviderInterface;
use InvalidArgumentException;

/**
 * Registry of available Social Stream providers.
 *
 * Third-party plugins and modules can register their own providers by
 * listening for {@see self::EVENT_REGISTER_PROVIDER_TYPES}:
 *
 * ```php
 * Event::on(
 *     Providers::class,
 *     Providers::EVENT_REGISTER_PROVIDER_TYPES,
 *     function (RegisterComponentTypesEvent $event) {
 *         $event->types[] = MyProvider::class;
 *     }
 * );
 * ```
 */
class Providers extends Component
{
    public const EVENT_REGISTER_PROVIDER_TYPES = 'registerProviderTypes';

    /**
     * @var ProviderInterface[]|null Instantiated providers, keyed by handle. Lazily built.
     */
    private ?array $_providers = null;

    /**
     * @return ProviderInterface[] All registered providers, keyed by handle.
     */
    public function getAllProviders(): array
    {
        if ($this->_providers !== null) {
            return $this->_providers;
        }

        $event = new RegisterComponentTypesEvent(['types' => []]);
        $this->trigger(self::EVENT_REGISTER_PROVIDER_TYPES, $event);

        $providers = [];
        foreach ($event->types as $class) {
            if (!is_string($class) || !is_subclass_of($class, ProviderInterface::class)) {
                continue;
            }

            /** @var ProviderInterface $instance */
            $instance = Craft::createObject($class);
            $providers[$instance->getHandle()] = $instance;
        }

        return $this->_providers = $providers;
    }

    public function getProviderByHandle(string $handle): ?ProviderInterface
    {
        return $this->getAllProviders()[$handle] ?? null;
    }

    /**
     * Throwing variant for code paths where the provider must exist.
     */
    public function requireProviderByHandle(string $handle): ProviderInterface
    {
        $provider = $this->getProviderByHandle($handle);

        if ($provider === null) {
            throw new InvalidArgumentException("No Social Stream provider registered with handle '{$handle}'.");
        }

        return $provider;
    }
}
