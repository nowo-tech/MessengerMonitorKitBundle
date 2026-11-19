<?php

declare(strict_types=1);

namespace Nowo\MessengerMonitorKitBundle\Tests\Unit\EventListener;

use Nowo\MessengerMonitorKitBundle\Controller\SettingsController;
use Nowo\MessengerMonitorKitBundle\Controller\TransportsController;
use Nowo\MessengerMonitorKitBundle\EventListener\MessengerMonitorConflictListener;
use Nowo\MessengerMonitorKitBundle\Tests\Fixtures\DemoMessage;
use Nowo\MessengerMonitorKitBundle\Tests\Support\DbalErrors;
use Nowo\TransactionContract\TransientStorageFailure;
use Nowo\TransactionDoctrine\Symfony\Conflict\ConflictResponsePolicy;
use Nowo\TransactionDoctrine\TransientFailureKind;
use Nowo\TransactionDoctrine\WriteUnit\CommitLedger;
use Nowo\TransactionDoctrine\WriteUnit\CommitMark;
use Nowo\TransactionDoctrine\WriteUnit\ConcurrencyConflict;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\DelayedMessageHandlingException;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Throwable;

use function json_decode;

final class MessengerMonitorConflictListenerTest extends TestCase
{
    /**
     * @return iterable<string, array{Throwable}>
     */
    public static function retrySafe(): iterable
    {
        yield 'deadlock' => [DbalErrors::deadlock()];
        yield 'lock wait timeout' => [DbalErrors::lockWaitTimeout()];
        yield 'wrapped by the transport' => [new RuntimeException('transport', 0, DbalErrors::deadlock())];
        yield 'uncommitted write unit conflict' => [new ConcurrencyConflict(2, TransientFailureKind::Deadlock, null, DbalErrors::deadlock())];
        yield 'handler failed before any handler succeeded' => [new HandlerFailedException(new Envelope(new DemoMessage()), [DbalErrors::deadlock()])];
    }

    #[Test]
    #[DataProvider('retrySafe')]
    public function aLockConflictWithNothingCommittedAnswers001(Throwable $failure): void
    {
        $response = $this->respond($failure);

        self::assertSame(409, $response->getStatusCode());
        self::assertSame('CONFLICT_001', $response->headers->get('X-Conflict-Code'));
        self::assertSame('1', $response->headers->get('Retry-After'));
    }

    /**
     * @return iterable<string, array{Throwable}>
     */
    public static function committed(): iterable
    {
        yield 'committed write unit conflict' => [new ConcurrencyConflict(1, TransientFailureKind::Deadlock, null, DbalErrors::deadlock(), committed: true)];
        yield 'a handler already ran' => [new HandlerFailedException(new Envelope(new DemoMessage(), [new HandledStamp(null, 'App\\Handler')]), [DbalErrors::deadlock()])];
        yield 'delayed message handling' => [new DelayedMessageHandlingException([DbalErrors::lockWaitTimeout()], new Envelope(new DemoMessage()))];
    }

    #[Test]
    #[DataProvider('committed')]
    public function aLockConflictAfterACommitAnswers002WithoutRetryAfter(Throwable $failure): void
    {
        $response = $this->respond($failure);

        self::assertSame(409, $response->getStatusCode());
        self::assertSame('CONFLICT_002', $response->headers->get('X-Conflict-Code'));
        self::assertFalse($response->headers->has('Retry-After'));
    }

    #[Test]
    public function aCommitEarlierInTheRequestAnswers002(): void
    {
        $ledger  = new CommitLedger();
        $request = $this->request(TransportsController::class . '::retry');
        $request->attributes->set(CommitMark::REQUEST_ATTRIBUTE, new CommitMark($ledger));
        $ledger->record();

        $response = $this->respond(DbalErrors::deadlock(), $request);
        self::assertSame('CONFLICT_002', $response->headers->get('X-Conflict-Code'));
    }

    #[Test]
    public function jsonRequestsGetTheCodeInTheBody(): void
    {
        $request = $this->request(TransportsController::class . '::retry');
        $request->headers->set('Accept', 'application/json');

        $body = json_decode((string) $this->respond(DbalErrors::deadlock(), $request)->getContent(), true);
        self::assertIsArray($body);
        self::assertSame('CONFLICT_001', $body['code']);
    }

