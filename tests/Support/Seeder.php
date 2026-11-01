<?php

declare(strict_types=1);

namespace Nowo\MessengerMonitorKitBundle\Tests\Support;

use Nowo\MessengerMonitorKitBundle\Tests\Fixtures\DemoMessage;
use Psr\Container\ContainerInterface;
use RuntimeException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\BusNameStamp;
use Symfony\Component\Messenger\Stamp\ErrorDetailsStamp;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Stamp\SentToFailureTransportStamp;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Symfony\Component\Messenger\Transport\TransportInterface;
use Throwable;
use Zenstruck\Messenger\Monitor\History\Model\Results;
use Zenstruck\Messenger\Monitor\History\Storage;
use Zenstruck\Messenger\Monitor\Stamp\MonitorStamp;

/**
 * Writes history rows through the (decorated) storage and parks failed messages on a transport, like workers do.
 */
final class Seeder
{
    /**
     * Saves one history row (failed when `$failure` is given).
     *
     * @param list<array{handler?: string, exception?: class-string<Throwable>, message?: string, data: array<string, mixed>}> $results
     */
    public static function history(ContainerInterface $container, object $message = new DemoMessage(), ?string $failure = null, array $results = [], string $transport = 'async'): void
    {
        $storage = $container->get('zenstruck_messenger_monitor.history.storage');
        if (!$storage instanceof Storage) {
            throw new RuntimeException('No history storage.');
        }
        $stamp = (new MonitorStamp())->markReceived($transport)->markFinished();
        $storage->save(new Envelope($message, [$stamp]), new Results($results), $failure === null ? null : new RuntimeException($failure));
    }

    /**
     * Parks a message on the failure transport as if it failed on `$origin`; returns its id.
     */
    public static function failed(ContainerInterface $container, object $message = new DemoMessage(), string $origin = 'async', string $error = 'Payment refused for jane.doe@example.test', string $failureTransport = 'failed'): string
    {
        $transport = $container->get('messenger.transport.' . $failureTransport);
        if (!$transport instanceof TransportInterface) {
            throw new RuntimeException('No failure transport.');
        }
        $sent = $transport->send(new Envelope($message, [
            new BusNameStamp('messenger.bus.default'),
            new SentToFailureTransportStamp($origin),
            new RedeliveryStamp(1),
            ErrorDetailsStamp::create(new RuntimeException($error)),
            new MonitorStamp(),
        ]));

        return (string) $sent->last(TransportMessageIdStamp::class)?->getId();
    }
}
