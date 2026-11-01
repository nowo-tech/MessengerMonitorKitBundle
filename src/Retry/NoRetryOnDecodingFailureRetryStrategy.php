<?php

declare(strict_types=1);

namespace Nowo\MessengerMonitorKitBundle\Retry;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\MessageDecodingFailedException;
use Symfony\Component\Messenger\Retry\RetryStrategyInterface;
use Throwable;

/**
 * Sends messages that cannot be decoded straight to the failure transport instead of retrying them.
 *
 * A message that fails to decode (unknown class, signature mismatch with a signing serializer, corrupt body) cannot
 * succeed on a retry; on some Messenger releases the re-emitted message also lost its redelivery stamp, so every retry
 * was "retry #1" — an endless loop (fixed upstream in symfony/messenger 8.1.9, symfony/symfony#66449 / #66452). The
 * decorator is harmless on fixed releases. Enabled per transport with `retry_strategy.no_retry_on_decoding_failure`;
 * `max_retries` / delays stay in the transport configuration.
 */
final readonly class NoRetryOnDecodingFailureRetryStrategy implements RetryStrategyInterface
{
    /**
     * @param RetryStrategyInterface $inner the transport's configured strategy
     */
    public function __construct(
        private RetryStrategyInterface $inner,
    ) {
    }

    /**
     * False for decoding failures, else the inner strategy.
     */
    public function isRetryable(Envelope $message, ?Throwable $throwable = null): bool
    {
        if ($throwable instanceof MessageDecodingFailedException || $message->getMessage() instanceof MessageDecodingFailedException) {
            return false;
        }

        return $this->inner->isRetryable($message, $throwable);
    }

    /**
     * Inner waiting time.
     */
    public function getWaitingTime(Envelope $message, ?Throwable $throwable = null): int
    {
        return $this->inner->getWaitingTime($message, $throwable);
    }
}
