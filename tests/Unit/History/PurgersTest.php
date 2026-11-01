<?php

declare(strict_types=1);

namespace Nowo\MessengerMonitorKitBundle\Tests\Unit\History;

use Doctrine\Persistence\ManagerRegistry;
use LogicException;
use Nowo\MessengerMonitorKitBundle\Entity\ProcessedMessage;
use Nowo\MessengerMonitorKitBundle\History\AutoPurger;
use Nowo\MessengerMonitorKitBundle\History\HistoryPurger;
use Nowo\MessengerMonitorKitBundle\History\PurgePeriod;
use Nowo\MessengerMonitorKitBundle\Settings\ConfigMonitorSettingsStore;
use Nowo\MessengerMonitorKitBundle\Settings\MonitorSettings;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;

final class PurgersTest extends TestCase
{
    #[Test]
    public function thePurgerNeedsAnOrmManager(): void
    {
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn(null);

        $this->expectException(LogicException::class);
        (new HistoryPurger($registry, ProcessedMessage::class, new MockClock()))->purge(PurgePeriod::All, false);
    }

    #[Test]
    public function theAutoPurgerToleratesCacheErrors(): void
    {
        $pool = $this->createStub(CacheItemPoolInterface::class);
        $pool->method('getItem')->willThrowException(new RuntimeException('down'));
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn(null);
        $auto = new AutoPurger(new HistoryPurger($registry, ProcessedMessage::class, new MockClock()), new ConfigMonitorSettingsStore(new MonitorSettings(autoPurge: true)), $pool, new MockClock(), 3600, new NullLogger());

        self::assertNull($auto->lastRunAt());
        self::assertTrue($auto->isDue());
        $this->expectException(LogicException::class);
        $auto->run();
    }

    #[Test]
    public function theLastRunIsReadBack(): void
    {
        $pool  = new ArrayAdapter();
        $clock = new MockClock('2026-10-31 12:00:00');
        $item  = $pool->getItem(AutoPurger::LAST_RUN_KEY);
        $pool->save($item->set($clock->now()->getTimestamp()));
        $registry = $this->createStub(ManagerRegistry::class);
        $auto     = new AutoPurger(new HistoryPurger($registry, ProcessedMessage::class, $clock), new ConfigMonitorSettingsStore(new MonitorSettings(autoPurge: true)), $pool, $clock, 60);

        self::assertSame('2026-10-31 12:00:00', $auto->lastRunAt()?->format('Y-m-d H:i:s'));
        self::assertFalse($auto->isDue());
        $clock->sleep(60);
        self::assertTrue($auto->isDue());
    }
}
