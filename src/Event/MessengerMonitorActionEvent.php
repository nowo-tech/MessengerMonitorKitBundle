<?php

declare(strict_types=1);

namespace Nowo\MessengerMonitorKitBundle\Event;

/**
 * Dispatched after every mutation of the admin UI so the host can audit it (AuditKit, an action log, …). The context
 * holds transport names, counts, message short names and settings values — never a payload.
 */
final readonly class MessengerMonitorActionEvent
{
    /** One or more failed messages were sent back to their transport. */
    public const RETRIED = 'retried';

    /** One or more failed messages were deleted. */
    public const REMOVED = 'removed';

    /** History rows were purged from the UI. */
    public const PURGED = 'purged';

    /** The runtime switches changed. */
    public const SETTINGS_UPDATED = 'settings_updated';

    /**
     * @param string $action one of the class constants
     * @param array<string, mixed> $context action details (no payload)
     * @param object|null $user authenticated user (null with allow_unauthenticated)
     */
    public function __construct(
        public string $action,
        public array $context = [],
        public ?object $user = null,
    ) {
    }
}
