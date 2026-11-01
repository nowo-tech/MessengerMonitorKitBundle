<?php

declare(strict_types=1);

namespace Nowo\MessengerMonitorKitBundle\Tests\Unit\Retry;

use Nowo\MessengerMonitorKitBundle\Retry\NoRetryOnDecodingFailureRetryStrategy;
use Nowo\MessengerMonitorKitBundle\Tests\Fixtures\DemoMessage;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\MessageDecodingFailedException;
use Symfony\Component\Messenger\Retry\MultiplierRetryStrategy;

final class NoRetryOnDecodingFailureRetryStrategyTest extends TestCase
{
    #[Test]
    public function decodingFailuresAreNeverRetried(): void
    {
        $strategy = new NoRetryOnDecodingFailureRetryStrategy(new MultiplierRetryStrategy(3, 1000, 1, 0, 0));
        $envelope = new Envelope(new DemoMessage());

        self::assertFalse($strategy->isRetryable($envelope, new MessageDecodingFailedException('bad body')));
        self::assertFalse($strategy->isRetryable(new Envelope(new MessageDecodingFailedException('bad body'))));
        self::assertTrue($strategy->isRetryable($envelope, new RuntimeException('transient')));
        self::assertSame(1000, $strategy->getWaitingTime($envelope, new RuntimeException('transient')));
    }
}
