<?php

declare(strict_types=1);

namespace Nowo\MessengerMonitorKitBundle\Tests\Unit\Messenger;

use Closure;
use Nowo\MessengerMonitorKitBundle\Messenger\FailedTransportDsnEnvVarProcessor;
use Nowo\MessengerMonitorKitBundle\Messenger\StreamOptionsAmqpTransportFactory;
use Nowo\MessengerMonitorKitBundle\Messenger\StreamOptionsDoctrineTransportFactory;
use Nowo\MessengerMonitorKitBundle\Tests\Support\MemoryTransport;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Exception\EnvNotFoundException;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;
use Symfony\Component\Messenger\Transport\TransportFactoryInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;

use function array_key_exists;

final class TransportFactoriesTest extends TestCase
{
    #[Test]
    public function amqpOptionsComeFromTheStream(): void
    {
        $inner   = $this->inner();
        $factory = new StreamOptionsAmqpTransportFactory($inner, 'app.');

        self::assertTrue($factory->supports('amqp://localhost', []));
        self::assertFalse($factory->supports('redis://localhost', []));
        $factory->createTransport('amqp://localhost', ['stream' => 'async', 'group' => 'g', 'consumer' => 'c', 'auto_setup' => true], new PhpSerializer());
        self::assertSame(['auto_setup' => true, 'exchange' => ['name' => 'app.async'], 'queues' => ['app.async' => []], 'delay' => ['exchange_name' => 'app.delays']], $inner->options);

        self::assertSame(
            ['exchange' => ['type' => 'direct', 'name' => 'app.x'], 'queues' => ['custom' => []], 'delay' => ['queue_name_pattern' => 'd', 'exchange_name' => 'app.delays']],
            $factory->amqpOptions(['stream' => 'x', 'exchange' => ['type' => 'direct'], 'queues' => ['custom' => []], 'delay' => ['queue_name_pattern' => 'd']]),
        );
        self::assertSame(['delay' => ['exchange_name' => 'app.delays']], $factory->amqpOptions(['stream' => '']));
    }

    #[Test]
    public function doctrineOptionsComeFromTheStream(): void
    {
        $inner   = $this->inner();
        $factory = new StreamOptionsDoctrineTransportFactory($inner);

        self::assertTrue($factory->supports('doctrine://default', []));
        $factory->createTransport('doctrine://default', ['stream' => 'async', 'consumer' => 'c', 'redeliver_timeout' => 60], new PhpSerializer());
        self::assertSame(['redeliver_timeout' => 60, 'queue_name' => 'async'], $inner->options);
        self::assertSame(['queue_name' => 'kept'], StreamOptionsDoctrineTransportFactory::doctrineOptions(['stream' => 'async', 'queue_name' => 'kept']));
        self::assertSame([], StreamOptionsDoctrineTransportFactory::doctrineOptions(['stream' => 1]));
    }

    #[Test]
    public function theFailedDsnIsDerived(): void
    {
        $processor = new FailedTransportDsnEnvVarProcessor('QUEUE', 'REDIS');
        $env       = static fn (array $vars): Closure => static function (string $name) use ($vars): string {
            if (!array_key_exists($name, $vars)) {
                throw new EnvNotFoundException($name);
            }

            return $vars[$name];
        };

        self::assertSame(['messenger_failed_dsn' => 'string'], FailedTransportDsnEnvVarProcessor::getProvidedTypes());
        self::assertSame('doctrine://x', $processor->getEnv('messenger_failed_dsn', 'FAILED', $env(['FAILED' => ' doctrine://x '])));
        self::assertSame('redis://r:6379/messages', $processor->getEnv('messenger_failed_dsn', 'FAILED', $env(['FAILED' => '', 'QUEUE' => 'redis://r:6379/messages'])));
        self::assertSame('redis://:pw@r:6379?timeout=2&dbindex=3', $processor->getEnv('messenger_failed_dsn', 'FAILED', $env(['FAILED' => '', 'QUEUE' => 'amqp://rabbit', 'REDIS' => 'redis://:pw@r:6379/3?timeout=2'])));
        self::assertSame('amqps://rabbit', $processor->getEnv('messenger_failed_dsn', 'FAILED', $env(['FAILED' => '', 'QUEUE' => 'amqps://rabbit'])));
        self::assertSame('redis://r', FailedTransportDsnEnvVarProcessor::messengerRedisDsn('redis://r/'));
        self::assertSame('redis://r?dbindex=1', FailedTransportDsnEnvVarProcessor::messengerRedisDsn('redis://r?dbindex=1'));
        self::assertSame('not-a-redis-url', FailedTransportDsnEnvVarProcessor::messengerRedisDsn('not-a-redis-url'));
    }

    /**
     * @return object{options: array<string, mixed>}&TransportFactoryInterface<TransportInterface>
     */
    private function inner(): TransportFactoryInterface
    {
        return new class implements TransportFactoryInterface {
            /** @var array<string, mixed> */
            public array $options = [];

            /**
             * @param array<string, mixed> $options
             */
            public function createTransport(string $dsn, array $options, SerializerInterface $serializer): TransportInterface
            {
                $this->options = $options;

                return new MemoryTransport();
            }

            /**
             * @param array<string, mixed> $options
             */
            public function supports(string $dsn, array $options): bool
            {
                return str_starts_with($dsn, 'amqp') || str_starts_with($dsn, 'doctrine');
            }
        };
    }
}
