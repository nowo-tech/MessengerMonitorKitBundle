<?php

declare(strict_types=1);

namespace Nowo\MessengerMonitorKitBundle\Tests\Integration\Routing;

use Nowo\MessengerMonitorKitBundle\Controller\DashboardController;
use Nowo\MessengerMonitorKitBundle\Routing\MessengerMonitorRouteLoader;
use Nowo\MessengerMonitorKitBundle\Tests\Integration\Kernel\KernelTestCase;
use Symfony\Component\Routing\Loader\AttributeServicesLoader;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use Symfony\Component\Routing\RouterInterface;

use function class_exists;
use function is_string;
use function str_starts_with;

/**
 * REQ-SF-004 rule 7: a host `routing.controllers` import never mounts the bundle routes.
 */
final class RoutesOptInTest extends KernelTestCase
{
    public function testRoutingControllersDoesNotMountTheBundleRoutes(): void
    {
        if (!class_exists(AttributeServicesLoader::class)) {
            self::markTestSkipped('routing.controllers requires symfony/routing >= 7.3.');
        }

        $kernel = $this->bootKernel(routes: static function (RoutingConfigurator $routes): void {
            $routes->import('routing.controllers');
        });
        self::assertTrue($this->container($kernel)->has(DashboardController::class));

        /** @var RouterInterface $router */
        $router = $kernel->getContainer()->get('router');
        foreach ($router->getRouteCollection()->all() as $name => $route) {
            self::assertStringStartsNotWith('nowo_messenger_monitor_kit', $name);
            $controller = $route->getDefault('_controller');
            foreach (MessengerMonitorRouteLoader::CONTROLLERS as $class) {
                self::assertFalse(is_string($controller) && str_starts_with($controller, $class), $name);
            }
        }
    }

    public function testTheLoaderImportMountsThemUnderThePrefix(): void
    {
        $kernel = $this->bootKernel(['web_ui' => ['path_prefix' => '/ops/queues']]);
        /** @var RouterInterface $router */
        $router = $kernel->getContainer()->get('router');

        self::assertSame('/ops/queues/history/{id}', $router->getRouteCollection()->get('nowo_messenger_monitor_kit_history_show')?->getPath());
    }
}
