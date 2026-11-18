<?php

declare(strict_types=1);

namespace Nowo\MessengerMonitorKitBundle\Tests\Fixtures;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Throwable;

/**
 * Bus middleware that throws `$failure` (once) instead of sending: a lock conflict met by the dispatch of a retry.
 */
final class FailingMiddleware implements MiddlewareInterface
{
    public ?Throwable $failure = null;

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        $failure       = $this->failure;
        $this->failure = null;
        if ($failure instanceof Throwable) {
            throw $failure;
        }

        return $stack->next()->handle($envelope, $stack);
    }
}
