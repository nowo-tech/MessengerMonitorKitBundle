<?php

declare(strict_types=1);

namespace Nowo\MessengerMonitorKitBundle\Settings;

/**
 * Where the runtime switches live (`settings.store`).
 */
enum SettingsStore: string
{
    /** A shared PSR-6 cache pool (default). */
    case Cache = 'cache';

    /** A `messenger_monitor` section of nowo-tech/instance-settings-bundle. */
    case InstanceSettings = 'instance_settings';

    /** Read-only YAML defaults: no runtime switch, no Settings form. */
    case Config = 'config';
}
