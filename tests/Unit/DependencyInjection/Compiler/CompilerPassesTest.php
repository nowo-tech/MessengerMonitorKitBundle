<?php

declare(strict_types=1);

namespace Nowo\MessengerMonitorKitBundle\Tests\Unit\DependencyInjection\Compiler;

use LogicException;
use Nowo\MessengerMonitorKitBundle\Controller\SettingsController;
use Nowo\MessengerMonitorKitBundle\DependencyInjection\Compiler\CsrfProtectionPass;
use Nowo\MessengerMonitorKitBundle\DependencyInjection\Compiler\HistoryStoragePass;
use Nowo\MessengerMonitorKitBundle\DependencyInjection\Compiler\MessengerIntegrationPass;
use Nowo\MessengerMonitorKitBundle\DependencyInjection\Compiler\TwigPathsPass;
use Nowo\MessengerMonitorKitBundle\DependencyInjection\Compiler\UnauthenticatedAccessPass;
use Nowo\MessengerMonitorKitBundle\Entity\ProcessedMessage;
use Nowo\MessengerMonitorKitBundle\History\AutoPurger;
use Nowo\MessengerMonitorKitBundle\History\HistoryPurger;
use Nowo\MessengerMonitorKitBundle\Recording\ToggleableMonitorStorage;
use Nowo\MessengerMonitorKitBundle\Retry\NoRetryOnDecodingFailureRetryStrategy;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Twig\Loader\FilesystemLoader;

use function sys_get_temp_dir;

final class CompilerPassesTest extends TestCase
{
    #[Test]
    public function twigPathsPrependTheOverrideDirectory(): void
    {
        $project = sys_get_temp_dir() . '/mmk-twig-pass';
        @mkdir($project . '/templates/bundles/NowoMessengerMonitorKitBundle', 0o777, true);
        $container = new ContainerBuilder();
        $container->setParameter('kernel.project_dir', $project);
        $container->setDefinition('twig.loader.native_filesystem', new Definition(FilesystemLoader::class));
        $container->setAlias('twig.loader.native', 'twig.loader.native_filesystem');

        (new TwigPathsPass())->process($container);

        $calls = $container->getDefinition('twig.loader.native_filesystem')->getMethodCalls();
        self::assertSame('prependPath', $calls[0][0]);
        self::assertSame([$project . '/templates/bundles/NowoMessengerMonitorKitBundle', 'NowoMessengerMonitorKitBundle'], $calls[0][1]);
        self::assertSame('addPath', $calls[1][0]);
        self::assertStringEndsWith('/Resources/views', (string) $calls[1][1][0]);

        $withoutOverride = new ContainerBuilder();
        $withoutOverride->setDefinition('twig.loader.native_filesystem', new Definition(FilesystemLoader::class));
        (new TwigPathsPass())->process($withoutOverride);
        self::assertCount(1, $withoutOverride->getDefinition('twig.loader.native_filesystem')->getMethodCalls());

        $noTwig = new ContainerBuilder();
        (new TwigPathsPass())->process($noTwig);
        self::assertFalse($noTwig->hasDefinition('twig.loader.native_filesystem'));
    }

    #[Test]
    public function csrfFailsClosed(): void
    {
        $container = new ContainerBuilder();
        (new CsrfProtectionPass())->process($container);

        $container->setDefinition(SettingsController::class, new Definition(SettingsController::class));
        $container->setDefinition('security.csrf.token_manager', new Definition('stdClass'));
        $container->setDefinition('form.type_extension.csrf', new Definition('stdClass'));
        (new CsrfProtectionPass())->process($container);

        $container->setParameter('form.type_extension.csrf.enabled', false);
        $this->expectException(LogicException::class);
        (new CsrfProtectionPass())->process($container);
    }

    #[Test]
    public function csrfFailsWithoutTheTokenManager(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition(SettingsController::class, new Definition(SettingsController::class));
        $this->expectException(LogicException::class);
        (new CsrfProtectionPass())->process($container);
    }

