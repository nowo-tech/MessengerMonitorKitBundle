<?php

declare(strict_types=1);

namespace Nowo\MessengerMonitorKitBundle\Settings;

use LogicException;

/**
 * Loads and saves the runtime switches ({@see MonitorSettings}). Implementations must be shared by the web and the
 * worker processes (the recording switch is read by the workers).
 */
interface MonitorSettingsStoreInterface
{
    /**
     * Current switches (the configured defaults for values never saved).
     */
    public function load(): MonitorSettings;

    /**
     * Persists the switches.
     *
     * @throws LogicException when the store is read-only ({@see isWritable()})
     */
    public function save(MonitorSettings $settings): void;

    /**
     * Whether {@see save()} is supported (false for the `config` store: no Settings form).
     */
    public function isWritable(): bool;
}
