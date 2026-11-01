<?php

declare(strict_types=1);

namespace Nowo\MessengerMonitorKitBundle\Tests\Unit\History;

use DateTimeImmutable;
use Nowo\MessengerMonitorKitBundle\History\PurgePeriod;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PurgePeriodTest extends TestCase
{
    #[Test]
    public function cutoffsAndRetentionCases(): void
    {
        $now      = new DateTimeImmutable('2026-10-31 12:00:00');
        $expected = [
            '1-hour'   => '2026-10-31 11:00:00',
            '1-day'    => '2026-10-30 12:00:00',
            '1-week'   => '2026-10-24 12:00:00',
            '1-month'  => '2026-10-01 12:00:00',
            '3-months' => '2026-07-31 12:00:00',
            '6-months' => '2026-05-01 12:00:00',
            '1-year'   => '2025-10-31 12:00:00',
        ];
        foreach ($expected as $value => $cutoff) {
            self::assertSame($cutoff, PurgePeriod::from($value)->cutoff($now)?->format('Y-m-d H:i:s'));
        }
        self::assertNull(PurgePeriod::All->cutoff($now));
        self::assertNotContains(PurgePeriod::All, PurgePeriod::retentionCases());
        self::assertNotContains(PurgePeriod::OneHour, PurgePeriod::retentionCases());
        self::assertSame('period.1-week', PurgePeriod::OneWeek->translationKey());
    }
}
