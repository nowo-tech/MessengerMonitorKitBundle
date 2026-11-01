<?php

declare(strict_types=1);

namespace Nowo\MessengerMonitorKitBundle\Settings;

use LogicException;

/**
 * `settings.store: config` — the YAML defaults, read-only (changing a switch needs a deploy).
 */
final readonly class ConfigMonitorSettingsStore implements MonitorSettingsStoreInterface
{
    /**
     * @param MonitorSettings $defaults switches built from the bundle configuration
     */
    public function __construct(
        private MonitorSettings $defaults,
    ) {
    }

    /**
     * The configured defaults.
     */
    public function load(): MonitorSettings
    {
        return $this->defaults;
    }

    /**
     * Always throws: the configuration cannot change at runtime.
     */
    public function save(MonitorSettings $settings): void
    {
        throw new LogicException('The messenger monitor settings come from the configuration (settings.store: config) and cannot be changed at runtime.');
    }

    /**
     * False.
     */
    public function isWritable(): bool
    {
        return false;
    }
}