    /**
     * @return iterable<string, array{Throwable}>
     */
    public static function notConflicts(): iterable
    {
        yield 'unique violation' => [DbalErrors::uniqueViolation()];
        yield 'lost connection' => [DbalErrors::connectionLost()];
        yield 'bug' => [new RuntimeException('bug')];
    }

    #[Test]
    #[DataProvider('notConflicts')]
    public function otherFailuresAreNeverMapped(Throwable $failure): void
    {
        $event = $this->event($failure, $this->request(TransportsController::class . '::retry'));
        (new MessengerMonitorConflictListener())->onKernelException($event);

        self::assertNull($event->getResponse());
        self::assertSame($failure, $event->getThrowable());
        self::assertFalse(MessengerMonitorConflictListener::isConflict($failure));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function hostControllers(): iterable
    {
        yield 'host controller' => ['App\\Controller\\OrderController::save'];
        yield 'no controller' => [null];
        yield 'closure' => [static fn (): null => null];
    }

    #[Test]
    #[DataProvider('hostControllers')]
    public function hostRoutesAreNeverAnswered(mixed $controller): void
    {
        $request = Request::create('/orders', 'POST');
        $request->attributes->set('_controller', $controller);
        $event = $this->event(DbalErrors::deadlock(), $request);
        (new MessengerMonitorConflictListener())->onKernelException($event);

        self::assertNull($event->getResponse());
    }

    #[Test]
    public function arrayAndObjectControllersOfTheBundleAreAnswered(): void
    {
        $response = $this->respond(DbalErrors::deadlock(), $this->request([SettingsController::class, 'purge']));
        self::assertSame(409, $response->getStatusCode());

        $object   = (new ReflectionClass(SettingsController::class))->newInstanceWithoutConstructor();
        $response = $this->respond(DbalErrors::deadlock(), $this->request([$object, 'purge']));
        self::assertSame(409, $response->getStatusCode());
    }

    #[Test]
    public function theErrorPageModeThrowsAnHttpExceptionWithTheHeaders(): void
    {
        $listener = new MessengerMonitorConflictListener(new ConflictResponsePolicy(503, 7), errorPage: true);

        $event = $this->event(DbalErrors::deadlock(), $this->request(TransportsController::class . '::remove'));
        $listener->onKernelException($event);
        $http = $event->getThrowable();
        self::assertInstanceOf(HttpException::class, $http);
        self::assertSame(503, $http->getStatusCode());
        self::assertSame(['X-Conflict-Code' => 'CONFLICT_001', 'Retry-After' => '7'], $http->getHeaders());
        self::assertSame(MessengerMonitorConflictListener::MESSAGE, $http->getMessage());

        $hinted = new TransientStorageFailure(3, 'transient', DbalErrors::deadlock());
        $event  = $this->event($hinted, $this->request(TransportsController::class . '::remove'));
        $listener->onKernelException($event);
        $http = $event->getThrowable();
        self::assertInstanceOf(HttpException::class, $http);
        self::assertSame('3', $http->getHeaders()['Retry-After'] ?? null, "the failure's own hint");

        $event = $this->event(new ConcurrencyConflict(1, TransientFailureKind::Deadlock, null, DbalErrors::deadlock(), committed: true), $this->request(TransportsController::class . '::retry'));
        $listener->onKernelException($event);
        $http = $event->getThrowable();
        self::assertInstanceOf(HttpException::class, $http);
        self::assertSame(['X-Conflict-Code' => 'CONFLICT_002'], $http->getHeaders());
    }

    private function respond(Throwable $failure, ?Request $request = null): Response
    {
        $event = $this->event($failure, $request ?? $this->request(TransportsController::class . '::retry'));
        (new MessengerMonitorConflictListener())->onKernelException($event);
        $response = $event->getResponse();
        self::assertInstanceOf(Response::class, $response);

        return $response;
    }

    private function request(mixed $controller): Request
    {
        $request = Request::create('/admin/messenger/transports/failed/retry-all', 'POST');
        $request->attributes->set('_controller', $controller);

        return $request;
    }

    private function event(Throwable $failure, Request $request): ExceptionEvent
    {
        return new ExceptionEvent($this->createStub(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST, $failure);
    }
}
