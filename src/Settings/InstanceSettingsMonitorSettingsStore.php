<?php

declare(strict_types=1);

namespace Nowo\MessengerMonitorKitBundle\Settings;

use Nowo\InstanceSettingsBundle\Event\SettingsChangedEvent;
use Nowo\InstanceSettingsBundle\Settings\InstanceSettingsInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * `settings.store: instance_settings` — the switches in the `messenger_monitor` section of
 * nowo-tech/instance-settings-bundle ({@see MessengerMonitorSettingsSection}), so they are also editable, exported
 * and imported with the other instance settings. A read error never breaks message handling: the defaults apply.
 */
final readonly class InstanceSettingsMonitorSettingsStore implements MonitorSettingsStoreInterface
{
    /**
     * @param InstanceSettingsInterface $settings instance settings store
     * @param MonitorSettings $defaults switches built from the bundle configuration (also the section field defaults)
     */
    public function __construct(
        private InstanceSettingsInterface $settings,
        private MonitorSettings $defaults,
        private ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * Section values (field defaults for values never saved).
     */
    public function load(): MonitorSettings
    {
        try {
            $values = $this->settings->getSection(MessengerMonitorSettingsSection::NAME);
        } catch (Throwable $e) {
            $this->logger?->warning('Messenger monitor settings could not be read from the instance settings; using the configured defaults.', ['exception' => $e]);

            return $this->defaults;
        }

        return MonitorSettings::fromArray($values, $this->defaults);
    }

    /**
     * Saves the section (validated by the instance settings store).
     */
    public function save(MonitorSettings $settings): void
    {
        $this->settings->save(MessengerMonitorSettingsSection::NAME, $settings->toArray(), SettingsChangedEvent::ORIGIN_FORM);
    }

    /**
     * True.
     */
    public function isWritable(): bool
    {
        return true;
    }
}
