<?php

declare(strict_types=1);

namespace Nowo\MessengerMonitorKitBundle\Tests\Integration\Kernel;

use Closure;
use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Nowo\FormKitBundle\NowoFormKitBundle;
use Nowo\MessengerMonitorKitBundle\NowoMessengerMonitorKitBundle;
use Nowo\MessengerMonitorKitBundle\Tests\Fixtures\DemoMessage;
use Nowo\MessengerMonitorKitBundle\Tests\Fixtures\OptionalMessage;
use Nowo\UiKitBundle\NowoUiKitBundle;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use Twig\Extra\TwigExtraBundle\TwigExtraBundle;
use Zenstruck\Messenger\Monitor\ZenstruckMessengerMonitorBundle;

use function dirname;
use function in_array;
use function sys_get_temp_dir;

/**
 * Kernel of the integration tests: Framework (Messenger with Doctrine transports `async` / `failed` on SQLite, lock),
 * Security (http_basic, in-memory users), Twig + Twig Extra, Doctrine on a SQLite file, zenstruck monitor, FormKit,
 * UiKit and the bundle. Configuration and routes are injected per test.
 */
final class MonitorTestKernel extends Kernel
{
    use MicroKernelTrait;

    /**
     * @param Closure(ContainerConfigurator): void $services extension configs and services
     * @param Closure(RoutingConfigurator): void $routes routes
     * @param list<string> $without bundle short names to leave out (security)
     */
    public function __construct(
        private readonly string $id,
        private readonly Closure $services,
        private readonly Closure $routes,
        private readonly array $without = [],
        bool $debug = false,
    ) {
        parent::__construct('test', $debug);
    }

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        if (!in_array('security', $this->without, true)) {
            yield new SecurityBundle();
        }
        yield new TwigBundle();
        yield new TwigExtraBundle();
        yield new DoctrineBundle();
        yield new ZenstruckMessengerMonitorBundle();
        yield new NowoFormKitBundle();
        yield new NowoUiKitBundle();
        yield new NowoMessengerMonitorKitBundle();
    }

    public function getProjectDir(): string
    {
        return dirname(__DIR__, 3);
    }

    public function getCacheDir(): string
    {
        return self::baseDir() . '/' . $this->id . '/cache';
    }

    public function getLogDir(): string
    {
        return self::baseDir() . '/' . $this->id . '/log';
    }

    public static function baseDir(): string
    {
        return sys_get_temp_dir() . '/nowo-messenger-monitor-kit-tests';
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', [
            'test'                  => true,
            'secret'                => 'test-secret',
            'http_method_override'  => false,
            'handle_all_throwables' => true,
            'php_errors'            => ['log' => true],
            'csrf_protection'       => true,
            'form'                  => ['csrf_protection' => true],
            'validation'            => true,
            'lock'                  => 'flock',
            'session'               => ['storage_factory_id' => 'session.storage.factory.mock_file'],
            'translator'            => ['default_path' => '%kernel.project_dir%/tests/Fixtures/translations', 'fallbacks' => ['en']],
            'router'                => ['utf8' => true],
            'messenger'             => [
                'failure_transport' => 'failed',
                'transports'        => [
                    'async'  => ['dsn' => 'doctrine://default?queue_name=async', 'retry_strategy' => ['max_retries' => 0]],
                    'failed' => 'doctrine://default?queue_name=failed',
                    'sync'   => 'sync://',
                ],
                'routing' => [DemoMessage::class => 'async', OptionalMessage::class => 'async'],
            ],
        ]);
        $container->extension('twig', ['strict_variables' => true]);
        $container->extension('doctrine', [
            'dbal' => ['url' => 'sqlite:///' . self::baseDir() . '/' . $this->id . '/db.sqlite'],
            'orm'  => [],
        ]);
        if (!in_array('security', $this->without, true)) {
            $container->extension('security', [
                'password_hashers' => ['Symfony\Component\Security\Core\User\InMemoryUser' => 'plaintext'],
                'providers'        => ['memory' => ['memory' => ['users' => [
                    'admin'  => ['password' => 'admin', 'roles' => ['ROLE_ADMIN']],
                    'ops'    => ['password' => 'ops', 'roles' => ['ROLE_ADMIN', 'ROLE_OPS']],
                    'viewer' => ['password' => 'viewer', 'roles' => ['ROLE_USER']],
                ]]]],
                'firewalls' => ['main' => ['lazy' => true, 'http_basic' => true]],
            ]);
        }
        $container->services()->set('logger', NullLogger::class);
        ($this->services)($container);
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        ($this->routes)($routes);
    }
}
