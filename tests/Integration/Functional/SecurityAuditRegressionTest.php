<?php

declare(strict_types=1);

namespace Nowo\MessengerMonitorKitBundle\Tests\Integration\Functional;

use Nowo\MessengerMonitorKitBundle\Retry\FailedMessageRetrier;
use Nowo\MessengerMonitorKitBundle\Routing\MessengerMonitorRoutes;
use Nowo\MessengerMonitorKitBundle\Tests\Fixtures\DemoMessage;
use Nowo\MessengerMonitorKitBundle\Tests\Fixtures\StringableMessage;
use Nowo\MessengerMonitorKitBundle\Tests\Integration\Kernel\KernelTestCase;
use Nowo\MessengerMonitorKitBundle\Tests\Integration\Kernel\MonitorTestKernel;
use Nowo\MessengerMonitorKitBundle\Tests\Support\Seeder;
use Nowo\MessengerMonitorKitBundle\Ui\ActionForms;
use ReflectionProperty;
use Symfony\Component\Messenger\Transport\Receiver\MessageCountAwareInterface;

/**
 * End-to-end regressions of the REQ-SEC-004 audit (Appendix CB, 2026-11-01), adapted from the audit PoC.
 */
final class SecurityAuditRegressionTest extends KernelTestCase
{
    private const PREFIX = '/admin/messenger';

    private const ERROR = 'SQLSTATE[08006] connection to mysql://app:REDACTED-TEST-TOKEN@10.0.0.5/app failed; Authorization: Bearer eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxIn0.c2ln; '
        . 'api_key=sk_live_PLACEHOLDER0; password=REDACTED-TEST-TOKEN; customer DNI 12345678Z card 4111.1111.1111.1111 phone (612) 345-678 jane.doe@example.test';

    private const LEAKS = ['REDACTED-TEST-TOKEN', 'eyJhbGciOiJIUzI1NiJ9', 'sk_live_', '12345678Z', '4111.1111.1111.1111', '(612) 345-678', 'jane.doe@example.test'];

    /** M1: view-only users get metadata only; managers get the texts redacted. */
    public function testViewerDisclosure(): void
    {
        $kernel = $this->bootKernel(['security' => ['manage_roles' => ['ROLE_OPS']]]);
        $c      = $this->container($kernel);
        $id     = Seeder::failed($c, error: self::ERROR);
        Seeder::history($c, new StringableMessage(), self::ERROR);

        $viewer = $this->client($kernel, 'admin');
        $viewer->request('GET', self::PREFIX . '/transports/failed/messages/' . $id);
        self::assertSame(200, $viewer->getResponse()->getStatusCode());
        $this->assertNoLeak((string) $viewer->getResponse()->getContent(), 'Payment', 'SQLSTATE');
        $viewer->request('GET', self::PREFIX . '/history/1');
        self::assertSame(200, $viewer->getResponse()->getStatusCode());
        $this->assertNoLeak((string) $viewer->getResponse()->getContent(), 'Invoice for', 'SQLSTATE');

        $manager = $this->client($kernel, 'ops');
        $manager->request('GET', self::PREFIX . '/transports/failed/messages/' . $id);
        $html = (string) $manager->getResponse()->getContent();
        self::assertStringContainsString('mysql://[redacted]@10.0.0.5', $html);
        $this->assertNoLeak($html);
        $manager->request('GET', self::PREFIX . '/history/1');
        $html = (string) $manager->getResponse()->getContent();
        self::assertStringContainsString('Invoice for Jane Doe, DNI [redacted]', $html);
        self::assertStringNotContainsString('<img src=x', $html, 'escaped');
        $this->assertNoLeak($html);
    }

