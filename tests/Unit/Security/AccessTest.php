<?php

declare(strict_types=1);

namespace Nowo\MessengerMonitorKitBundle\Tests\Unit\Security;

use Nowo\MessengerMonitorKitBundle\Security\AccessGuard;
use Nowo\MessengerMonitorKitBundle\Security\AllowAllMessengerMonitorAccessChecker;
use Nowo\MessengerMonitorKitBundle\Security\ConfigurableMessengerMonitorAccessChecker;
use Nowo\MessengerMonitorKitBundle\Security\MessengerMonitorAccessCheckerInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Bundle\SecurityBundle\Security\FirewallConfig;
use Symfony\Bundle\SecurityBundle\Security\FirewallMap;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\AccessDecision;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\User\InMemoryUser;

use function in_array;

final class AccessTest extends TestCase
{
    #[Test]
    public function theRoleCheckerSeparatesViewAndManage(): void
    {
        $checker = new ConfigurableMessengerMonitorAccessChecker($this->granted(['ROLE_ADMIN']), ['ROLE_ADMIN'], ['ROLE_OPS']);
        $user    = new stdClass();

        self::assertTrue($checker->canAccess($user));
        self::assertFalse($checker->canAccess($user, MessengerMonitorAccessCheckerInterface::AREA_MANAGE));
        self::assertFalse($checker->canAccess(null));
        self::assertFalse((new ConfigurableMessengerMonitorAccessChecker(null, ['ROLE_ADMIN']))->canAccess($user));
        self::assertTrue((new ConfigurableMessengerMonitorAccessChecker($this->granted(['ROLE_ADMIN']), ['ROLE_ADMIN']))->canAccess($user, MessengerMonitorAccessCheckerInterface::AREA_MANAGE), 'manage inherits the access roles');
        self::assertFalse((new ConfigurableMessengerMonitorAccessChecker($this->granted(['ROLE_ADMIN']), []))->canAccess($user), 'an empty list denies');
        self::assertTrue((new AllowAllMessengerMonitorAccessChecker())->canAccess(null, 'manage'));
    }

    #[Test]
    public function theGuardTrustsOnlyFirewalledRequests(): void
    {
        $tokens = new TokenStorage();
        $tokens->setToken(new UsernamePasswordToken(new InMemoryUser('admin', null, ['ROLE_ADMIN']), 'main', ['ROLE_ADMIN']));
        $stack   = new RequestStack();
        $request = Request::create('/admin/messenger');
        $stack->push($request);
        $checker = new ConfigurableMessengerMonitorAccessChecker($this->granted(['ROLE_ADMIN']), ['ROLE_ADMIN']);
        $guard   = new AccessGuard($checker, false, $tokens, $stack);

        self::assertNull($guard->user(), 'no firewall context: the token is not trusted');
        self::assertFalse($guard->isGranted());
        $request->attributes->set('_firewall_context', 'security.firewall.map.context.main');
        $trusted = new AccessGuard($checker, false, $tokens, $stack);
        self::assertInstanceOf(InMemoryUser::class, $trusted->user());
        self::assertTrue($trusted->isGranted());
        $trusted->denyUnlessGranted();

        self::assertTrue((new AccessGuard($checker, true))->isGranted('manage'));
        self::assertNull((new AccessGuard($checker))->user());
    }

    #[Test]
    public function denialsUseTheFirewallWhenItIsEnabled(): void
    {
        $stack   = new RequestStack();
        $request = Request::create('/admin/messenger');
        $request->attributes->set('_firewall_context', 'ctx');
        $stack->push($request);
        $map = new class(new Container(), []) extends FirewallMap {
            public function getFirewallConfig(Request $request): FirewallConfig
            {
                return new FirewallConfig('main', 'user_checker');
            }
        };
        $guard = new AccessGuard(new ConfigurableMessengerMonitorAccessChecker(null, ['ROLE_ADMIN']), false, new TokenStorage(), $stack, $map);

        try {
            $guard->denyUnlessGranted();
            self::fail('denied');
        } catch (AccessDeniedException) {
            $this->addToAssertionCount(1);
        }

        $this->expectException(AccessDeniedHttpException::class);
        (new AccessGuard(new ConfigurableMessengerMonitorAccessChecker(null, ['ROLE_ADMIN'])))->deny('no firewall');
    }

    /**
     * @param list<string> $roles
     */
    private function granted(array $roles): AuthorizationCheckerInterface
    {
        return new class($roles) implements AuthorizationCheckerInterface {
            /**
             * @param list<string> $roles
             */
            public function __construct(private array $roles)
            {
            }

            public function isGranted(mixed $attribute, mixed $subject = null, ?AccessDecision $accessDecision = null): bool
            {
                return in_array($attribute, $this->roles, true);
            }
        };
    }
}
