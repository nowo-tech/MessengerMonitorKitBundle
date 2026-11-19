<?php

declare(strict_types=1);

namespace Nowo\MessengerMonitorKitBundle\EventListener;

use Nowo\MessengerMonitorKitBundle\Concurrency\PartialCommit;
use Nowo\MessengerMonitorKitBundle\DependencyInjection\Compiler\ConflictResponsePass;
use Nowo\TransactionContract\TransientStorageFailure;
use Nowo\TransactionDoctrine\ConflictCode;
use Nowo\TransactionDoctrine\Symfony\Conflict\ConflictResponsePolicy;
use Nowo\TransactionDoctrine\Symfony\EventListener\ConcurrencyConflictResponder;
use Nowo\TransactionDoctrine\TransientFailureClassifier;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Messenger\Exception\DelayedMessageHandlingException;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Throwable;

use function is_array;
use function is_object;
use function is_string;
use function ltrim;
use function str_starts_with;

/**
 * Since 1.3.0 (REQ-RUNTIME-003 rule 6): a lock conflict on a bundle route answers the platform conflict answer
 * instead of a 500.
 *
 * A lock conflict is a {@see TransientStorageFailure} (transaction-doctrine's ConcurrencyConflict included) or a
 * database failure {@see TransientFailureClassifier} calls transient (deadlock, lock wait timeout, serialization
 * failure: MySQL 1213 / 1205, MariaDB 1020, PostgreSQL 40001 / 40P01 / 55P03) anywhere in the previous chain. It
 * answers **409** (configurable to 503) with `X-Conflict-Code` and a code ({@see ConflictCode::forRequest()}):
 *
 * - `CONFLICT_001` and `Retry-After` when nothing was committed;
 * - `CONFLICT_002` without `Retry-After` when part of the request was: a committed ConcurrencyConflict (a retry whose
 *   message was already sent back, a batch or a purge after its first step, {@see PartialCommit}), a commit since the
 *   request's commit mark (transaction-doctrine >= 1.6 `commit_detection`), or a Messenger failure that shows a
 *   handler already ran (a {@see HandlerFailedException} carrying a {@see HandledStamp}, or any
 *   {@see DelayedMessageHandlingException}).
 *
 * The body is {@see ConcurrencyConflictResponder::respond()}: JSON `{"error", "code", "message"}` for JSON / XHR
 * requests, else the "try again" page. With `conflict_response.error_page: true` the failure becomes an
 * HttpException with the same status and headers, rendered by your error page. Wiring: {@see ConflictResponsePass}.
 *
 * Not conflicts, never mapped: unique violations and lost connections (they stay real errors). Only the bundle's own
 * controllers, never a host route. Priority -64: after the application's own exception listeners, before the
 * framework's error listener (-128). Stateless (worker-safe).
 */
final readonly class MessengerMonitorConflictListener
{
    /** Listener priority. */
    public const PRIORITY = -64;

    /** Generic, client-safe text of the error-page mode. */
    public const MESSAGE = 'The same data was being changed at the same time. Try again.';

    private ConflictResponsePolicy $policy;

    private ConcurrencyConflictResponder $responder;

    /**
     * @param ConflictResponsePolicy|null $policy status (409 or 503) and Retry-After; null = 409 + Retry-After: 1
     * @param ConcurrencyConflictResponder|null $responder builds the answer; null = a plain one with $policy
     * @param bool $errorPage whether the host's error page renders the answer
     */
    public function __construct(
        ?ConflictResponsePolicy $policy = null,
        ?ConcurrencyConflictResponder $responder = null,
        private bool $errorPage = false,
    ) {
        $this->policy    = $policy ?? new ConflictResponsePolicy();
        $this->responder = $responder ?? new ConcurrencyConflictResponder($this->policy);
    }

    /**
     * Answers a lock conflict of a bundle controller.
     */
    #[AsEventListener(event: KernelEvents::EXCEPTION, priority: self::PRIORITY)]
    public function onKernelException(ExceptionEvent $event): void
    {
        $request = $event->getRequest();
        if (!$this->isBundleController($request->attributes->get('_controller'))) {
            return;
        }

        $failure = $event->getThrowable();
        if (!self::isConflict($failure)) {
            return;
        }

        if (self::handlerRan($failure)) {
            $failure = PartialCommit::conflict($failure);
        }

        if ($this->errorPage) {
            $event->setThrowable($this->policy->httpExceptionFor(ConflictCode::forRequest($request, $failure), self::MESSAGE, $failure, $this->hint($failure)));

            return;
        }

        $event->setResponse($this->responder->respond($request, $failure));
    }

    /**
     * Whether the chain holds a lock conflict (never a unique violation or a lost connection on its own).
     */
    public static function isConflict(Throwable $failure): bool
    {
        for ($current = $failure; $current instanceof Throwable; $current = $current->getPrevious()) {
            if ($current instanceof TransientStorageFailure) {
                return true;
            }
        }

        return (new TransientFailureClassifier())->isTransient($failure);
    }

    /**
     * Whether a Messenger failure in the chain shows that a handler already ran (and may have committed).
     */
    public static function handlerRan(Throwable $failure): bool
    {
        for ($current = $failure; $current instanceof Throwable; $current = $current->getPrevious()) {
            if ($current instanceof DelayedMessageHandlingException) {
                return true;
            }
            if ($current instanceof HandlerFailedException && $current->getEnvelope()->all(HandledStamp::class) !== []) {
                return true;
            }
        }

        return false;
    }

    private function hint(Throwable $failure): ?int
    {
        for ($current = $failure; $current instanceof Throwable; $current = $current->getPrevious()) {
            if ($current instanceof TransientStorageFailure) {
                return $current->retryAfterSeconds();
            }
        }

        return null;
    }

    private function isBundleController(mixed $controller): bool
    {
        if (is_array($controller)) {
            $controller = $controller[0] ?? null;
        }
        if (is_object($controller)) {
            $controller = $controller::class;
        }

        return is_string($controller) && str_starts_with(ltrim($controller, '\\'), MessengerMonitorAccessListener::CONTROLLER_NAMESPACE);
    }
}
