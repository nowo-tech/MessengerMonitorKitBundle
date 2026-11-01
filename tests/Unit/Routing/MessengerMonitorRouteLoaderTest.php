<?php

declare(strict_types=1);

namespace Nowo\MessengerMonitorKitBundle\Tests\Unit\Routing;

use Nowo\MessengerMonitorKitBundle\Routing\MessengerMonitorRouteLoader;
use Nowo\MessengerMonitorKitBundle\Routing\MessengerMonitorRoutes;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;

use function array_values;

final class MessengerMonitorRouteLoaderTest extends TestCase
{
    #[Test]
    public function itLoadsEveryRouteUnderThePrefix(): void
    {
        $loader = new MessengerMonitorRouteLoader(true, '/ops/messenger/');
        self::assertTrue($loader->supports('.', MessengerMonitorRouteLoader::TYPE));
        self::assertFalse($loader->supports('.', 'attribute'));

        $routes = $loader->load('.', MessengerMonitorRouteLoader::TYPE);
        self::assertSame(array_values((new ReflectionClass(MessengerMonitorRoutes::class))->getConstants()), array_keys($routes->all()));
        $dashboard = $routes->get(MessengerMonitorRoutes::DASHBOARD);
        $retry     = $routes->get(MessengerMonitorRoutes::RETRY);
        self::assertNotNull($dashboard);
        self::assertNotNull($retry);
        self::assertSame('/ops/messenger', $dashboard->getPath());
        self::assertSame('/ops/messenger/transports/{name}/messages/{id}/retry', $retry->getPath());
        self::assertSame(['POST'], $retry->getMethods());

        $this->expectException(RuntimeException::class);
        $loader->load('.', MessengerMonitorRouteLoader::TYPE);
    }

    #[Test]
    public function itLoadsNothingWhenTheUiIsOff(): void
    {
        self::assertCount(0, (new MessengerMonitorRouteLoader(false, '/admin/messenger'))->load('.', MessengerMonitorRouteLoader::TYPE));
    }
}
