<?php

declare(strict_types=1);

namespace Nowo\MessengerMonitorKitBundle\Tests\Unit\Recording;

use Nowo\MessengerMonitorKitBundle\Payload\Redactor;
use Nowo\MessengerMonitorKitBundle\Recording\RecordingSwitch;
use Nowo\MessengerMonitorKitBundle\Recording\ToggleableMonitorStorage;
use Nowo\MessengerMonitorKitBundle\Settings\CacheMonitorSettingsStore;
use Nowo\MessengerMonitorKitBundle\Settings\MonitorSettings;
use Nowo\MessengerMonitorKitBundle\Settings\MonitorSettingsStoreInterface;
use Nowo\MessengerMonitorKitBundle\Tests\Fixtures\DemoMessage;
use Nowo\MessengerMonitorKitBundle\Tests\Fixtures\OptionalMessage;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Messenger\Envelope;
use Throwable;
use Zenstruck\Collection;
use Zenstruck\Collection\ArrayCollection;
use Zenstruck\Messenger\Monitor\History\Model\ProcessedMessage;
use Zenstruck\Messenger\Monitor\History\Model\Results;
use Zenstruck\Messenger\Monitor\History\Specification;
use Zenstruck\Messenger\Monitor\History\Storage;

final class RecordingTest extends TestCase
{
    #[Test]
    public function theSwitchCachesTheStoreForTheCheckInterval(): void
    {
        $store = new class implements MonitorSettingsStoreInterface {
            public int $loads = 0;

            public MonitorSettings $settings;

            public function __construct()
            {
                $this->settings = new MonitorSettings();
            }

            public function load(): MonitorSettings
            {
                ++$this->loads;

                return $this->settings;
            }

            public function save(MonitorSettings $settings): void
            {
                $this->settings = $settings;
            }

            public function isWritable(): bool
            {
                return true;
            }
        };
        $clock  = new MockClock('2026-10-31 10:00:00');
        $switch = new RecordingSwitch($store, $clock, 30);

        self::assertTrue($switch->settings()->recording);
        $store->save(new MonitorSettings(false));
        $clock->sleep(29);
        self::assertTrue($switch->settings()->recording, 'still cached');
        $clock->sleep(1);
        self::assertFalse($switch->settings()->recording, 'reloaded after the interval');
        self::assertSame(2, $store->loads);
    }

    #[Test]
    public function theStorageSkipsWritesWhileOffAndOptionalMessages(): void
    {
        $inner   = $this->inner();
        $store   = new CacheMonitorSettingsStore(new ArrayAdapter(), new MonitorSettings());
        $storage = new ToggleableMonitorStorage($inner, new RecordingSwitch($store, new MockClock(), 0), new Redactor(), [OptionalMessage::class]);

        $storage->save(new Envelope(new DemoMessage()), new Results([['handler' => 'H', 'data' => ['email' => 'a@b.test'], 'message' => 'for a@b.test', 'exception' => RuntimeException::class]]));
        $storage->save(new Envelope(new OptionalMessage()), new Results([]));
        self::assertCount(1, $inner->saved);
        self::assertSame([['handler' => 'H', 'data' => ['email' => '[redacted]'], 'message' => 'for [redacted]', 'exception' => RuntimeException::class]], $inner->saved[0]->jsonSerialize());

        $store->save(new MonitorSettings(true, true));
        $storage->save(new Envelope(new OptionalMessage()), new Results(null));
        self::assertCount(2, $inner->saved);

        $store->save(new MonitorSettings(false));
        $storage->save(new Envelope(new DemoMessage()), new Results([]));
        self::assertCount(2, $inner->saved);
    }

    #[Test]
    public function resultsCanBeDropped(): void
    {
        $inner   = $this->inner();
        $storage = new ToggleableMonitorStorage($inner, new RecordingSwitch(new CacheMonitorSettingsStore(new ArrayAdapter(), new MonitorSettings()), new MockClock()), new Redactor(), [], false);
        $storage->save(new Envelope(new DemoMessage()), new Results([['handler' => 'H', 'data' => ['x' => 1]]]));
        self::assertSame([], $inner->saved[0]->jsonSerialize());
    }

    #[Test]
    public function readsAndPurgesDelegate(): void
    {
        $inner   = $this->inner();
        $storage = new ToggleableMonitorStorage($inner, new RecordingSwitch(new CacheMonitorSettingsStore(new ArrayAdapter(), new MonitorSettings(false)), new MockClock()), new Redactor());
        $spec    = Specification::new();

        self::assertNull($storage->find(1));
        self::assertCount(0, $storage->filter($spec));
        self::assertSame(7, $storage->purge($spec));
        $storage->delete(1);
        self::assertSame(11, $storage->averageWaitTime($spec));
        self::assertSame(12, $storage->averageHandlingTime($spec));
        self::assertSame(13, $storage->count($spec));
        self::assertCount(0, $storage->perMessageTypeMetrics($spec));
        self::assertCount(0, $storage->availableMessageTypes($spec));
        self::assertSame(['delete'], $inner->calls);
    }

    /**
     * @return object{saved: list<Results>, calls: list<string>}&Storage
     */
    private function inner(): Storage
    {
        return new class implements Storage {
            /** @var list<Results> */
            public array $saved = [];

            /** @var list<string> */
            public array $calls = [];

            public function find(mixed $id): ?ProcessedMessage
            {
                return null;
            }

            public function filter(Specification $specification): Collection
            {
                return new ArrayCollection([]);
            }

            public function purge(Specification $specification): int
            {
                return 7;
            }

            public function save(Envelope $envelope, Results $results, ?Throwable $exception = null): void
            {
                $this->saved[] = $results;
            }

            public function delete(mixed $id): void
            {
                $this->calls[] = 'delete';
            }

            public function averageWaitTime(Specification $specification): int
            {
                return 11;
            }

            public function averageHandlingTime(Specification $specification): int
            {
                return 12;
            }

            public function count(Specification $specification): int
            {
                return 13;
            }

            public function perMessageTypeMetrics(Specification $specification): Collection
            {
                return new ArrayCollection([]);
            }

            public function availableMessageTypes(Specification $specification): Collection
            {
                return new ArrayCollection([]);
            }
        };
    }
}
