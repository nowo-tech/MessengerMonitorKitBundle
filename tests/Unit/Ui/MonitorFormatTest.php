<?php

declare(strict_types=1);

namespace Nowo\MessengerMonitorKitBundle\Tests\Unit\Ui;

use DateTimeImmutable;
use Nowo\MessengerMonitorKitBundle\Twig\MessengerMonitorTwigExtension;
use Nowo\MessengerMonitorKitBundle\Ui\MonitorFormat;
use Nowo\MessengerMonitorKitBundle\Ui\MonitorPages;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MonitorFormatTest extends TestCase
{
    #[Test]
    public function durationsDatesAndRates(): void
    {
        self::assertSame('0 ms', MonitorFormat::duration(null));
        self::assertSame('850 ms', MonitorFormat::duration(850));
        self::assertSame('1.5 s', MonitorFormat::duration(1500));
        self::assertSame('2 s', MonitorFormat::duration(2000));
        self::assertSame('2 min 5 s', MonitorFormat::duration(125_000));
        self::assertSame('3 h 10 min', MonitorFormat::duration(11_400_000));
        self::assertSame('2 d 4 h', MonitorFormat::duration(187_200_000));
        self::assertNull(MonitorFormat::date(null));
        self::assertSame('2026-10-31 12:00:00', MonitorFormat::date(new DateTimeImmutable('2026-10-31 12:00:00')));
        self::assertSame('0 %', MonitorFormat::rate(1, 0));
        self::assertSame('12.5 %', MonitorFormat::rate(1, 8));
        self::assertSame('50 %', MonitorFormat::rate(1, 2));
    }

    #[Test]
    public function theTwigFilterAndPages(): void
    {
        $filters = (new MessengerMonitorTwigExtension())->getFilters();
        self::assertSame('nowo_messenger_monitor_duration', $filters[0]->getName());

        $pages = new MonitorPages('layouts/admin.html.twig', ['history' => 'custom/history.html.twig']);
        self::assertSame('custom/history.html.twig', $pages->template('history'));
        self::assertSame(MonitorPages::TEMPLATES['dashboard'], $pages->template('dashboard'));
        self::assertSame('unknown', $pages->template('unknown'));
        self::assertSame('layouts/admin.html.twig', $pages->layout());
        self::assertNull((new MonitorPages(''))->layout());
        self::assertSame(['%x%' => 1], MonitorPages::label('k', ['%x%' => 1])->params);
    }
}
