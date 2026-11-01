<?php

declare(strict_types=1);

namespace Nowo\MessengerMonitorKitBundle\Tests\Unit\Ui;

use Nowo\MessengerMonitorKitBundle\Queue\QueueDepthReport;
use Nowo\MessengerMonitorKitBundle\Queue\TransportDepth;
use Nowo\MessengerMonitorKitBundle\Settings\MonitorSettings;
use Nowo\MessengerMonitorKitBundle\Tests\Support\PageFixtures;
use Nowo\MessengerMonitorKitBundle\Ui\MonitorPageFactory;
use Nowo\UiKitBundle\View\ListPage;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\WorkerMetadata;
use Zenstruck\Messenger\Monitor\Worker\WorkerInfo;

final class MonitorPageFactoryTest extends TestCase
{
    #[Test]
    public function theDashboardListsWorkersAndHidesSettingsFromViewers(): void
    {
        $worker = new WorkerInfo(new WorkerMetadata(['transportNames' => ['async'], 'queueNames' => null]), WorkerInfo::PROCESSING, time() - 90, 12, 1_048_576);
        $page   = PageFixtures::factory()->dashboard(4, 1, 10, 20, new QueueDepthReport([new TransportDepth('async', 3), new TransportDepth('failed', 1, true, true)]), [$worker], new MonitorSettings(false, false, true), [], false);

        self::assertSame([['state' => 'processing', 'transports' => 'async', 'handled' => 12, 'memory' => '1.05 MB', 'uptime' => '1 min 30 s']], $page['workers']->rows);
        self::assertSame('warning', $page['page']->kpis[7]->variant);
        self::assertNull($page['page']->kpis[7]->href);
        self::assertCount(3, $page['page']->header->tabs->items ?? []);
    }

    #[Test]
    public function theHistoryIsSortedByFinishTimeNewestFirst(): void
    {
        $page = PageFixtures::history()['page'];
        self::assertInstanceOf(ListPage::class, $page);
        self::assertSame('finished', $page->sort);
        self::assertSame('desc', MonitorPageFactory::historySorting()->direction);
        self::assertTrue($page->columns[0]->isSortable());
    }
}