    /** L1: a token captured for one action and target cannot be replayed on another. */
    public function testCsrfTokenReplayAcrossActions(): void
    {
        $kernel = $this->bootKernel();
        $c      = $this->container($kernel);
        $a      = Seeder::failed($c);
        $b      = Seeder::failed($c, new DemoMessage('order-2'));
        Seeder::failed($c, new DemoMessage('order-3'));
        $client = $this->client($kernel);

        $client->request('GET', self::PREFIX . '/transports/failed/messages/' . $a);
        $retryA = ActionForms::formName(MessengerMonitorRoutes::RETRY, ['name' => 'failed', 'id' => $a]);
        $token  = $client->getCrawler()->filter('input[name="' . $retryA . '[_token]"]')->attr('value');
        self::assertNotNull($token);

        $replays = [
            ['/transports/failed/remove-all', MessengerMonitorRoutes::REMOVE_ALL, ['name' => 'failed']],
            ['/transports/failed/retry-all', MessengerMonitorRoutes::RETRY_ALL, ['name' => 'failed']],
            ['/transports/failed/messages/' . $a . '/remove', MessengerMonitorRoutes::REMOVE, ['name' => 'failed', 'id' => $a]],
            ['/transports/failed/messages/' . $b . '/retry', MessengerMonitorRoutes::RETRY, ['name' => 'failed', 'id' => $b]],
            ['/transports/failed/messages/' . $b . '/remove', MessengerMonitorRoutes::REMOVE, ['name' => 'failed', 'id' => $b]],
        ];
        foreach ($replays as [$path, $route, $params]) {
            $client->request('POST', self::PREFIX . $path, [ActionForms::formName($route, $params) => ['_token' => $token], $retryA => ['_token' => $token]]);
            self::assertSame(403, $client->getResponse()->getStatusCode(), $path);
        }
        self::assertSame(3, $this->depth($kernel), 'nothing was retried or removed');

        $client->request('POST', self::PREFIX . '/transports/failed/messages/' . $a . '/retry', [$retryA => ['_token' => $token]]);
        self::assertSame(302, $client->getResponse()->getStatusCode(), 'the token still works for its own action');
        self::assertSame(2, $this->depth($kernel));
    }

    /** I2: with `failure_transports: []` the retrier uses Symfony's failure transport. */
    public function testFailureTransportsComeFromMessenger(): void
    {
        $kernel  = $this->bootKernel();
        $retrier = $this->container($kernel)->get(FailedMessageRetrier::class);
        self::assertInstanceOf(FailedMessageRetrier::class, $retrier);
        self::assertSame(['failed'], (new ReflectionProperty(FailedMessageRetrier::class, 'failureTransports'))->getValue($retrier));
    }

    /** I3: "retry all" leaves duplicate-risk messages parked and says so. */
    public function testRetryAllSkipsDuplicateRiskMessages(): void
    {
        $kernel = $this->bootKernel(['retry' => ['duplicate_risk_classes' => [DemoMessage::class]]]);
        $c      = $this->container($kernel);
        Seeder::failed($c);
        Seeder::failed($c, new DemoMessage('order-2'));
        $client = $this->client($kernel);

        $client->request('GET', self::PREFIX . '/transports/failed');
        self::assertStringContainsString('Duplicate-risk messages are skipped', (string) $client->getResponse()->getContent());
        $client->submit($client->getCrawler()->filter('form[action$="/retry-all"]')->form());
        $client->followRedirect();
        self::assertStringContainsString('0 messages were sent back', (string) $client->getResponse()->getContent());
        self::assertSame(2, $this->depth($kernel));
    }

    private function assertNoLeak(string $html, string ...$absent): void
    {
        foreach ([...self::LEAKS, ...$absent] as $needle) {
            self::assertStringNotContainsString($needle, $html, $needle);
        }
    }

    private function depth(MonitorTestKernel $kernel): int
    {
        $receiver = $this->container($kernel)->get('messenger.transport.failed');
        self::assertInstanceOf(MessageCountAwareInterface::class, $receiver);

        return $receiver->getMessageCount();
    }
}
