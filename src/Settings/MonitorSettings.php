<?php

declare(strict_types=1);

namespace Nowo\MessengerMonitorKitBundle\Settings;

use Nowo\MessengerMonitorKitBundle\History\PurgePeriod;

use function in_array;
use function is_bool;
use function is_string;

/**
 * Runtime switches of the monitor: history recording, recording of the optional (high-volume) message classes and the
 * automatic purge (retention). Immutable value object; the stores persist {@see toArray()}.
 */
final readonly class MonitorSettings
{
    /**
     * @param bool $recording record history rows
     * @param bool $recordOptional also record `recording.optional_message_classes`
     * @param bool $autoPurge purge automatically (nowo:messenger-monitor:purge --auto)
     * @param PurgePeriod $retention automatic purge: delete history older than this
     * @param bool $keepFailed automatic purge: keep failed rows
     */
    public function __construct(
        public bool $recording = true,
        public bool $recordOptional = false,
        public bool $autoPurge = false,
        public PurgePeriod $retention = PurgePeriod::OneMonth,
        public bool $keepFailed = true,
    ) {
    }

    /**
     * Builds the settings from a stored array; missing or invalid entries keep the `$defaults` value.
     *
     * @param array<mixed> $data
     */
    public static function fromArray(array $data, self $defaults = new self()): self
    {
        $retention = is_string($data['retention'] ?? null) ? PurgePeriod::tryFrom($data['retention']) : null;

        return new self(
            is_bool($data['recording'] ?? null) ? $data['recording'] : $defaults->recording,
            is_bool($data['record_optional'] ?? null) ? $data['record_optional'] : $defaults->recordOptional,
            is_bool($data['auto_purge'] ?? null) ? $data['auto_purge'] : $defaults->autoPurge,
            $retention !== null && in_array($retention, PurgePeriod::retentionCases(), true) ? $retention : $defaults->retention,
            is_bool($data['keep_failed'] ?? null) ? $data['keep_failed'] : $defaults->keepFailed,
        );
    }

    /**
     * Storable array (also the data of the Settings form and the audit event context).
     *
     * @return array{recording: bool, record_optional: bool, auto_purge: bool, retention: string, keep_failed: bool}
     */
    public function toArray(): array
    {
        return [
            'recording'       => $this->recording,
            'record_optional' => $this->recordOptional,
            'auto_purge'      => $this->autoPurge,
            'retention'       => $this->retention->value,
            'keep_failed'     => $this->keepFailed,
        ];
    }
}
