<?php

declare(strict_types=1);

namespace Nowo\MessengerMonitorKitBundle\Routing;

/**
 * Public route names of the admin UI (stable API): link from the host menu with `path(MessengerMonitorRoutes::DASHBOARD)`.
 */
final class MessengerMonitorRoutes
{
    /** Dashboard. */
    public const DASHBOARD = 'nowo_messenger_monitor_kit_dashboard';

    /** History list (GET filters). */
    public const HISTORY = 'nowo_messenger_monitor_kit_history';

    /** History row; parameter `id`. */
    public const HISTORY_SHOW = 'nowo_messenger_monitor_kit_history_show';

    /** Transports and their depth. */
    public const TRANSPORTS = 'nowo_messenger_monitor_kit_transports';

    /** Queued messages of a listable transport; parameter `name`. */
    public const TRANSPORT = 'nowo_messenger_monitor_kit_transport';

    /** One queued message; parameters `name`, `id`. */
    public const MESSAGE = 'nowo_messenger_monitor_kit_message';

    /** Retry one failed message (POST + CSRF). */
    public const RETRY = 'nowo_messenger_monitor_kit_retry';

    /** Remove one failed message (POST + CSRF). */
    public const REMOVE = 'nowo_messenger_monitor_kit_remove';

    /** Retry the first `retry.batch_limit` failed messages (POST + CSRF). */
    public const RETRY_ALL = 'nowo_messenger_monitor_kit_retry_all';

    /** Remove the first `retry.batch_limit` failed messages (POST + CSRF). */
    public const REMOVE_ALL = 'nowo_messenger_monitor_kit_remove_all';

    /** Settings (GET, POST + CSRF). */
    public const SETTINGS = 'nowo_messenger_monitor_kit_settings';

    /** Manual purge (POST + CSRF). */
    public const PURGE = 'nowo_messenger_monitor_kit_purge';
}
