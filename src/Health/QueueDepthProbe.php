<?php

declare(strict_types=1);

namespace Nowo\MessengerMonitorKitBundle\Health;

use Nowo\HealthKitBundle\Probe\HealthProbeInterface;
use Nowo\HealthKitBundle\Probe\ProbeResult;
use Nowo\MessengerMonitorKitBundle\Queue\QueueDepth;

use function implode;
use function sprintf;

/**
 * HealthKit probe fed by {@see QueueDepth} (`health_probe.enabled`, needs nowo-tech/health-kit-bundle): pending and
 * failed message totals against the configured thresholds; the per-transport counts go into the probe data (shown
 * only to HealthKit detail callers).
 */
final readonly class QueueDepthProbe implements HealthProbeInterface
{
    /**
     * @param int|null $pendingWarnAt degraded when pending >= this
     * @param int|null $pendingFailAt error when pending >= this
     * @param int|null $failedWarnAt degraded when failed >= this
     * @param int|null $failedFailAt error when failed >= this
     */
    public function __construct(
        private QueueDepth $queueDepth,
        private ?int $pendingWarnAt = null,
        private ?int $pendingFailAt = null,
        private ?int $failedWarnAt = 1,
        private ?int $failedFailAt = null,
    ) {
    }

    /**
     * Counts the queues (the broker calls are short; the HealthKit timeout bounds the whole probe).
     */
    public function check(float $timeout): ProbeResult
    {
        $report  = $this->queueDepth->report();
        $pending = $report->pending();
        $failed  = $report->failed();
        $data    = ['pending' => $pending, 'failed' => $failed];
        foreach ($report->toArray() as $name => $count) {
            $data['transport.' . $name] = $count;
        }

        if ($pending === null && $failed === null) {
            return ProbeResult::degraded('No transport could report its message count.', $data);
        }

        $errors   = [];
        $warnings = [];
        $this->compare('pending', $pending, $this->pendingWarnAt, $this->pendingFailAt, $warnings, $errors);
        $this->compare('failed', $failed, $this->failedWarnAt, $this->failedFailAt, $warnings, $errors);

        if ($errors !== []) {
            return ProbeResult::error(implode(' ', $errors), $data);
        }
        if ($warnings !== []) {
            return ProbeResult::degraded(implode(' ', $warnings), $data);
        }

        return ProbeResult::ok($data);
    }

    /**
     * @param list<string> $warnings
     * @param list<string> $errors
     */
    private function compare(string $kind, ?int $count, ?int $warnAt, ?int $failAt, array &$warnings, array &$errors): void
    {
        if ($count === null) {
            return;
        }
        if ($failAt !== null && $count >= $failAt) {
            $errors[] = sprintf('%d %s messages (fail threshold %d).', $count, $kind, $failAt);
        } elseif ($warnAt !== null && $count >= $warnAt) {
            $warnings[] = sprintf('%d %s messages (warning threshold %d).', $count, $kind, $warnAt);
        }
    }
}
