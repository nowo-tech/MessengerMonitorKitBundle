<?php

declare(strict_types=1);

namespace Nowo\MessengerMonitorKitBundle\Settings;

use Nowo\InstanceSettingsBundle\Section\AbstractSettingsSection;
use Nowo\InstanceSettingsBundle\Section\SettingField;
use Nowo\MessengerMonitorKitBundle\History\PurgePeriod;
use Nowo\MessengerMonitorKitBundle\NowoMessengerMonitorKitBundle;

/**
 * The `messenger_monitor` instance settings section (`settings.store: instance_settings`): recording switches and
 * automatic purge. Field defaults are the bundle configuration defaults.
 */
final class MessengerMonitorSettingsSection extends AbstractSettingsSection
{
    /** Section name (key prefix `messenger_monitor.`, URL segment, export key). */
    public const NAME = 'messenger_monitor';

    /**
     * @param MonitorSettings $defaults switches built from the bundle configuration
     */
    public function __construct(
        private readonly MonitorSettings $defaults = new MonitorSettings(),
    ) {
    }

    /**
     * `messenger_monitor`.
     */
    public function getName(): string
    {
        return self::NAME;
    }

    /**
     * Section title key.
     */
    public function getLabel(): string
    {
        return 'settings.section.label';
    }

    /**
     * `NowoMessengerMonitorKitBundle`.
     */
    public function getTranslationDomain(): string
    {
        return NowoMessengerMonitorKitBundle::TRANSLATION_DOMAIN;
    }

    /**
     * Intro key.
     */
    public function getIntro(): string
    {
        return 'settings.section.intro';
    }

    /**
     * Fields in form order (same keys as {@see MonitorSettings::toArray()}).
     *
     * @return iterable<SettingField>
     */
    public function getFields(): iterable
    {
        $retention = [];
        foreach (PurgePeriod::retentionCases() as $period) {
            $retention[$period->translationKey()] = $period->value;
        }

        yield SettingField::bool('recording', $this->defaults->recording, 'settings.recording', 'settings.recording_help');
        yield SettingField::bool('record_optional', $this->defaults->recordOptional, 'settings.record_optional', 'settings.record_optional_help');
        yield SettingField::bool('auto_purge', $this->defaults->autoPurge, 'settings.auto_purge', 'settings.auto_purge_help');
        yield SettingField::choice('retention', $retention, $this->defaults->retention->value, 'settings.retention', 'settings.retention_help', true);
        yield SettingField::bool('keep_failed', $this->defaults->keepFailed, 'settings.keep_failed', 'settings.keep_failed_help');
    }
}
