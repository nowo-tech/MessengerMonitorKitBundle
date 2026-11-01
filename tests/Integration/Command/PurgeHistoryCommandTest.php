<?php

declare(strict_types=1);

namespace Nowo\MessengerMonitorKitBundle\Tests\Integration\Command;

use Nowo\MessengerMonitorKitBundle\Command\PurgeHistoryCommand;
use Nowo\MessengerMonitorKitBundle\History\AutoPurger;
use Nowo\MessengerMonitorKitBundle\History\HistoryPurger;
use Nowo\MessengerMonitorKitBundle\History\PurgePeriod;
use Nowo\MessengerMonitorKitBundle\Settings\ConfigMonitorSettingsStore;
use Nowo\MessengerMonitorKitBundle\Settings\MonitorSettings;
use Nowo\MessengerMonitorKitBundle\Settings\MonitorSettingsStoreInterface;
use Nowo\MessengerMonitorKitBundle\Tests\Integration\Kernel\KernelTestCase;
use Nowo\MessengerMonitorKitBundle\Tests\Support\Seeder;
use PHPUnit\Framework\Attributes\Test;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;

final class PurgeHistoryCommandTest extends KernelTestCase
{
    #[Test]
    public function aBookkeepingErrorDoesNotFailThePurge(): void
    {
        $kernel    = $this->bootKernel();
        $container = $this->container($kernel);
        Seeder::history($container);
        $purger = $container->get(HistoryPurger::class);
        self::assertInstanceOf(HistoryPurger::class, $purger);
        $pool = $this->createStub(CacheItemPoolInterface::class);
        $pool->method('getItem')->willThrowException(new RuntimeException('down'));

        $auto = new AutoPurger($purger, new ConfigMonitorSettingsStore(new MonitorSettings(autoPurge: true, retention: PurgePeriod::All)), $pool, new MockClock(), 60, new NullLogger());
        self::assertSame(1, $auto->run()?->deleted);
    }

    #[Test]
    public function itPurgesManuallyAndAutomatically(): void
    {
        $kernel    = $this->bootKernel();
        $container = $this->container($kernel);
        Seeder::history($container);
        Seeder::history($container, failure: 'boom');
        $tester = new CommandTester((new Application($kernel))->find(PurgeHistoryCommand::NAME));

        self::assertSame(2, $tester->execute(['--older-than' => 'nope']));
        self::assertStringContainsString('Invalid --older-than', $tester->getDisplay());

        self::assertSame(0, $tester->execute(['--older-than' => 'all']));
        self::assertStringContainsString('1 history row(s) purged', $tester->getDisplay());
        self::assertSame(0, $tester->execute(['--older-than' => 'all', '--include-failed' => true]));
        self::assertStringContainsString('1 history row(s) purged', $tester->getDisplay());

        self::assertSame(0, $tester->execute(['--auto' => true], ['verbosity' => OutputInterface::VERBOSITY_VERBOSE]));
        self::assertStringContainsString('not due', $tester->getDisplay());

        $store = $container->get(MonitorSettingsStoreInterface::class);
        self::assertInstanceOf(MonitorSettingsStoreInterface::class, $store);
        $store->save(new MonitorSettings(autoPurge: true));
        Seeder::history($container);
        self::assertSame(0, $tester->execute(['--auto' => true]));
        self::assertStringContainsString('(automatic retention)', $tester->getDisplay());
        self::assertSame(0, $tester->execute(['--auto' => true], ['verbosity' => OutputInterface::VERBOSITY_VERBOSE]));
        self::assertStringContainsString('not due', $tester->getDisplay(), 'the interval has not elapsed');
        self::assertSame(0, $tester->execute(['--auto' => true, '--force' => true]));
        self::assertStringContainsString('(automatic retention)', $tester->getDisplay());
    }
}
