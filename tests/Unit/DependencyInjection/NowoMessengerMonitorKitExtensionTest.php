<?php

declare(strict_types=1);

namespace Nowo\MessengerMonitorKitBundle\Tests\Unit\DependencyInjection;

use LogicException;
use Nowo\MessengerMonitorKitBundle\Controller\SettingsController;
use Nowo\MessengerMonitorKitBundle\DependencyInjection\NowoMessengerMonitorKitExtension;
use Nowo\MessengerMonitorKitBundle\Entity\ProcessedMessage;
use Nowo\MessengerMonitorKitBundle\Health\QueueDepthProbe;
use Nowo\MessengerMonitorKitBundle\Messenger\FailedTransportDsnEnvVarProcessor;
use Nowo\MessengerMonitorKitBundle\Messenger\StreamOptionsAmqpTransportFactory;
use Nowo\MessengerMonitorKitBundle\Messenger\StreamOptionsDoctrineTransportFactory;
use Nowo\MessengerMonitorKitBundle\NowoMessengerMonitorKitBundle;
use Nowo\MessengerMonitorKitBundle\Security\AllowAllMessengerMonitorAccessChecker;
use Nowo\MessengerMonitorKitBundle\Security\ConfigurableMessengerMonitorAccessChecker;
use Nowo\MessengerMonitorKitBundle\Security\MessengerMonitorAccessCheckerInterface;
use Nowo\MessengerMonitorKitBundle\Settings\CacheMonitorSettingsStore;
use Nowo\MessengerMonitorKitBundle\Settings\ConfigMonitorSettingsStore;
use Nowo\MessengerMonitorKitBundle\Settings\InstanceSettingsMonitorSettingsStore;
use Nowo\MessengerMonitorKitBundle\Settings\MessengerMonitorSettingsSection;
use Nowo\MessengerMonitorKitBundle\Settings\MonitorSettingsStoreInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;

use function count;

final class NowoMessengerMonitorKitExtensionTest extends TestCase
{
    #[Test]
    public function itLoadsTheDefaultServices(): void
    {
        $container = $this->load([]);

        self::assertSame('nowo_messenger_monitor_kit', (new NowoMessengerMonitorKitExtension())->getAlias());
        self::assertSame(CacheMonitorSettingsStore::class, $container->getDefinition(MonitorSettingsStoreInterface::class)->getClass());
        self::assertTrue($container->hasDefinition(SettingsController::class));
        self::assertFalse($container->getDefinition(SettingsController::class)->isAutoconfigured());
        self::assertSame(ConfigurableMessengerMonitorAccessChecker::class, (string) $container->getAlias(MessengerMonitorAccessCheckerInterface::class));
        self::assertFalse($container->hasDefinition(QueueDepthProbe::class));
        self::assertFalse($container->hasDefinition(StreamOptionsAmqpTransportFactory::class));
        self::assertSame('/admin/messenger', $container->getParameter('nowo_messenger_monitor_kit.web_ui.path_prefix'));
    }

    #[Test]
    public function itLoadsTheOptionalIntegrations(): void
    {
        $container = $this->load([
            'settings'            => ['store' => 'instance_settings'],
            'health_probe'        => ['enabled' => true, 'name' => 'queues', 'critical' => true],
            'transport_factories' => ['amqp' => ['enabled' => true, 'prefix' => 'app.'], 'doctrine' => ['enabled' => true], 'failed_dsn_env' => ['enabled' => true]],
            'security'            => ['manage_roles' => ['ROLE_OPS']],
        ]);

        self::assertSame(InstanceSettingsMonitorSettingsStore::class, $container->getDefinition(MonitorSettingsStoreInterface::class)->getClass());
        self::assertTrue($container->getDefinition(MessengerMonitorSettingsSection::class)->hasTag('nowo_instance_settings.section'));
        self::assertSame([['name' => 'queues', 'groups' => ['ready'], 'critical' => true]], $container->getDefinition(QueueDepthProbe::class)->getTag('nowo_health_kit.probe'));
        self::assertSame('app.', $container->getDefinition(StreamOptionsAmqpTransportFactory::class)->getArgument(1));
        self::assertTrue($container->hasDefinition(StreamOptionsDoctrineTransportFactory::class));
        self::assertTrue($container->getDefinition(FailedTransportDsnEnvVarProcessor::class)->hasTag('container.env_var_processor'));
        self::assertSame(['ROLE_OPS'], $container->getDefinition(ConfigurableMessengerMonitorAccessChecker::class)->getArgument(2));
    }

    #[Test]
    public function accessCanBeDelegatedOrOpened(): void
    {
        self::assertSame('app.checker', (string) $this->load(['security' => ['access_checker' => 'app.checker']])->getAlias(MessengerMonitorAccessCheckerInterface::class));
        self::assertSame(AllowAllMessengerMonitorAccessChecker::class, (string) $this->load(['security' => ['allow_unauthenticated' => true]], false)->getAlias(MessengerMonitorAccessCheckerInterface::class));
    }

