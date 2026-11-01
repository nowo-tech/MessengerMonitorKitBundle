<?php

declare(strict_types=1);

namespace Nowo\MessengerMonitorKitBundle\Settings;

use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Throwable;

use function is_array;

/**
 * `settings.store: cache` (default) — the switches in one non-expiring item of a PSR-6 pool shared by web and workers
 * (`settings.cache_pool`, default `cache.app`). A pool error never breaks message handling: the defaults apply and a
 * warning is logged.
 */
final readonly class CacheMonitorSettingsStore implements MonitorSettingsStoreInterface
{
    /** Cache key of the switches. */
    public const KEY = 'nowo_messenger_monitor_kit.settings';

    /**
     * @param CacheItemPoolInterface $pool shared pool
     * @param MonitorSettings $defaults switches built from the bundle configuration
     */
    public function __construct(
        private CacheItemPoolInterface $pool,
        private MonitorSettings $defaults,
        private ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * Stored switches merged over the defaults.
     */
    public function load(): MonitorSettings
    {
        try {
            $item = $this->pool->getItem(self::KEY);
            $data = $item->isHit() ? $item->get() : null;
        } catch (Throwable $e) {
            $this->logger?->warning('Messenger monitor settings could not be read from the cache pool; using the configured defaults.', ['exception' => $e]);

            return $this->defaults;
        }

        return is_array($data) ? MonitorSettings::fromArray($data, $this->defaults) : $this->defaults;
    }

    /**
     * Saves the switches (no expiry).
     */
    public function save(MonitorSettings $settings): void
    {
        $item = $this->pool->getItem(self::KEY);
        $item->set($settings->toArray());
        $this->pool->save($item);
    }

    /**
     * True.
     */
    public function isWritable(): bool
    {
        return true;
    }
}
