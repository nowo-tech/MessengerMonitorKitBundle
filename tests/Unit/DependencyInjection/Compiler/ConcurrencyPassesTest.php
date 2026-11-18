<?php

declare(strict_types=1);

namespace Nowo\MessengerMonitorKitBundle\Tests\Unit\DependencyInjection\Compiler;

use Nowo\MessengerMonitorKitBundle\DependencyInjection\Compiler\ConflictResponsePass;
use Nowo\MessengerMonitorKitBundle\DependencyInjection\Compiler\RetryLockPass;
use Nowo\MessengerMonitorKitBundle\EventListener\MessengerMonitorConflictListener;
use Nowo\TransactionDoctrine\Symfony\Conflict\ConflictResponsePolicy;
use Nowo\TransactionDoctrine\Symfony\EventListener\ConcurrencyConflictResponder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Argument\TaggedIteratorArgument;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Exception\InvalidArgumentException;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;

final class ConcurrencyPassesTest extends TestCase
{
    #[Test]
    public function withoutTheListenerTheConflictPassDoesNothing(): void
    {
        $container = new ContainerBuilder();
        (new ConflictResponsePass())->process($container);
        self::assertFalse($container->hasDefinition(ConflictResponsePass::OWN_POLICY));
    }

    #[Test]
    public function withoutTransactionDoctrineBundleTheListenerGetsItsOwnPolicyAndAPlainResponder(): void
    {
        $container = $this->withListener();
        (new ConflictResponsePass())->process($container);

        self::assertSame([ConflictResponsePolicy::STATUS_CONFLICT], $container->getDefinition(ConflictResponsePass::OWN_POLICY)->getArguments());
        $listener = $container->getDefinition(MessengerMonitorConflictListener::class);
        self::assertEquals(new Reference(ConflictResponsePass::OWN_POLICY), $listener->getArgument('$policy'));
        $responder = $listener->getArgument('$responder');
        self::assertInstanceOf(Definition::class, $responder);
        self::assertSame(ConcurrencyConflictResponder::class, $responder->getClass());
        self::assertSame([], $responder->getArgument(2));
        self::assertNull($responder->getArgument(3));
        self::assertFalse($listener->getArgument('$errorPage'));
    }

    #[Test]
    public function withTransactionDoctrineBundleTheSharedPolicyAndResponderAreUsed(): void
    {
        $container = $this->withListener(['status' => null, 'error_page' => true]);
        $container->setDefinition(ConflictResponsePass::SHARED_POLICY, new Definition(ConflictResponsePolicy::class));
        $container->setDefinition(ConflictResponsePass::SHARED_RESPONDER, new Definition(ConcurrencyConflictResponder::class));
        (new ConflictResponsePass())->process($container);

        $listener = $container->getDefinition(MessengerMonitorConflictListener::class);
        self::assertEquals(new Reference(ConflictResponsePass::SHARED_POLICY), $listener->getArgument('$policy'));
        self::assertEquals(new Reference(ConflictResponsePass::SHARED_RESPONDER), $listener->getArgument('$responder'));
        self::assertTrue($listener->getArgument('$errorPage'));
    }

    #[Test]
    public function anExplicitStatusKeepsTheSharedTemplateButUsesItsOwnPolicy(): void
    {
        $container = $this->withListener(['status' => 503, 'error_page' => false]);
        $container->setDefinition(ConflictResponsePass::SHARED_POLICY, new Definition(ConflictResponsePolicy::class));
        $container->setDefinition(ConflictResponsePass::SHARED_RESPONDER, new Definition(ConcurrencyConflictResponder::class));
        (new ConflictResponsePass())->process($container);

        self::assertSame([503], $container->getDefinition(ConflictResponsePass::OWN_POLICY)->getArguments());
        $responder = $container->getDefinition(MessengerMonitorConflictListener::class)->getArgument('$responder');
        self::assertInstanceOf(Definition::class, $responder);
        self::assertInstanceOf(TaggedIteratorArgument::class, $responder->getArgument(2));
        self::assertEquals(new Reference('twig', ContainerBuilder::NULL_ON_INVALID_REFERENCE), $responder->getArgument(3));
    }

    #[Test]
    public function theRetryLockUsesTheConfiguredFactory(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition('lock.factory', new Definition(LockFactory::class));
        (new RetryLockPass())->process($container);
        self::assertSame('lock.factory', (string) $container->getAlias(RetryLockPass::SERVICE));

        $custom = new ContainerBuilder();
        $custom->setParameter(RetryLockPass::PARAMETER, 'app.shared_locks');
        $custom->setDefinition('app.shared_locks', new Definition(LockFactory::class));
        (new RetryLockPass())->process($custom);
        self::assertSame('app.shared_locks', (string) $custom->getAlias(RetryLockPass::SERVICE));
    }

    #[Test]
    public function withoutFrameworkLockTheRetryLockFallsBackToALocalFlockStore(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter(RetryLockPass::PARAMETER, RetryLockPass::DEFAULT_FACTORY);
        (new RetryLockPass())->process($container);

        $definition = $container->getDefinition(RetryLockPass::SERVICE);
        self::assertSame(LockFactory::class, $definition->getClass());
        $store = $definition->getArgument(0);
        self::assertInstanceOf(Definition::class, $store);
        self::assertSame(FlockStore::class, $store->getClass());

        $odd = new ContainerBuilder();
        $odd->setParameter(RetryLockPass::PARAMETER, ['not', 'a', 'string']);
        (new RetryLockPass())->process($odd);
        self::assertTrue($odd->hasDefinition(RetryLockPass::SERVICE));
    }

    #[Test]
    public function anUnknownConfiguredFactoryFailsFast(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter(RetryLockPass::PARAMETER, 'app.missing_locks');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('retry.lock_factory "app.missing_locks" is not a service');
        (new RetryLockPass())->process($container);
    }

    /**
     * @param array{status: int|null, error_page: bool}|null $config
     */
    private function withListener(?array $config = null): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setDefinition(MessengerMonitorConflictListener::class, new Definition(MessengerMonitorConflictListener::class));
        if ($config !== null) {
            $container->setParameter(ConflictResponsePass::PARAMETER, $config);
        }

        return $container;
    }
}
