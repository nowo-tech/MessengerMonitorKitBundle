<?php

declare(strict_types=1);

namespace Nowo\MessengerMonitorKitBundle\Twig;

use Nowo\MessengerMonitorKitBundle\Ui\MonitorFormat;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * `|nowo_messenger_monitor_duration` — compact durations of milliseconds (`850 ms`, `2 min 5 s`) for host templates
 * and overrides of the monitor pages.
 */
final class MessengerMonitorTwigExtension extends AbstractExtension
{
    /**
     * The duration filter.
     *
     * @return list<TwigFilter>
     */
    public function getFilters(): array
    {
        return [new TwigFilter('nowo_messenger_monitor_duration', MonitorFormat::duration(...))];
    }
}
