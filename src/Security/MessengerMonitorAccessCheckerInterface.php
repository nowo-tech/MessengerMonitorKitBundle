<?php

declare(strict_types=1);

namespace Nowo\MessengerMonitorKitBundle\Security;

/**
 * Decides who may use the messenger monitor admin UI (REQ-UI-002). Set `security.access_checker` to a host service id
 * implementing this interface to replace the role-based default.
 */
interface MessengerMonitorAccessCheckerInterface
{
    /** Read-only pages: dashboard, history, transports, failed message lists. */
    public const AREA_VIEW = 'view';

    /** Mutations (retry, remove, purge, settings) and message payloads. */
    public const AREA_MANAGE = 'manage';

    /**
     * Whether the user may use an area ({@see self::AREA_VIEW} or {@see self::AREA_MANAGE}).
     *
     * @param object|null $user authenticated user of the main request firewall (null = anonymous)
     */
    public function canAccess(?object $user, string $area = self::AREA_VIEW): bool;
}
