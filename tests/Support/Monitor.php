<?php

declare(strict_types=1);

namespace Nowo\MessengerMonitorKitBundle\Tests\Support;

use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Messenger\Transport\Sync\SyncTransport;
use Symfony\Component\Messenger\Transport\TransportInterface;
use Zenstruck\Messenger\Monitor\Transports;
use Zenstruck\Messenger\Monitor\Worker\WorkerCache;
use Zenstruck\Messenger\Monitor\Workers;

/**
 * Builds zenstruck monitor services over fake transports.
 */
final class Monitor
{
    /**
     * @param array<string, TransportInterface> $transports
     */
    public static function transports(array $transports): Transports
    {
        $factories = [];
        foreach ($transports as $name => $transport) {
            $factories[$name] = static fn (): TransportInterface => $transport;
        }

        return new Transports(new ServiceLocator($factories), self::workers());
    }

    public static function workers(): Workers
    {
        return new Workers(new WorkerCache(new ArrayAdapter()));
    }

    public static function sync(): SyncTransport
    {
        return new SyncTransport(new RecordingBus());
    }
}
