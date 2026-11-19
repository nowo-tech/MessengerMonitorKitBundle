<?php

declare(strict_types=1);

namespace Nowo\MessengerMonitorKitBundle\Tests\Integration\Functional;

use Nowo\MessengerMonitorKitBundle\Tests\Fixtures\DemoMessage;
use Nowo\MessengerMonitorKitBundle\Tests\Fixtures\FailingMiddleware;
use Nowo\MessengerMonitorKitBundle\Tests\Integration\Kernel\KernelTestCase;
use Nowo\MessengerMonitorKitBundle\Tests\Integration\Kernel\MonitorTestKernel;
use Nowo\MessengerMonitorKitBundle\Tests\Support\DbalErrors;
use Nowo\MessengerMonitorKitBundle\Tests\Support\Seeder;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Messenger\Transport\Receiver\MessageCountAwareInterface;
use Throwable;

/**
 * REQ-RUNTIME-003 rule 6 end to end: a lock conflict on a bundle route answers 409 + X-Conflict-Code (CONFLICT_001 with
 * Retry-After, CONFLICT_002 without), configurable to 503 or to the host's error page; other failures stay errors.
 */
final class ConcurrencyConflictTest extends KernelTestCase
{
    private const PREFIX = '/admin/messenger';

    public function testALockConflictOfARetryAnswers409WithConflict001(): void
    {
        [$kernel, $client, $id] = $this->retryFailing(DbalErrors::deadlock());

        self::assertSame(409, $client->getResponse()->getStatusCode());
        self::assertSame('CONFLICT_001', $client->getResponse()->headers->get('X-Conflict-Code'));
        self::assertSame('1', $client->getResponse()->headers->get('Retry-After'));
        self::assertStringContainsString('CONFLICT_001', (string) $client->getResponse()->getContent());
        self::assertSame(0, $this->depth($kernel, 'async'), 'nothing was sent');
        self::assertSame(1, $this->depth($kernel, 'failed'), 'the message is still parked');

        $client->request('GET', self::PREFIX . '/transports/failed');
        $client->submit($client->getCrawler()->filter('form[action$="/messages/' . $id . '/retry"]')->form());
        self::assertSame(302, $client->getResponse()->getStatusCode(), 'the retry succeeds once the conflict is gone');
        self::assertSame(1, $this->depth($kernel, 'async'));
    }

    public function testAHandlerThatAlreadyRanAnswersConflict002(): void
    {
        $failure    = new HandlerFailedException(new Envelope(new DemoMessage(), [new HandledStamp(null, 'App\\Handler')]), [DbalErrors::deadlock()]);
        [, $client] = $this->retryFailing($failure);

        self::assertSame(409, $client->getResponse()->getStatusCode());
        self::assertSame('CONFLICT_002', $client->getResponse()->headers->get('X-Conflict-Code'));
        self::assertFalse($client->getResponse()->headers->has('Retry-After'));
    }

    public function testTheStatusIsConfigurableTo503AndToTheErrorPage(): void
    {
        [, $client] = $this->retryFailing(DbalErrors::lockWaitTimeout(), ['conflict_response' => ['status' => 503, 'error_page' => true]]);

        self::assertSame(503, $client->getResponse()->getStatusCode());
        self::assertSame('CONFLICT_001', $client->getResponse()->headers->get('X-Conflict-Code'));
        self::assertSame('1', $client->getResponse()->headers->get('Retry-After'));
    }

    public function testAUniqueViolationIsNotAConflict(): void
    {
        [, $client] = $this->retryFailing(DbalErrors::uniqueViolation());

        self::assertSame(500, $client->getResponse()->getStatusCode());
        self::assertFalse($client->getResponse()->headers->has('X-Conflict-Code'));
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array{MonitorTestKernel, KernelBrowser, string}
     */
    private function retryFailing(Throwable $failure, array $config = []): array
    {
        $kernel = $this->bootKernel(
            $config,
            [FailingMiddleware::class => FailingMiddleware::class],
            extensions: ['framework' => ['messenger' => ['buses' => ['messenger.bus.default' => ['middleware' => [FailingMiddleware::class]]]]]],
        );
        $c      = $this->container($kernel);
        $id     = Seeder::failed($c);
        $client = $this->client($kernel);

        $client->request('GET', self::PREFIX . '/transports/failed');
        $form       = $client->getCrawler()->filter('form[action$="/messages/' . $id . '/retry"]')->form();
        $middleware = $c->get(FailingMiddleware::class);
        self::assertInstanceOf(FailingMiddleware::class, $middleware);
        $middleware->failure = $failure;
        $client->submit($form);

        return [$kernel, $client, $id];
    }

    private function depth(MonitorTestKernel $kernel, string $transport): int
    {
        $receiver = $this->container($kernel)->get('messenger.transport.' . $transport);
        self::assertInstanceOf(MessageCountAwareInterface::class, $receiver);

        return $receiver->getMessageCount();
    }
}