    #[Test]
    public function unauthenticatedAccessNeedsDebug(): void
    {
        $container = new ContainerBuilder();
        (new UnauthenticatedAccessPass())->process($container);
        $container->setParameter(UnauthenticatedAccessPass::PARAMETER, true);
        $container->setParameter('kernel.debug', true);
        (new UnauthenticatedAccessPass())->process($container);

        $container->setParameter('kernel.debug', false);
        $this->expectException(InvalidConfigurationException::class);
        (new UnauthenticatedAccessPass())->process($container);
    }

    #[Test]
    public function historyStorageIsDecoratedWithTheEntityClass(): void
    {
        $container = $this->historyContainer();
        $container->setDefinition(HistoryStoragePass::STORAGE_ID, new Definition('stdClass', ['doctrine', ProcessedMessage::class]));
        (new HistoryStoragePass())->process($container);

        self::assertSame(ProcessedMessage::class, $container->getDefinition(HistoryPurger::class)->getArgument(1));
        self::assertSame([HistoryStoragePass::STORAGE_ID, null, 0], $container->getDefinition(ToggleableMonitorStorage::class)->getDecoratedService());

        $none = new ContainerBuilder();
        (new HistoryStoragePass())->process($none);
        self::assertFalse($none->hasDefinition(HistoryPurger::class));
    }

    #[Test]
    public function historyServicesAreRemovedWithoutStorageOrRefusedWithTheUi(): void
    {
        $container = $this->historyContainer();
        (new HistoryStoragePass())->process($container);
        self::assertFalse($container->hasDefinition(HistoryPurger::class));
        self::assertFalse($container->hasDefinition(AutoPurger::class));

        $ui = $this->historyContainer();
        $ui->setDefinition(SettingsController::class, new Definition(SettingsController::class));
        $this->expectException(LogicException::class);
        (new HistoryStoragePass())->process($ui);
    }

    #[Test]
    public function decodingFailureStrategiesDecorateTheTransportStrategy(): void
    {
        $container = new ContainerBuilder();
        (new MessengerIntegrationPass())->process($container);

        $container->setParameter(MessengerIntegrationPass::NO_RETRY_PARAMETER, ['async']);
        $container->setParameter(MessengerIntegrationPass::FACTORIES_PARAMETER, ['doctrine' => 'messenger.transport.doctrine.factory']);
        $container->setDefinition('messenger.retry.multiplier_retry_strategy.async', new Definition('stdClass'));
        $container->setDefinition('messenger.transport.doctrine.factory', new Definition('stdClass'));
        (new MessengerIntegrationPass())->process($container);

        $decorator = $container->getDefinition('nowo_messenger_monitor_kit.no_retry_on_decoding_failure.async');
        self::assertSame(NoRetryOnDecodingFailureRetryStrategy::class, $decorator->getClass());
        self::assertSame('messenger.retry.multiplier_retry_strategy.async', $decorator->getDecoratedService()[0] ?? null);
    }

    #[Test]
    public function anUnknownRetryStrategyTransportFails(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter(MessengerIntegrationPass::NO_RETRY_PARAMETER, ['nope']);
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('transport "nope"');
        (new MessengerIntegrationPass())->process($container);
    }

    #[Test]
    public function aMissingWrappedFactoryFails(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter(MessengerIntegrationPass::FACTORIES_PARAMETER, ['amqp' => 'messenger.transport.amqp.factory']);
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('symfony/amqp-messenger');
        (new MessengerIntegrationPass())->process($container);
    }

    private function historyContainer(): ContainerBuilder
    {
        $container = new ContainerBuilder();
        foreach (HistoryStoragePass::HISTORY_SERVICES as $id) {
            $container->setDefinition($id, new Definition($id, ['inner', 'entity']));
        }

        return $container;
    }
}
