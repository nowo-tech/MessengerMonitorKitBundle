<?php

declare(strict_types=1);

namespace Nowo\MessengerMonitorKitBundle\DependencyInjection\Compiler;

use LogicException;
use Nowo\MessengerMonitorKitBundle\Retry\NoRetryOnDecodingFailureRetryStrategy;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

use function is_array;
use function is_string;
use function sprintf;

/**
 * Messenger integrations that need the final Messenger services:
 *
 * - `retry_strategy.no_retry_on_decoding_failure`: decorates `messenger.retry.multiplier_retry_strategy.<transport>`
 *   with {@see NoRetryOnDecodingFailureRetryStrategy} (an unknown transport, or one with a custom retry strategy
 *   service, fails the build with a clear message);
 * - `transport_factories.*`: checks that the wrapped Symfony factory exists (symfony/amqp-messenger,
 *   symfony/doctrine-messenger).
 */
final class MessengerIntegrationPass implements CompilerPassInterface
{
    /** Parameter listing the transports of the decoding-failure strategy. */
    public const NO_RETRY_PARAMETER = 'nowo_messenger_monitor_kit.retry_strategy.no_retry_on_decoding_failure';

    /** Parameter mapping the enabled stream-options factories to their wrapped service id. */
    public const FACTORIES_PARAMETER = 'nowo_messenger_monitor_kit.transport_factories.inner';

    /**
     * Registers the decorators and validates the wrapped factories.
     */
    public function process(ContainerBuilder $container): void
    {
        $transports = $container->hasParameter(self::NO_RETRY_PARAMETER) ? $container->getParameter(self::NO_RETRY_PARAMETER) : [];
        foreach (is_array($transports) ? $transports : [] as $transport) {
            $strategy = 'messenger.retry.multiplier_retry_strategy.' . (is_string($transport) ? $transport : '');
            if (!$container->has($strategy)) {
                throw new LogicException(sprintf('nowo_messenger_monitor_kit.retry_strategy.no_retry_on_decoding_failure: transport "%s" has no multiplier retry strategy ("%s"); check the transport name, or wrap a custom retry_strategy.service yourself with %s.', (string) $transport, $strategy, NoRetryOnDecodingFailureRetryStrategy::class));
            }
            $container->setDefinition('nowo_messenger_monitor_kit.no_retry_on_decoding_failure.' . $transport, (new Definition(NoRetryOnDecodingFailureRetryStrategy::class, [new Reference('.inner')]))
                ->setDecoratedService($strategy));
        }

        $factories = $container->hasParameter(self::FACTORIES_PARAMETER) ? $container->getParameter(self::FACTORIES_PARAMETER) : [];
        foreach (is_array($factories) ? $factories : [] as $factory => $inner) {
            if (!is_string($inner) || !$container->has($inner)) {
                throw new LogicException(sprintf('nowo_messenger_monitor_kit.transport_factories.%s needs the "%s" service (composer require symfony/%s-messenger).', (string) $factory, (string) $inner, (string) $factory));
            }
        }
    }
}
