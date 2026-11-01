<?php

declare(strict_types=1);

namespace Nowo\MessengerMonitorKitBundle\Security;

/**
 * Grants everything: wired only with `security.allow_unauthenticated: true`, which the container accepts only when
 * `kernel.debug` is true (dev / demo).
 */
final class AllowAllMessengerMonitorAccessChecker implements MessengerMonitorAccessCheckerInterface
{
    /**
     * Always true.
     */
    public function canAccess(?object $user, string $area = self::AREA_VIEW): bool
    {
        return true;
    }
}
