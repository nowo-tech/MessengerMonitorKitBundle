<?php

declare(strict_types=1);

namespace Nowo\MessengerMonitorKitBundle\Ui;

use DateTimeInterface;

use function intdiv;
use function max;
use function number_format;
use function round;
use function rtrim;
use function sprintf;

/**
 * Formatting helpers of the admin pages (also the Twig filter `nowo_messenger_monitor_duration`). Pure functions.
 */
final class MonitorFormat
{
    /** Date format of the list cells and detail values. */
    public const DATE_FORMAT = 'Y-m-d H:i:s';

    /**
     * Compact duration of milliseconds: `850 ms`, `1.5 s`, `2 min 5 s`, `3 h 10 min`, `2 d 4 h`.
     */
    public static function duration(int|float|null $milliseconds): string
    {
        $ms = max(0, (int) round((float) $milliseconds));
        if ($ms < 1000) {
            return $ms . ' ms';
        }
        if ($ms < 60_000) {
            return rtrim(rtrim(number_format($ms / 1000, 1, '.', ''), '0'), '.') . ' s';
        }

        $seconds = intdiv($ms, 1000);
        if ($seconds < 3600) {
            return sprintf('%d min %d s', intdiv($seconds, 60), $seconds % 60);
        }
        if ($seconds < 86_400) {
            return sprintf('%d h %d min', intdiv($seconds, 3600), intdiv($seconds % 3600, 60));
        }

        return sprintf('%d d %d h', intdiv($seconds, 86_400), intdiv($seconds % 86_400, 3600));
    }

    /**
     * Date of a list cell / detail value, or null.
     */
    public static function date(?DateTimeInterface $date): ?string
    {
        return $date?->format(self::DATE_FORMAT);
    }

    /**
     * Failure rate in percent with one decimal (`12.5 %`), `0 %` without messages.
     */
    public static function rate(int $failures, int $total): string
    {
        if ($total <= 0) {
            return '0 %';
        }

        return rtrim(rtrim(number_format($failures / $total * 100, 1, '.', ''), '0'), '.') . ' %';
    }
}
