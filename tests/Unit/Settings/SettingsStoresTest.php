<?php

declare(strict_types=1);

namespace Nowo\MessengerMonitorKitBundle\Tests\Unit\Settings;

use LogicException;
use Nowo\InstanceSettingsBundle\File\StoredSettingFile;
use Nowo\InstanceSettingsBundle\Section\SettingField;
use Nowo\InstanceSettingsBundle\Settings\InstanceSettingsInterface;
use Nowo\MessengerMonitorKitBundle\History\PurgePeriod;
use Nowo\MessengerMonitorKitBundle\NowoMessengerMonitorKitBundle;
use Nowo\MessengerMonitorKitBundle\Settings\CacheMonitorSettingsStore;
use Nowo\MessengerMonitorKitBundle\Settings\ConfigMonitorSettingsStore;
use Nowo\MessengerMonitorKitBundle\Settings\InstanceSettingsMonitorSettingsStore;
use Nowo\MessengerMonitorKitBundle\Settings\MessengerMonitorSettingsSection;
use Nowo\MessengerMonitorKitBundle\Settings\MonitorSettings;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class SettingsStoresTest extends TestCase
{
    #[Test]
    public function settingsRoundTripAndIgnoreInvalidEntries(): void
    {
        $settings = new MonitorSettings(false, true, true, PurgePeriod::OneWeek, false);
        self::assertEquals($settings, MonitorSettings::fromArray($settings->toArray()));

        $defaults = new MonitorSettings(true, false, false, PurgePeriod::SixMonths, true);
        self::assertEquals($defaults, MonitorSettings::fromArray(['recording' => 'yes', 'retention' => 'all', 'keep_failed' => 1], $defaults));
        self::assertEquals($defaults, MonitorSettings::fromArray(['retention' => 'unknown'], $defaults));
    }

    #[Test]
    public function theCacheStoreSavesAndFallsBackToTheDefaults(): void
    {
        $defaults = new MonitorSettings();
        $store    = new CacheMonitorSettingsStore(new ArrayAdapter(), $defaults);
        self::assertTrue($store->isWritable());
        self::assertSame($defaults, $store->load());

        $saved = new MonitorSettings(false, true, true, PurgePeriod::OneDay, false);
        $store->save($saved);
        self::assertEquals($saved, $store->load());

        $pool = new ArrayAdapter();
        $item = $pool->getItem(CacheMonitorSettingsStore::KEY);
        $pool->save($item->set('garbage'));
        self::assertSame($defaults, (new CacheMonitorSettingsStore($pool, $defaults))->load());

        $broken = $this->createStub(CacheItemPoolInterface::class);
        $broken->method('getItem')->willThrowException(new RuntimeException('down'));
        self::assertSame($defaults, (new CacheMonitorSettingsStore($broken, $defaults, new NullLogger()))->load());
    }

    #[Test]
    public function theConfigStoreIsReadOnly(): void
    {
        $defaults = new MonitorSettings(false);
        $store    = new ConfigMonitorSettingsStore($defaults);
        self::assertSame($defaults, $store->load());
        self::assertFalse($store->isWritable());
        $this->expectException(LogicException::class);
        $store->save($defaults);
    }

    #[Test]
    public function theInstanceSettingsStoreUsesTheSection(): void
    {
        $instance = new class implements InstanceSettingsInterface {
            /** @var array<string, mixed> */
            public array $section = ['recording' => false, 'record_optional' => true, 'auto_purge' => true, 'retention' => '3-months', 'keep_failed' => false];

            public bool $fail = false;

            /** @var list<array{string, array<string, mixed>, string}> */
            public array $saved = [];

            public function get(string $key): bool|float|int|string|null
            {
                return null;
            }

            public function getString(string $key): ?string
            {
                return null;
            }

            public function getInt(string $key): ?int
            {
                return null;
            }

            public function getFloat(string $key): ?float
            {
                return null;
            }

            public function getBool(string $key): bool
            {
                return false;
            }

            public function getSecret(string $key): ?string
            {
                return null;
            }

            public function hasSecret(string $key): bool
            {
                return false;
            }

            public function getFile(string $key): ?StoredSettingFile
            {
                return null;
            }

            public function isOverridden(string $key): bool
            {
                return false;
            }

            public function getSection(string $section): array
            {
                if ($this->fail) {
                    throw new RuntimeException('db down');
                }

                return $this->section;
            }

            public function save(string $section, array $values, string $origin = 'api'): array
            {
                $this->saved[] = [$section, $values, $origin];

                return [];
            }
        };
        $defaults = new MonitorSettings();
        $store    = new InstanceSettingsMonitorSettingsStore($instance, $defaults, new NullLogger());

        self::assertEquals(new MonitorSettings(false, true, true, PurgePeriod::ThreeMonths, false), $store->load());
        self::assertTrue($store->isWritable());
        $store->save($defaults);
        self::assertSame([MessengerMonitorSettingsSection::NAME, $defaults->toArray(), 'form'], $instance->saved[0]);

        $instance->fail = true;
        self::assertSame($defaults, $store->load());
    }

    #[Test]
    public function theSectionDescribesTheSwitches(): void
    {
        $section = new MessengerMonitorSettingsSection(new MonitorSettings(false, true, true, PurgePeriod::OneWeek, false));
        self::assertSame('messenger_monitor', $section->getName());
        self::assertSame('settings.section.label', $section->getLabel());
        self::assertSame(NowoMessengerMonitorKitBundle::TRANSLATION_DOMAIN, $section->getTranslationDomain());
        self::assertSame('settings.section.intro', $section->getIntro());

        $fields = [];
        foreach ($section->getFields() as $field) {
            self::assertInstanceOf(SettingField::class, $field);
            $fields[$field->name] = $field->default;
        }
        self::assertSame(['recording' => false, 'record_optional' => true, 'auto_purge' => true, 'retention' => '1-week', 'keep_failed' => false], $fields);
    }

    #[Test]
    public function theCacheItemIsSavedWithoutExpiry(): void
    {
        $pool = new ArrayAdapter();
        (new CacheMonitorSettingsStore($pool, new MonitorSettings()))->save(new MonitorSettings(false));
        self::assertTrue($pool->getItem(CacheMonitorSettingsStore::KEY)->isHit());
    }
}