    #[Test]
    public function theConfigStoreAndADisabledUi(): void
    {
        $container = $this->load(['settings' => ['store' => 'config'], 'web_ui' => ['enabled' => false]], false);
        self::assertSame(ConfigMonitorSettingsStore::class, $container->getDefinition(MonitorSettingsStoreInterface::class)->getClass());
        self::assertFalse($container->hasDefinition(SettingsController::class));
    }

    #[Test]
    public function theUiNeedsSecurity(): void
    {
        $this->expectException(LogicException::class);
        $this->load([], false);
    }

    #[Test]
    public function prependMapsTheBundleEntityUnlessTheHostHasOne(): void
    {
        $container = $this->prepend([]);
        self::assertSame(ProcessedMessage::class, $container->getExtensionConfig('zenstruck_messenger_monitor')[0]['storage']['orm']['entity_class']);
        self::assertArrayHasKey('NowoMessengerMonitorKitBundle', $container->getExtensionConfig('doctrine')[0]['orm']['mappings']);
        self::assertSame(NowoMessengerMonitorKitExtension::formKitProfile(), $container->getExtensionConfig('nowo_form_kit')[0]['profiles'][NowoMessengerMonitorKitBundle::FORM_KIT_PROFILE]);

        $host = $this->prepend([], ['storage' => ['orm' => ['entity_class' => 'App\\Entity\\Row']]], ['profiles' => [NowoMessengerMonitorKitBundle::FORM_KIT_PROFILE => ['alias' => 'mine']]]);
        self::assertCount(1, $host->getExtensionConfig('zenstruck_messenger_monitor'));
        self::assertSame([], $host->getExtensionConfig('doctrine'));
        self::assertCount(1, $host->getExtensionConfig('nowo_form_kit'));

        $off = $this->prepend(['history' => ['bundle_entity' => false]]);
        self::assertSame([], $off->getExtensionConfig('doctrine'));
    }

    #[Test]
    public function optionalIntegrationsNeedTheirPackage(): void
    {
        NowoMessengerMonitorKitExtension::requirePackage(MonitorSettingsStoreInterface::class, 'x', 'y');
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('nowo_messenger_monitor_kit.health_probe.enabled needs vendor/missing (composer require vendor/missing).');
        NowoMessengerMonitorKitExtension::requirePackage('Vendor\\Missing\\ProbeInterface', 'health_probe.enabled', 'vendor/missing');
    }

    #[Test]
    public function prependSkipsMissingExtensions(): void
    {
        $container = new ContainerBuilder();
        (new NowoMessengerMonitorKitExtension())->prepend($container);
        self::assertSame([], $container->getExtensionConfig('doctrine'));
    }

    #[Test]
    public function theBundleUsesItsExtension(): void
    {
        $bundle = new NowoMessengerMonitorKitBundle();
        self::assertInstanceOf(NowoMessengerMonitorKitExtension::class, $bundle->getContainerExtension());
        self::assertSame($bundle->getContainerExtension(), $bundle->getContainerExtension());

        $container = new ContainerBuilder();
        $bundle->build($container);
        self::assertGreaterThanOrEqual(5, count($container->getCompilerPassConfig()->getBeforeOptimizationPasses()));
    }

    /**
     * @param array<string, mixed> $config
     */
    private function load(array $config, bool $security = true): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.bundles', $security ? ['SecurityBundle' => 'Symfony\Bundle\SecurityBundle\SecurityBundle'] : []);
        $container->setParameter('kernel.debug', true);
        (new NowoMessengerMonitorKitExtension())->load([$config], $container);

        return $container;
    }

    /**
     * @param array<string, mixed> $config
     * @param array<string, mixed>|null $zenstruck
     * @param array<string, mixed>|null $formKit
     */
    private function prepend(array $config, ?array $zenstruck = null, ?array $formKit = null): ContainerBuilder
    {
        $container = new ContainerBuilder();
        foreach (['doctrine', 'zenstruck_messenger_monitor', 'nowo_form_kit', 'nowo_messenger_monitor_kit'] as $alias) {
            $container->registerExtension(new class($alias) extends Extension {
                public function __construct(private string $alias)
                {
                }

                public function load(array $configs, ContainerBuilder $container): void
                {
                }

                public function getAlias(): string
                {
                    return $this->alias;
                }
            });
        }
        $container->loadFromExtension('nowo_messenger_monitor_kit', $config);
        if ($zenstruck !== null) {
            $container->loadFromExtension('zenstruck_messenger_monitor', $zenstruck);
        }
        if ($formKit !== null) {
            $container->loadFromExtension('nowo_form_kit', $formKit);
        }
        (new NowoMessengerMonitorKitExtension())->prepend($container);

        return $container;
    }
}
