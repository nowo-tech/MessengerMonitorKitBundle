<?php

declare(strict_types=1);

namespace Nowo\MessengerMonitorKitBundle\Tests\Unit\Recording;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\MessengerMonitorKitBundle\Recording\OwnHistoryManagerSubscriber;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

#[CoversClass(OwnHistoryManagerSubscriber::class)]
final class OwnHistoryManagerSubscriberTest extends TestCase
{
    #[Test]
    public function recoversOnlyAClosedOwnManagerOnTheMainRequest(): void
    {
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('isOpen')->willReturnOnConsecutiveCalls(false, true);
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->expects(self::exactly(2))->method('getManager')->with('monitor')->willReturn($em);
        $registry->expects(self::once())->method('resetManager')->with('monitor');

        $subscriber = new OwnHistoryManagerSubscriber($registry, 'monitor');
        $kernel     = $this->createStub(HttpKernelInterface::class);
        $subscriber->onRequest(new RequestEvent($kernel, Request::create('/'), HttpKernelInterface::SUB_REQUEST));
        $subscriber->onRequest(new RequestEvent($kernel, Request::create('/'), HttpKernelInterface::MAIN_REQUEST));
        $subscriber->recover();
    }

    #[Test]
    public function clearsTheOpenOwnManagerOnly(): void
    {
        $open = $this->createMock(EntityManagerInterface::class);
        $open->method('isOpen')->willReturn(true);
        $open->expects(self::once())->method('clear');
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManager')->willReturn($open);
        (new OwnHistoryManagerSubscriber($registry, 'monitor'))->clear();

        $closed = $this->createMock(EntityManagerInterface::class);
        $closed->method('isOpen')->willReturn(false);
        $closed->expects(self::never())->method('clear');
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManager')->willReturn($closed);
        (new OwnHistoryManagerSubscriber($registry, 'monitor'))->clear();
    }
}
