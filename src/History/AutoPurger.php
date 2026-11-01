<?php

declare(strict_types=1);

namespace Nowo\MessengerMonitorKitBundle\History;

use DateTimeImmutable;
use Nowo\MessengerMonitorKitBundle\Settings\MonitorSettingsStoreInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Throwable;

use function is_int;

/**
 * Automatic purge (retention): purges with the runtime retention when the automatic purge is on and the last run is
 * older than `retention.min_interval`. Run `nowo:messenger-monitor:purge --auto` often (cron, Symfony Scheduler); the
 * switch and the retention change without a restart. The last run instant lives in the shared cache pool.
 */
final readonly class AutoPurger
{
    /** Cache key of the last automatic run (Unix timestamp). */
    public const LAST_RUN_KEY = 'nowo_messenger_monitor_kit.auto_purge_last_run';

    /**
     * @param int $minInterval seconds between two automatic purges
     */
    public function __construct(
        private HistoryPurger $purger,
        private MonitorSettingsStoreInterface $settings,
        private CacheItemPoolInterface $pool,
        private ClockInterface $clock,
        private int $minInterval = 3600,
        private ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * Whether the automatic purge switch is on.
     */
    public function isEnabled(): bool
    {
        return $this->settings->load()->autoPurge;
    }

    /**
     * Whether an automatic run is due now.
     */
    public function isDue(): bool
    {
        if (!$this->isEnabled()) {
            return false;
        }
        $last = $this->lastRunAt();

        return !$last instanceof DateTimeImmutable || $this->clock->now()->getTimestamp() - $last->getTimestamp() >= $this->minInterval;
    }

    /**
     * Runs the automatic purge when due (or always with `$force`) and records the run; null when nothing ran.
     */
    public function run(bool $force = false): ?PurgeResult
    {
        if (!$force && !$this->isDue()) {
            return null;
        }

        $settings = $this->settings->load();
        $result   = $this->purger->purge($settings->retention, $settings->keepFailed);

        try {
            $item = $this->pool->getItem(self::LAST_RUN_KEY);
            $item->set($this->clock->now()->getTimestamp());
            $this->pool->save($item);
        } catch (Throwable $e) {
            $this->logger?->warning('Messenger monitor automatic purge: the last run could not be recorded.', ['exception' => $e]);
        }

        return $result;
    }

    /**
     * Instant of the last automatic run, or null.
     */
    public function lastRunAt(): ?DateTimeImmutable
    {
        try {
            $item = $this->pool->getItem(self::LAST_RUN_KEY);
        } catch (Throwable) {
            return null;
        }
        $value = $item->isHit() ? $item->get() : null;

        return is_int($value) ? $this->clock->now()->setTimestamp($value) : null;
    }
}
