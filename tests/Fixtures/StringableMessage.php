<?php

declare(strict_types=1);

namespace Nowo\MessengerMonitorKitBundle\Tests\Fixtures;

use Stringable;

/**
 * Message whose `__toString()` (zenstruck's history description) carries personal data.
 */
final readonly class StringableMessage implements Stringable
{
    public function __toString(): string
    {
        return 'Invoice for Jane Doe, DNI 12345678Z, card 4111.1111.1111.1111, <img src=x onerror=alert(1)>';
    }
}
