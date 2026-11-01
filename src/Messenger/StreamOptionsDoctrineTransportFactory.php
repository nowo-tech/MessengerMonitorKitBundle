<?php

declare(strict_types=1);

namespace Nowo\MessengerMonitorKitBundle\Messenger;

use SensitiveParameter;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;
use Symfony\Component\Messenger\Transport\TransportFactoryInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;

use function array_flip;
use function array_intersect_key;
use function is_string;

/**
 * Doctrine counterpart of {@see StreamOptionsAmqpTransportFactory}: lets messenger.yaml keep Redis-stream options with
 * `doctrine://…` DSNs. The Doctrine transport throws on unknown options and every transport would otherwise share the
 * `default` queue, so each transport's `stream` becomes its `queue_name` and options outside the Doctrine set are
 * dropped (DSN query parameters still win, Symfony's precedence). Wraps `messenger.transport.doctrine.factory`.
 *
 * @implements TransportFactoryInterface<TransportInterface>
 */
final readonly class StreamOptionsDoctrineTransportFactory implements TransportFactoryInterface
{
    /** Options accepted by the Doctrine connection (+ the ones DoctrineTransportFactory strips itself). */
    public const DOCTRINE_OPTIONS = [
        'table_name', 'queue_name', 'redeliver_timeout', 'auto_setup', 'check_delayed_interval', 'get_notify_timeout',
        'use_notify', 'transport_name',
    ];

    /**
     * @param TransportFactoryInterface<TransportInterface> $inner Symfony's Doctrine transport factory
     */
    public function __construct(
        private TransportFactoryInterface $inner,
    ) {
    }

    /**
     * Same DSNs as the inner factory.
     *
     * @param array<string, mixed> $options
     */
    public function supports(#[SensitiveParameter] string $dsn, array $options): bool
    {
        return $this->inner->supports($dsn, $options);
    }

    /**
     * Creates the Doctrine transport with the filtered options.
     *
     * @param array<string, mixed> $options
     */
    public function createTransport(#[SensitiveParameter] string $dsn, array $options, SerializerInterface $serializer): TransportInterface
    {
        return $this->inner->createTransport($dsn, self::doctrineOptions($options), $serializer);
    }

    /**
     * Doctrine options of a transport configured with Redis-stream options.
     *
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    public static function doctrineOptions(array $options): array
    {
        $stream  = $options['stream'] ?? null;
        $options = array_intersect_key($options, array_flip(self::DOCTRINE_OPTIONS));
        if (is_string($stream) && $stream !== '') {
            $options['queue_name'] ??= $stream;
        }

        return $options;
    }
}
