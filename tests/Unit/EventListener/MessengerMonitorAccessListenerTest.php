<?php

declare(strict_types=1);

namespace Nowo\MessengerMonitorKitBundle\Tests\Unit\EventListener;

use Nowo\MessengerMonitorKitBundle\Controller\DashboardController;
use Nowo\MessengerMonitorKitBundle\EventListener\MessengerMonitorAccessListener;
use Nowo\MessengerMonitorKitBundle\Security\AccessGuard;
use Nowo\MessengerMonitorKitBundle\Security\ConfigurableMessengerMonitorAccessChecker;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class MessengerMonitorAccessListenerTest extends TestCase
{
    #[Test]
    public function itIgnoresOtherPathsAndSubRequestsOfOtherControllers(): void
    {
        $listener = $this->listener();
        $listener->onKernelRequest($this->event('/admin/messengers'));
        $listener->onKernelRequest($this->event('/', HttpKernelInterface::SUB_REQUEST));
        $this->addToAssertionCount(1);
    }

    /**
     * @return iterable<string, array{string, int, mixed}>
     */
    public static function guardedRequests(): iterable
    {
        yield 'prefix' => ['/admin/messenger', HttpKernelInterface::MAIN_REQUEST, null];
        yield 'below the prefix' => ['/admin/messenger/history', HttpKernelInterface::MAIN_REQUEST, null];
        yield 'bundle controller elsewhere' => ['/other', HttpKernelInterface::MAIN_REQUEST, DashboardController::class . '::index'];
        yield 'bundle controller sub-request' => ['/', HttpKernelInterface::SUB_REQUEST, [DashboardController::class, 'index']];
    }

    #[Test]
    #[DataProvider('guardedRequests')]
    public function itDeniesGuardedRequests(string $path, int $type, mixed $controller): void
    {
        $this->expectException(AccessDeniedHttpException::class);
        $this->listener()->onKernelRequest($this->event($path, $type, $controller));
    }

    private function listener(): MessengerMonitorAccessListener
    {
        return new MessengerMonitorAccessListener(new AccessGuard(new ConfigurableMessengerMonitorAccessChecker(null, ['ROLE_ADMIN'])), '/admin/messenger/');
    }

    private function event(string $path, int $type = HttpKernelInterface::MAIN_REQUEST, mixed $controller = null): RequestEvent
    {
        $request = Request::create($path);
        if ($controller !== null) {
            $request->attributes->set('_controller', $controller);
        }

        return new RequestEvent($this->createStub(HttpKernelInterface::class), $request, $type);
    }
}
