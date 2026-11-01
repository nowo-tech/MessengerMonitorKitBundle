<?php

declare(strict_types=1);

namespace Nowo\MessengerMonitorKitBundle\Recording;

use Nowo\MessengerMonitorKitBundle\Settings\MonitorSettings;
use Nowo\MessengerMonitorKitBundle\Settings\MonitorSettingsStoreInterface;
use Psr\Clock\ClockInterface;

/**
 * The switches as seen by the workers: read from the store at most once per `recording.check_interval` seconds.
 *
 * Deliberately not reset between messages (kernel.reset): resetting meant one store read per handled message. The
 * cached value is configuration (two booleans), never request or user data, and the interval bounds its staleness.
 */
final class RecordingSwitch
{
    private ?MonitorSettings $settings = null;

    private int $checkedAt = 0;

    /**
     * @param int $checkInterval seconds a read is reused (0 = read on every call)
     */
    public function __construct(
        private readonly MonitorSettingsStoreInterface $store,
        private readonly ClockInterface $clock,
        private readonly int $checkInterval = 30,
    ) {
    }

    /**
     * Current switches (cached for the check interval).
     */
    public function settings(): MonitorSettings
    {
        $now = $this->clock->now()->getTimestamp();
        if (!$this->settings instanceof MonitorSettings || $now - $this->checkedAt >= $this->checkInterval) {
            // @igor-ignore - TTL-bounded copy of two configuration switches (not request / user state); see class doc.
            $this->settings = $this->store->load();
            // @igor-ignore - Timestamp of the TTL-bounded switch copy above.
            $this->checkedAt = $now;
        }

        return $this->settings;
    }
}
