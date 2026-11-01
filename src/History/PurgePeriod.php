<?php

declare(strict_types=1);

namespace Nowo\MessengerMonitorKitBundle\History;

use DateTimeImmutable;

/**
 * "Delete history older than …" choices of the manual purge, the automatic purge (retention) and the CLI.
 */
enum PurgePeriod: string
{
    case OneHour     = '1-hour';
    case OneDay      = '1-day';
    case OneWeek     = '1-week';
    case OneMonth    = '1-month';
    case ThreeMonths = '3-months';
    case SixMonths   = '6-months';
    case OneYear     = '1-year';
    case All         = 'all';

    /**
     * Rows finished at or before this instant are purged; null = every row.
     */
    public function cutoff(DateTimeImmutable $now): ?DateTimeImmutable
    {
        return match ($this) {
            self::OneHour     => $now->modify('-1 hour'),
            self::OneDay      => $now->modify('-1 day'),
            self::OneWeek     => $now->modify('-7 days'),
            self::OneMonth    => $now->modify('-1 month'),
            self::ThreeMonths => $now->modify('-3 months'),
            self::SixMonths   => $now->modify('-6 months'),
            self::OneYear     => $now->modify('-1 year'),
            self::All         => null,
        };
    }

    /**
     * Periods offered as retention of the automatic purge ("all" would wipe the history on every run, "1 hour" defeats
     * the purpose of a history).
     *
     * @return list<self>
     */
    public static function retentionCases(): array
    {
        return [self::OneDay, self::OneWeek, self::OneMonth, self::ThreeMonths, self::SixMonths, self::OneYear];
    }

    /**
     * Translation key of the period label (bundle domain).
     */
    public function translationKey(): string
    {
        return 'period.' . $this->value;
    }
}
