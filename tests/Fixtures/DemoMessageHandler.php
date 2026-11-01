<?php

declare(strict_types=1);

namespace Nowo\MessengerMonitorKitBundle\Tests\Fixtures;

use RuntimeException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Handles {@see DemoMessage}; fails for order ids starting with "fail".
 */
#[AsMessageHandler]
final class DemoMessageHandler
{
    /** @var list<string> */
    public array $handled = [];

    public function __invoke(DemoMessage $message): string
    {
        if (str_starts_with($message->orderId, 'fail')) {
            throw new RuntimeException('Payment refused for ' . $message->email);
        }
        $this->handled[] = $message->orderId;

        return 'sent to ' . $message->email;
    }
}
