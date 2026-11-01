<?php

declare(strict_types=1);

namespace Nowo\MessengerMonitorKitBundle\Controller;

use Nowo\FormKitBundle\Form\GetFilterFormFactory;
use Nowo\MessengerMonitorKitBundle\DependencyInjection\Configuration;
use Nowo\MessengerMonitorKitBundle\Form\HistoryFilterType;
use Nowo\MessengerMonitorKitBundle\Payload\PayloadView;
use Nowo\MessengerMonitorKitBundle\Payload\Redactor;
use Nowo\MessengerMonitorKitBundle\Routing\MessengerMonitorRoutes;
use Nowo\MessengerMonitorKitBundle\Security\AccessGuard;
use Nowo\MessengerMonitorKitBundle\Ui\ActionForms;
use Nowo\MessengerMonitorKitBundle\Ui\MonitorPageFactory;
use Nowo\UiKitBundle\Listing\SortRequest;
use Nowo\UiKitBundle\View\Pagination;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;
use Zenstruck\Messenger\Monitor\History\Period;
use Zenstruck\Messenger\Monitor\History\Specification;
use Zenstruck\Messenger\Monitor\History\Storage;
use Zenstruck\Messenger\Monitor\Transports;

use function array_filter;
use function array_values;
use function in_array;
use function max;
use function min;

/**
 * History list (GET filters, paginated: one count + one page query, no association to load) and one history row with
 * its attempts (same run, capped).
 */
final class HistoryController extends AbstractMonitorController
{
    /** Highest page number accepted (deep offsets are refused, not slow). */
    public const MAX_PAGE = 10_000;

    /** Attempts of one run listed on the detail page. */
    public const MAX_ATTEMPTS = 50;

    /**
     * @param int $perPage default page size (`web_ui.per_page`)
     */
    public function __construct(
        private readonly Storage $storage,
        private readonly Transports $transports,
        private readonly GetFilterFormFactory $filterForms,
        private readonly Redactor $redactor,
        private readonly PayloadView $payloadView,
        private readonly int $perPage,
        AccessGuard $guard,
        MonitorPageFactory $pages,
        ActionForms $actionForms,
        ?EventDispatcherInterface $events = null,
        ?TranslatorInterface $translator = null,
    ) {
        parent::__construct($guard, $pages, $actionForms, $events, $translator);
    }

    /**
     * History list.
     */
    #[Route('/history', name: MessengerMonitorRoutes::HISTORY, methods: ['GET'])]
    public function index(Request $request): Response
    {
        $transportNames = array_values($this->transports->filter()->excludeSync()->names());
        $period         = Period::parse($request->query->getString('period'), Period::IN_LAST_WEEK);
        $status         = $request->query->getString('status');
        $status         = in_array($status, [Specification::SUCCESS, Specification::FAILED], true) ? $status : null;
        $transport      = $request->query->getString('transport');
        $transport      = in_array($transport, $transportNames, true) ? $transport : null;
        $type           = $request->query->getString('type');
        $perPage        = $request->query->getInt('per_page', $this->perPage);
        $perPage        = in_array($perPage, Configuration::PER_PAGE_SIZES, true) ? $perPage : $this->perPage;
        $page           = min(self::MAX_PAGE, max(1, $request->query->getInt('page', 1)));

        $sorting = SortRequest::fromRequest($request, ['finished' => SortRequest::DESC], 'finished', SortRequest::DESC);
        $base    = ['period' => $period, 'status' => $status, 'transport' => $transport];
        $types   = [];
        foreach (Specification::create($base)->filters($this->storage)->availableMessageTypes() as $available) {
            $types[$available->shortName()] = $available->class();
        }
        $type = in_array($type, $types, true) ? $type : null;

        $messages = Specification::create($base + ['message_type' => $type, 'sort' => $sorting->direction === SortRequest::ASC ? Specification::ASC : Specification::DESC])->snapshot($this->storage)->messages()->paginate($page, max(1, $perPage));
        $filters  = $this->filterForms->create(HistoryFilterType::class, [
            'period'    => $period->value,
            'status'    => $status,
            'transport' => $transport,
            'type'      => $type,
            'per_page'  => $perPage,
        ], [
            'action'            => $this->generateUrl(MessengerMonitorRoutes::HISTORY),
            'transport_choices' => $transportNames,
            'type_choices'      => $types,
        ]);

        $query = array_filter(['period' => $period->value, 'status' => $status, 'transport' => $transport, 'type' => $type, 'per_page' => $perPage, 'sort' => $request->query->has('sort') ? $sorting->field : null, 'dir' => $request->query->has('dir') ? $sorting->direction : null], static fn (mixed $v): bool => $v !== null);

        return $this->render($this->pages->template('history'), [
            'page' => $this->pages->history(
                $messages,
                $filters->createView(),
                new Pagination($messages->currentPage(), $perPage, $messages->totalCount(), max(1, $messages->pageCount()), MessengerMonitorRoutes::HISTORY, $query, allowedPerPage: Configuration::PER_PAGE_SIZES),
                $this->canManage(),
                $sorting,
            ),
        ]);
    }

    /**
     * One history row. Viewers get metadata only; managers also get the failure message, the description and the
     * handler results, all redacted.
     */
    #[Route('/history/{id}', name: MessengerMonitorRoutes::HISTORY_SHOW, requirements: ['id' => '[A-Za-z0-9_-]{1,64}'], methods: ['GET'])]
    public function show(string $id): Response
    {
        $message  = $this->storage->find($id) ?? throw $this->createNotFoundException();
        $attempts = $this->storage->filter(Specification::create(['run_id' => $message->runId(), 'sort' => Specification::ASC]))->take(self::MAX_ATTEMPTS);

        $manage  = $this->canManage();
        $results = null;
        if ($manage && $message->results()->count() > 0) {
            $data = [];
            foreach ($message->results() as $result) {
                $data[] = ['handler' => (string) $result->handler(), 'failure' => $result->failure()?->class(), 'data' => $result->data()];
            }
            $results = $this->payloadView->renderData($data, $message->type()->class());
        }

        return $this->render($this->pages->template('history_show'), $this->pages->historyShow(
            $message,
            $attempts,
            $results,
            $manage ? $this->redactor->redactText($message->failure()?->description()) : null,
            $manage ? $this->redactor->redactText($message->description()) : null,
            $manage,
        ));
    }
}
