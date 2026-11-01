<?php

declare(strict_types=1);

namespace Nowo\MessengerMonitorKitBundle\Tests\Integration\Kernel;

use Closure;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\MessengerMonitorKitBundle\Tests\Fixtures\DemoMessageHandler;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

use function count;
use function preg_replace;

/**
 * Boots a fresh {@see MonitorTestKernel} per call (own cache dir and SQLite file, schema created).
 */
abstract class KernelTestCase extends TestCase
{
    /** @var list<MonitorTestKernel> */
    private array $kernels = [];

    protected function tearDown(): void
    {
        foreach ($this->kernels as $kernel) {
            $kernel->shutdown();
        }
        $this->kernels = [];
        (new Filesystem())->remove(MonitorTestKernel::baseDir());
    }

    /**
     * @param array<string, mixed> $config nowo_messenger_monitor_kit configuration
     * @param array<string, string> $services extra service id => class (autoconfigured, public)
     * @param list<string> $without bundles to leave out
     * @param Closure(RoutingConfigurator): void|null $routes routes (default: the bundle loader)
     * @param array<string, array<string, mixed>> $extensions extra extension configs
     */
    protected function bootKernel(array $config = [], array $services = [], array $without = [], ?Closure $routes = null, bool $debug = false, array $extensions = []): MonitorTestKernel
    {
        $id     = (string) preg_replace('/\W+/', '_', static::class . '_' . $this->name() . '_' . count($this->kernels));
        $kernel = new MonitorTestKernel(
            $id,
            static function (ContainerConfigurator $container) use ($config, $services, $extensions): void {
                $container->extension('nowo_messenger_monitor_kit', $config);
                foreach ($extensions as $name => $extension) {
                    $container->extension($name, $extension);
                }
                $defaults = $container->services()->defaults()->autowire()->autoconfigure();
                $defaults->set(DemoMessageHandler::class)->public();
                foreach ($services as $serviceId => $class) {
                    $defaults->set($serviceId, $class)->public();
                }
            },
            $routes ?? static function (RoutingConfigurator $routes): void {
                $routes->import('.', 'nowo_messenger_monitor_kit');
            },
            $without,
            $debug,
        );
        $this->kernels[] = $kernel;
        $kernel->boot();

        $doctrine = $kernel->getContainer()->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $doctrine);
        $entityManager = $doctrine->getManager();
        if ($entityManager instanceof EntityManagerInterface) {
            (new SchemaTool($entityManager))->createSchema($entityManager->getMetadataFactory()->getAllMetadata());
        }

        return $kernel;
    }

    protected function container(MonitorTestKernel $kernel): ContainerInterface
    {
        /** @var ContainerInterface $container */
        $container = $kernel->getContainer()->get('test.service_container');

        return $container;
    }

    /**
     * Browser that keeps the session (CSRF tokens) and the database between requests.
     */
    protected function client(MonitorTestKernel $kernel, ?string $user = 'admin'): KernelBrowser
    {
        /** @var KernelBrowser $client */
        $client = $kernel->getContainer()->get('test.client');
        $client->disableReboot();
        $client->catchExceptions(true);
        $client->setServerParameters($user === null ? ['REMOTE_ADDR' => '203.0.113.9'] : ['REMOTE_ADDR' => '203.0.113.9', 'PHP_AUTH_USER' => $user, 'PHP_AUTH_PW' => $user]);

        return $client;
    }

    protected function connection(MonitorTestKernel $kernel): Connection
    {
        $connection = $this->container($kernel)->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $connection);

        return $connection;
    }
}
