<?php

declare(strict_types=1);

namespace Nowo\MessengerMonitorKitBundle\Controller;

use Nowo\MessengerMonitorKitBundle\Queue\QueueDepth;
use Nowo\MessengerMonitorKitBundle\Routing\MessengerMonitorRoutes;
use Nowo\MessengerMonitorKitBundle\Security\AccessGuard;
use Nowo\MessengerMonitorKitBundle\Settings\MonitorSettingsStoreInterface;
use Nowo\MessengerMonitorKitBundle\Ui\ActionForms;
use Nowo\MessengerMonitorKitBundle\Ui\MonitorPageFactory;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;
use Zenstruck\Messenger\Monitor\History\Period;
use Zenstruck\Messenger\Monitor\History\Specification;
use Zenstruck\Messenger\Monitor\History\Storage;
use Zenstruck\Messenger\Monitor\Workers;

/**
 * Dashboard: last 24 hours (processed, failed, average wait / handling), queue depth, workers, recording state and
 * the latest failures. Bounded queries: four aggregates, one 10-row page, one count per transport.
 */
final class DashboardController extends AbstractMonitorController
{
    /** Failed rows listed on the dashboard. */
    public const RECENT_FAILURES = 10;

    /**
     * @param Storage $storage history storage
     * @param Workers $workers running workers
     * @param QueueDepth $queueDepth transport counts
     * @param MonitorSettingsStoreInterface $settings runtime switches
     */
    public function __construct(
        private readonly Storage $storage,
        private readonly Workers $workers,
        private readonly QueueDepth $queueDepth,
        private readonly MonitorSettingsStoreInterface $settings,
        AccessGuard $guard,
        MonitorPageFactory $pages,
        ActionForms $actionForms,
        ?EventDispatcherInterface $events = null,
        ?TranslatorInterface $translator = null,
    ) {
        parent::__construct($guard, $pages, $actionForms, $events, $translator);
    }

    /**
     * Dashboard page.
     */
    #[Route('', name: MessengerMonitorRoutes::DASHBOARD, methods: ['GET'])]
    public function index(): Response
    {
        $snapshot = Specification::create(Period::IN_LAST_DAY)->snapshot($this->storage);
        $failures = Specification::create(['period' => Period::IN_LAST_WEEK, 'status' => Specification::FAILED])->snapshot($this->storage)->messages()->take(self::RECENT_FAILURES);

        return $this->render($this->pages->template('dashboard'), $this->pages->dashboard(
            $snapshot->totalCount(),
            $snapshot->failureCount(),
            $snapshot->averageWaitTime(),
            $snapshot->averageHandlingTime(),
            $this->queueDepth->report(),
            $this->workers->all(),
            $this->settings->load(),
            $failures,
            $this->canManage(),
        ));
    }
}
