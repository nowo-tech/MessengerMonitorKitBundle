<?php

declare(strict_types=1);

namespace Nowo\MessengerMonitorKitBundle\Messenger;

use SensitiveParameter;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;
use Symfony\Component\Messenger\Transport\TransportFactoryInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;

use function array_diff_key;
use function array_flip;
use function is_array;
use function is_string;

/**
 * Lets one messenger.yaml keep Redis-stream options while the DSN picks the broker (`amqp://…` or `redis://…`).
 *
 * The AMQP transport rejects Redis-only options, and without names every transport would share the default exchange
 * and queue. So each transport's `stream` becomes its own exchange and queue (`transport_factories.amqp.prefix` +
 * stream, e.g. `app.async`), the retry delays get one prefixed delay exchange, and Redis-only options are dropped.
 * Registered with a higher priority than Symfony's AMQP factory (`messenger.transport.amqp.factory`), which it wraps.
 *
 * @implements TransportFactoryInterface<TransportInterface>
 */
final readonly class StreamOptionsAmqpTransportFactory implements TransportFactoryInterface
{
    /** Redis connection options with no AMQP meaning (`auto_setup`, `read_timeout` are shared and kept). */
    public const REDIS_ONLY_OPTIONS = [
        'stream', 'group', 'consumer', 'delete_after_ack', 'delete_after_reject', 'stream_max_entries', 'dbindex',
        'redeliver_timeout', 'claim_interval', 'lazy', 'auth', 'serializer', 'sentinel', 'timeout', 'retry_interval',
        'persistent_id', 'ssl', 'cluster',
    ];

    /**
     * @param TransportFactoryInterface<TransportInterface> $inner Symfony's AMQP transport factory
     * @param string $prefix prefix of the exchange / queue / delay exchange names
     */
    public function __construct(
        private TransportFactoryInterface $inner,
        private string $prefix = '',
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
     * Creates the AMQP transport with the rewritten options.
     *
     * @param array<string, mixed> $options
     */
    public function createTransport(#[SensitiveParameter] string $dsn, array $options, SerializerInterface $serializer): TransportInterface
    {
        return $this->inner->createTransport($dsn, $this->amqpOptions($options), $serializer);
    }

    /**
     * AMQP options of a transport configured with Redis-stream options.
     *
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    public function amqpOptions(array $options): array
    {
        $stream  = $options['stream'] ?? null;
        $options = array_diff_key($options, array_flip(self::REDIS_ONLY_OPTIONS));

        if (is_string($stream) && $stream !== '') {
            $name                = $this->prefix . $stream;
            $options['exchange'] = (is_array($options['exchange'] ?? null) ? $options['exchange'] : []) + ['name' => $name];
            $options['queues'] ??= [$name => []];
        }
        $options['delay'] = (is_array($options['delay'] ?? null) ? $options['delay'] : []) + ['exchange_name' => $this->prefix . 'delays'];

        return $options;
    }
}
