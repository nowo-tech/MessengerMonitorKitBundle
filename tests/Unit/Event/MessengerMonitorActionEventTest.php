<?php

declare(strict_types=1);

namespace Nowo\MessengerMonitorKitBundle\Tests\Unit\Event;

use Nowo\MessengerMonitorKitBundle\Entity\ProcessedMessage;
use Nowo\MessengerMonitorKitBundle\Event\MessengerMonitorActionEvent;
use Nowo\MessengerMonitorKitBundle\Tests\Fixtures\DemoMessage;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\Messenger\Envelope;
use Zenstruck\Messenger\Monitor\History\Model\Results;
use Zenstruck\Messenger\Monitor\Stamp\MonitorStamp;

final class MessengerMonitorActionEventTest extends TestCase
{
    #[Test]
    public function itCarriesTheActionContextAndUser(): void
    {
        $user  = new stdClass();
        $event = new MessengerMonitorActionEvent(MessengerMonitorActionEvent::PURGED, ['deleted' => 3], $user);
        self::assertSame('purged', $event->action);
        self::assertSame(['deleted' => 3], $event->context);
        self::assertSame($user, $event->user);
    }

    #[Test]
    public function theEntityExposesItsId(): void
    {
        $entity = new ProcessedMessage(new Envelope(new DemoMessage(), [(new MonitorStamp())->markReceived('async')->markFinished()]), new Results([]));
        self::assertNull($entity->id());
        self::assertSame(5, $entity->setId(5)->id());
    }
}
