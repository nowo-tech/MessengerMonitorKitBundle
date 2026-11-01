<?php

declare(strict_types=1);

namespace Nowo\MessengerMonitorKitBundle\Tests\Unit\DependencyInjection;

use Nowo\MessengerMonitorKitBundle\DependencyInjection\Configuration;
use Nowo\MessengerMonitorKitBundle\Payload\KeyPatternRedactor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;

final class ConfigurationTest extends TestCase
{
    #[Test]
    public function defaultsAreSafe(): void
    {
        $config = $this->process([]);

        self::assertTrue($config['history']['bundle_entity']);
        self::assertSame('', $config['doctrine']['table_prefix']);
        self::assertTrue($config['recording']['enabled']);
        self::assertFalse($config['recording']['record_optional']);
        self::assertSame(30, $config['recording']['check_interval']);
        self::assertSame('cache', $config['settings']['store']);
        self::assertSame('cache.app', $config['settings']['cache_pool']);
        self::assertFalse($config['retention']['auto_purge']);
        self::assertSame('1-month', $config['retention']['older_than']);
        self::assertSame(1000, $config['purge']['batch_size']);
        self::assertSame(50, $config['retry']['batch_limit']);
        self::assertNull($config['retry']['fallback_transport']);
        self::assertSame([], $config['failure_transports']);
        self::assertFalse($config['health_probe']['enabled']);
        self::assertTrue($config['payload']['enabled']);
        self::assertSame(KeyPatternRedactor::DEFAULT_KEYS, $config['redaction']['keys']);
        self::assertFalse($config['transport_factories']['amqp']['enabled']);
        self::assertTrue($config['web_ui']['enabled']);
        self::assertSame('/admin/messenger', $config['web_ui']['path_prefix']);
        self::assertNull($config['web_ui']['layout_template']);
        self::assertSame(25, $config['web_ui']['per_page']);
        self::assertSame(['ROLE_ADMIN'], $config['security']['access_roles']);
        self::assertNull($config['security']['manage_roles']);
        self::assertNull($config['security']['access_checker']);
        self::assertFalse($config['security']['allow_unauthenticated']);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalidConfigurations(): iterable
    {
        yield 'table prefix' => [['doctrine' => ['table_prefix' => 'app-']], 'table_prefix'];
        yield 'relative path prefix' => [['web_ui' => ['path_prefix' => 'admin']], 'path_prefix'];
        yield 'root path prefix' => [['web_ui' => ['path_prefix' => '/']], 'path_prefix'];
        yield 'unknown template' => [['web_ui' => ['templates' => ['nope' => 'x.html.twig']]], 'Unknown nowo_messenger_monitor_kit.web_ui.templates'];
        yield 'per page' => [['web_ui' => ['per_page' => 30]], 'per_page'];
        yield 'empty access roles' => [['security' => ['access_roles' => []]], 'access_roles'];
        yield 'blank access roles' => [['security' => ['access_roles' => [' ']]], 'access_roles'];
        yield 'empty manage roles' => [['security' => ['manage_roles' => []]], 'manage_roles'];
        yield 'store' => [['settings' => ['store' => 'redis']], 'store'];
        yield 'retention' => [['retention' => ['older_than' => 'all']], 'older_than'];
        yield 'probe name' => [['health_probe' => ['enabled' => true, 'name' => 'Bad Name']], 'health_probe.name'];
        yield 'pending thresholds' => [['health_probe' => ['enabled' => true, 'pending_warn_at' => 10, 'pending_fail_at' => 5]], 'warn_at'];
        yield 'failed thresholds' => [['health_probe' => ['enabled' => true, 'failed_warn_at' => 10, 'failed_fail_at' => 5]], 'warn_at'];
        yield 'unknown key' => [['nope' => true], 'nope'];
    }

    /**
     * @param array<string, mixed> $config
     */
    #[Test]
    #[DataProvider('invalidConfigurations')]
    public function invalidValuesFailWithTheirPath(array $config, string $message): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage($message);
        $this->process($config);
    }

    #[Test]
    public function customAccessAllowsEmptyRoleLists(): void
    {
        self::assertSame([], $this->process(['security' => ['access_roles' => [], 'access_checker' => 'app.checker']])['security']['access_roles']);
        self::assertSame([], $this->process(['security' => ['access_roles' => [], 'allow_unauthenticated' => true]])['security']['access_roles']);
        self::assertSame(['ROLE_OPS'], $this->process(['security' => ['manage_roles' => ['ROLE_OPS']]])['security']['manage_roles']);
        self::assertSame(['dashboard' => 'a.html.twig'], $this->process(['web_ui' => ['templates' => ['dashboard' => 'a.html.twig']]])['web_ui']['templates']);
    }

    #[Test]
    public function rolesKeepsNonEmptyStrings(): void
    {
        self::assertSame(['ROLE_A', 'ROLE_B'], Configuration::roles([' ROLE_A ', '', 5, 'ROLE_B']));
        self::assertSame([], Configuration::roles('ROLE_A'));
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    private function process(array $config): array
    {
        /** @var array<string, mixed> $processed */
        $processed = (new Processor())->processConfiguration(new Configuration(), [$config]);

        return $processed;
    }
}
