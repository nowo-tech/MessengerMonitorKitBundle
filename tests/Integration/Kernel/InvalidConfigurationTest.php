<?php

declare(strict_types=1);

namespace Nowo\MessengerMonitorKitBundle\Tests\Integration\Kernel;

use LogicException;
use Nowo\MessengerMonitorKitBundle\Queue\QueueDepth;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;

/**
 * Container-level guards: demo-only switches, security bundle, history storage, literal booleans.
 */
final class InvalidConfigurationTest extends KernelTestCase
{
    public function testUnauthenticatedAccessOnlyInDebug(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('only allowed when kernel.debug is true');
        $this->bootKernel(['security' => ['allow_unauthenticated' => true]]);
    }

    public function testUnauthenticatedAccessInDebugBoots(): void
    {
        $kernel = $this->bootKernel(['security' => ['allow_unauthenticated' => true]], without: ['security'], debug: true);
        $client = $this->client($kernel, null);
        $client->request('GET', '/admin/messenger/settings');
        self::assertSame(200, $client->getResponse()->getStatusCode());
    }

    public function testUiWithoutSecurityBundleFails(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('requires symfony/security-bundle');
        $this->bootKernel(without: ['security']);
    }

    public function testDisabledUiNeedsNoSecurityAndKeepsTheServices(): void
    {
        $kernel = $this->bootKernel(['web_ui' => ['enabled' => false]], without: ['security']);
        self::assertInstanceOf(QueueDepth::class, $this->container($kernel)->get(QueueDepth::class));
    }

    public function testTheUiNeedsAHistoryStorage(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('ORM history storage');
        $this->bootKernel(['history' => ['bundle_entity' => false]]);
    }

    public function testEnvPlaceholderSwitchIsRefused(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('must be a literal boolean');
        $this->bootKernel(['security' => ['allow_unauthenticated' => '%env(bool:MONITOR_OPEN)%']]);
    }
}
