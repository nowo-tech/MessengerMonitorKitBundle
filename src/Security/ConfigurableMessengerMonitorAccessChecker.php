<?php

declare(strict_types=1);

namespace Nowo\MessengerMonitorKitBundle\Security;

use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Role-based default checker: `security.access_roles` for the read-only pages, `security.manage_roles` (default: the
 * access roles) for mutations and payloads. An empty role list, an anonymous user or a missing SecurityBundle denies
 * (fail closed).
 */
final readonly class ConfigurableMessengerMonitorAccessChecker implements MessengerMonitorAccessCheckerInterface
{
    /**
     * @param list<string> $accessRoles roles that open the read-only pages (any of them)
     * @param list<string>|null $manageRoles roles allowed to manage; null = `$accessRoles`
     */
    public function __construct(
        private ?AuthorizationCheckerInterface $authorizationChecker,
        private array $accessRoles,
        private ?array $manageRoles = null,
    ) {
    }

    /**
     * Grants when the user holds any role of the area; anonymous and empty role lists are denied.
     */
    public function canAccess(?object $user, string $area = self::AREA_VIEW): bool
    {
        if ($user === null || !$this->authorizationChecker instanceof AuthorizationCheckerInterface) {
            return false;
        }

        $roles = $area === self::AREA_MANAGE ? ($this->manageRoles ?? $this->accessRoles) : $this->accessRoles;
        foreach ($roles as $role) {
            if ($this->authorizationChecker->isGranted($role)) {
                return true;
            }
        }

        return false;
    }
}
