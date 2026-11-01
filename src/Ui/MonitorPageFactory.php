<?php

declare(strict_types=1);

namespace Nowo\MessengerMonitorKitBundle\Ui;

use DateTimeImmutable;
use Nowo\FormKitBundle\Form\CsrfOnlyFormFactory;
use Nowo\MessengerMonitorKitBundle\Queue\QueueDepthReport;
use Nowo\MessengerMonitorKitBundle\Routing\MessengerMonitorRoutes;
use Nowo\MessengerMonitorKitBundle\Settings\MonitorSettings;
use Nowo\UiKitBundle\Listing\SortRequest;
use Nowo\UiKitBundle\View\Action;
use Nowo\UiKitBundle\View\AreaNav;
use Nowo\UiKitBundle\View\AreaNavItem;
use Nowo\UiKitBundle\View\BackLink;
use Nowo\UiKitBundle\View\Column;
use Nowo\UiKitBundle\View\Confirm;
use Nowo\UiKitBundle\View\DashboardPage;
use Nowo\UiKitBundle\View\DetailItem;
use Nowo\UiKitBundle\View\DetailSection;
use Nowo\UiKitBundle\View\EmptyState;
use Nowo\UiKitBundle\View\KpiTile;
use Nowo\UiKitBundle\View\Label;
use Nowo\UiKitBundle\View\ListPage;
use Nowo\UiKitBundle\View\PageHeader;
use Nowo\UiKitBundle\View\Pagination;
use Nowo\UiKitBundle\View\SettingsPage;
use Nowo\UiKitBundle\View\SettingsSection;
use Nowo\UiKitBundle\View\ShowPage;
use Nowo\UiKitBundle\View\Table;
use Stringable;
use Symfony\Component\Form\FormView;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Zenstruck\Messenger\Monitor\History\Model\ProcessedMessage;
use Zenstruck\Messenger\Monitor\Transport\QueuedMessage;
use Zenstruck\Messenger\Monitor\Worker\WorkerInfo;

use function implode;
use function in_array;
use function is_scalar;

/**
 * Builds the UiKit view models of the admin pages (REQ-UI-003): dashboard, history list and row, transports, queued
 * messages of a transport, one queued message and settings. The controllers and the page contract test share it. View
 * models carry labels, plain values (escaped by Twig) and FormViews only — payloads arrive already redacted. Stateless.
 */
final readonly class MonitorPageFactory
{
    /** CSRF token id of the failure transport actions (retry / remove, one or all); the form action URL is checked too. */
    public const ACTION_CSRF_ID = 'nowo_messenger_monitor_kit_action';

    /**
     * @param UrlGeneratorInterface $urls route URLs
     * @param CsrfOnlyFormFactory $csrfForms FormKit CSRF-only forms of the POST actions
     * @param MonitorPages $pages layout / template overrides
     */
    public function __construct(
        private UrlGeneratorInterface $urls,
        private CsrfOnlyFormFactory $csrfForms,
        private MonitorPages $pages = new MonitorPages(),
    ) {
    }

    /**
     * Template of a page (`web_ui.templates.<page>` override, else the bundle template).
     */
    public function template(string $page): string
    {
        return $this->pages->template($page);
    }

    /**
     * Dashboard: KPI tiles of the last 24 hours and of the queues, plus the transports / workers / recent failures
     * tables rendered in the cards block.
     *
     * @param iterable<WorkerInfo> $workers running workers
     * @param iterable<ProcessedMessage> $recentFailures latest failed history rows
     *
     * @return array{page: DashboardPage, transports: Table, workers: Table, failures: Table, settings: MonitorSettings}
     */
    public function dashboard(int $total, int $failed, int $averageWait, int $averageHandling, QueueDepthReport $depth, iterable $workers, MonitorSettings $settings, iterable $recentFailures, bool $canManage): array
    {
        $pending     = $depth->pending();
        $parked      = $depth->failed();
        $workerRows  = [];
        $failureRows = [];
        $workerCount = 0;
        foreach ($workers as $worker) {
            ++$workerCount;
            $workerRows[] = [
                'state'      => $worker->status(),
                'transports' => implode(', ', $worker->transports()),
                'handled'    => $worker->messagesHandled(),
                'memory'     => (string) $worker->memoryUsage(),
                'uptime'     => MonitorFormat::duration($worker->runningFor() * 1000),
            ];
        }
        foreach ($recentFailures as $message) {
            $failureRows[] = $this->historyRow($message);
        }

        $kpis = [
            new KpiTile(MonitorPages::label('dashboard.kpi.processed'), $total, MonitorPages::label('dashboard.kpi.last_24h'), $this->urls->generate(MessengerMonitorRoutes::HISTORY)),
            new KpiTile(MonitorPages::label('dashboard.kpi.failed'), $failed, MonitorFormat::rate($failed, $total), $this->urls->generate(MessengerMonitorRoutes::HISTORY, ['status' => 'failed']), $failed > 0 ? 'danger' : 'success'),
            new KpiTile(MonitorPages::label('dashboard.kpi.wait'), MonitorFormat::duration($averageWait), MonitorPages::label('dashboard.kpi.average')),
            new KpiTile(MonitorPages::label('dashboard.kpi.handling'), MonitorFormat::duration($averageHandling), MonitorPages::label('dashboard.kpi.average')),
            new KpiTile(MonitorPages::label('dashboard.kpi.pending'), $pending ?? '—', MonitorPages::label('dashboard.kpi.queues'), $this->urls->generate(MessengerMonitorRoutes::TRANSPORTS), ($pending ?? 0) > 0 ? 'info' : 'neutral'),
            new KpiTile(MonitorPages::label('dashboard.kpi.parked'), $parked ?? '—', MonitorPages::label('dashboard.kpi.failure_transports'), $this->urls->generate(MessengerMonitorRoutes::TRANSPORTS), ($parked ?? 0) > 0 ? 'warning' : 'success'),
            new KpiTile(MonitorPages::label('dashboard.kpi.workers'), $workerCount, null, null, $workerCount > 0 ? 'success' : 'warning'),
            new KpiTile(MonitorPages::label($settings->recording ? 'dashboard.kpi.recording_on' : 'dashboard.kpi.recording_off'), $settings->recording ? '✓' : '—', MonitorPages::label($settings->autoPurge ? $settings->retention->translationKey() : 'dashboard.kpi.no_retention'), $canManage ? $this->urls->generate(MessengerMonitorRoutes::SETTINGS) : null, $settings->recording ? 'success' : 'warning'),
        ];

        return [
            'page'       => new DashboardPage($this->header('dashboard', 'dashboard.title', 'dashboard.intro', $canManage), $kpis, MonitorPages::BUNDLE, $this->pages->layout(), ['data-testid' => 'mmk-dashboard']),
            'transports' => $this->transportsTable($depth),
            'workers'    => new Table(
                [
                    new Column('state', MonitorPages::label('worker.status')),
                    new Column('transports', MonitorPages::label('worker.transports')),
                    new Column('handled', MonitorPages::label('worker.handled'), align: 'end'),
                    new Column('memory', MonitorPages::label('worker.memory'), align: 'end', priority: 2),
                    new Column('uptime', MonitorPages::label('worker.uptime'), align: 'end', priority: 2),
                ],
                $workerRows,
                caption: MonitorPages::label('dashboard.workers'),
                captionVisible: false,
                responsive: 'scroll',
                bundle: MonitorPages::BUNDLE,
            ),
            'failures' => $this->historyTable($failureRows, MonitorPages::label('dashboard.recent_failures'), true),
            'settings' => $settings,
        ];
    }

    /**
     * History list: filters, rows of the current page, pagination, link to each row.
     *
     * @param iterable<ProcessedMessage> $messages rows of the current page
     */
    public function history(iterable $messages, FormView $filters, Pagination $pagination, bool $canManage, ?SortRequest $sorting = null): ListPage
    {
        $rows = [];
        foreach ($messages as $message) {
            $rows[] = $this->historyRow($message);
        }

        return new ListPage(
            header: $this->header('history', 'history.title', 'history.intro', $canManage),
            columns: $this->historyColumns(true),
            rows: $rows,
            filters: $filters,
            rowActions: fn (array $row): array => [Action::view($this->urls->generate(MessengerMonitorRoutes::HISTORY_SHOW, ['id' => $row['id']]), MonitorPages::label('action.view'))],
            pagination: $pagination,
            empty: new EmptyState(MonitorPages::label('history.empty_title'), MonitorPages::label('history.empty')),
            filtersClearHref: $this->urls->generate(MessengerMonitorRoutes::HISTORY),
            caption: MonitorPages::label('history.title'),
            bundle: MonitorPages::BUNDLE,
            layout: $this->pages->layout(),
            attributes: ['data-testid' => 'mmk-history'],
            captionVisible: false,
            sorting: $sorting ?? self::historySorting(),
        );
    }

    /**
     * One history row: summary, timings, failure; attempts of the same run and the redacted handler results are
     * rendered after the sections.
     *
     * @param iterable<ProcessedMessage> $attempts every attempt of the same run (oldest first)
     * @param string|null $results redacted JSON of the handler results
     * @param string|null $failure redacted failure message
     *
     * @return array{page: ShowPage, attempts: Table, results: string|null, failure: string|null}
     */
    public function historyShow(ProcessedMessage $message, iterable $attempts, ?string $results, ?string $failure, bool $canManage): array
    {
        $type = $message->type();
        $rows = [];
        foreach ($attempts as $attempt) {
            $rows[] = $this->historyRow($attempt);
        }

        $header = new PageHeader(
            $type->shortName(),
            MonitorPages::label('history.show_intro'),
            tabs: $this->nav('history', $canManage),
            back: new BackLink($this->urls->generate(MessengerMonitorRoutes::HISTORY), MonitorPages::label('history.back'), 'mmk-back'),
        );

        return [
            'page' => new ShowPage($header, [
                new DetailSection(MonitorPages::label('history.section.summary'), [
                    new DetailItem(MonitorPages::label('column.type'), $type->class()),
                    new DetailItem(MonitorPages::label('column.description'), $message->description()),
                    new DetailItem(MonitorPages::label('column.status'), MonitorPages::label($message->isFailure() ? 'status.failed' : 'status.success'), badge: $message->isFailure() ? 'danger' : 'success'),
                    new DetailItem(MonitorPages::label('column.transport'), $message->transport()),
                    new DetailItem(MonitorPages::label('column.attempt'), $message->attempt()),
                    new DetailItem(MonitorPages::label('column.run'), $message->runId()),
                    new DetailItem(MonitorPages::label('column.tags'), (string) $message->tags()),
                ], 'summary'),
                new DetailSection(MonitorPages::label('history.section.timing'), [
                    new DetailItem(MonitorPages::label('column.dispatched'), MonitorFormat::date($message->dispatchedAt())),
                    new DetailItem(MonitorPages::label('column.received'), MonitorFormat::date($message->receivedAt())),
                    new DetailItem(MonitorPages::label('column.finished'), MonitorFormat::date($message->finishedAt())),
                    new DetailItem(MonitorPages::label('column.wait'), MonitorFormat::duration($message->timeInQueue())),
                    new DetailItem(MonitorPages::label('column.handle'), MonitorFormat::duration($message->timeToHandle())),
                    new DetailItem(MonitorPages::label('column.memory'), (string) $message->memoryUsage()),
                ], 'timing'),
                new DetailSection(MonitorPages::label('history.section.failure'), [
                    new DetailItem(MonitorPages::label('column.failure_type'), $message->failure()?->class()),
                ], 'failure'),
            ], [], MonitorPages::BUNDLE, $this->pages->layout(), ['data-testid' => 'mmk-history-show']),
            'attempts' => $this->historyTable($rows, MonitorPages::label('history.attempts')),
            'results'  => $results,
            'failure'  => $failure,
        ];
    }

    /**
     * Transports with their message count; listable ones link to their messages.
     */
    public function transports(QueueDepthReport $depth, bool $canManage): ListPage
    {
        $table = $this->transportsTable($depth);

        return new ListPage(
            header: $this->header('transports', 'transports.title', 'transports.intro', $canManage),
            columns: $table->columns,
            rows: $table->rows,
            rowActions: fn (array $row): array => $row['listable'] === true ? [Action::view($this->urls->generate(MessengerMonitorRoutes::TRANSPORT, ['name' => $row['name']]), MonitorPages::label('action.messages'))] : [],
            empty: new EmptyState(MonitorPages::label('transports.empty_title'), MonitorPages::label('transports.empty')),
            caption: MonitorPages::label('transports.title'),
            bundle: MonitorPages::BUNDLE,
            layout: $this->pages->layout(),
            attributes: ['data-testid' => 'mmk-transports'],
            captionVisible: false,
        );
    }

    /**
     * Queued messages of one transport (at most `web_ui.transport_list_limit`). On a failure transport managers get
     * retry / remove per row and "retry all" / "remove all" (CSRF forms, confirm dialogs).
     *
     * @param iterable<QueuedMessage> $messages
     * @param array<int|string, string|null> $targets message id => retry target (null = not retryable)
     * @param list<string> $duplicateRisk ids of messages whose retry may repeat side effects
     */
    public function transport(string $name, iterable $messages, bool $failure, bool $canManage, int $limit, int $batchLimit, array $targets = [], array $duplicateRisk = []): ListPage
    {
        $rows = [];
        foreach ($messages as $queued) {
            $id     = $queued->id();
            $rows[] = [
                'id'         => is_scalar($id) ? (string) $id : '',
                'type'       => $queued->message()->shortName(),
                'dispatched' => MonitorFormat::date($queued->dispatchedAt()),
                'error'      => $queued->exception()?->shortName(),
                'target'     => is_scalar($id) ? ($targets[(string) $id] ?? null) : null,
                'tags'       => (string) $queued->tags(),
            ];
        }

        $manage  = $failure && $canManage;
        $actions = [];
        if ($manage && $rows !== []) {
            $actions[] = $this->post('custom', 'action.retry_all', MessengerMonitorRoutes::RETRY_ALL, ['name' => $name], self::ACTION_CSRF_ID, new Confirm(MonitorPages::label('confirm.retry_all_title'), MonitorPages::label('confirm.retry_all', ['%count%' => $batchLimit]), MonitorPages::label('action.retry_all'), 'primary'), 'primary', ['data-testid' => 'mmk-retry-all']);
            $actions[] = $this->post('delete', 'action.remove_all', MessengerMonitorRoutes::REMOVE_ALL, ['name' => $name], self::ACTION_CSRF_ID, new Confirm(MonitorPages::label('confirm.remove_all_title'), MonitorPages::label('confirm.remove_all', ['%count%' => $batchLimit]), MonitorPages::label('action.remove_all')), 'danger', ['data-testid' => 'mmk-remove-all']);
        }

        $header = new PageHeader(
            MonitorPages::label('transport.title', ['%name%' => $name]),
            MonitorPages::label($failure ? 'transport.intro_failure' : 'transport.intro', ['%limit%' => $limit]),
            $actions,
            tabs: $this->nav('transports', $canManage),
            back: new BackLink($this->urls->generate(MessengerMonitorRoutes::TRANSPORTS), MonitorPages::label('transports.back'), 'mmk-back'),
        );

        return new ListPage(
            header: $header,
            columns: [
                new Column('id', MonitorPages::label('column.id')),
                new Column('type', MonitorPages::label('column.type'), required: true),
                new Column('dispatched', MonitorPages::label('column.dispatched'), priority: 2),
                new Column('error', MonitorPages::label('column.error')),
                new Column('target', MonitorPages::label('column.target'), priority: 3),
                new Column('tags', MonitorPages::label('column.tags'), priority: 3),
            ],
            rows: $rows,
            rowActions: function (array $row) use ($name, $manage, $duplicateRisk): array {
                $params  = ['name' => $name, 'id' => $row['id']];
                $actions = [Action::view($this->urls->generate(MessengerMonitorRoutes::MESSAGE, $params), MonitorPages::label('action.view'))];
                if ($manage && $row['id'] !== '') {
                    if ($row['target'] !== null) {
                        $risky     = in_array($row['id'], $duplicateRisk, true);
                        $actions[] = $this->post('custom', 'action.retry', MessengerMonitorRoutes::RETRY, $params, self::ACTION_CSRF_ID, new Confirm(MonitorPages::label('confirm.retry_title'), MonitorPages::label($risky ? 'confirm.retry_duplicate' : 'confirm.retry', ['%transport%' => $row['target']]), MonitorPages::label('action.retry'), $risky ? 'danger' : 'primary'), 'secondary', ['data-testid' => 'mmk-retry']);
                    }
                    $actions[] = $this->post('delete', 'action.remove', MessengerMonitorRoutes::REMOVE, $params, self::ACTION_CSRF_ID, new Confirm(MonitorPages::label('confirm.remove_title'), MonitorPages::label('confirm.remove'), MonitorPages::label('action.remove')), 'danger', ['data-testid' => 'mmk-remove']);
                }

                return $actions;
            },
            empty: new EmptyState(MonitorPages::label('transport.empty_title'), MonitorPages::label('transport.empty')),
            caption: MonitorPages::label('transport.title', ['%name%' => $name]),
            bundle: MonitorPages::BUNDLE,
            layout: $this->pages->layout(),
            attributes: ['data-testid' => 'mmk-transport', 'data-transport' => $name],
            captionVisible: false,
        );
    }

    /**
     * One queued message: summary, error, stamps; the redacted payload is rendered after the sections.
     *
     * @param string|null $payload redacted JSON (null = payloads disabled or not allowed)
     * @param string|null $target retry target (null = not retryable)
     * @param string|null $error redacted error message
     *
     * @return array{page: ShowPage, payload: string|null, error: string|null}
     */
    public function message(string $transport, QueuedMessage $queued, ?string $payload, ?string $error, bool $failure, bool $canManage, ?string $target, bool $duplicateRisk = false): array
    {
        $id      = $queued->id();
        $params  = ['name' => $transport, 'id' => is_scalar($id) ? (string) $id : ''];
        $actions = [];
        if ($failure && $canManage) {
            if ($target !== null) {
                $actions[] = $this->post('custom', 'action.retry', MessengerMonitorRoutes::RETRY, $params, self::ACTION_CSRF_ID, new Confirm(MonitorPages::label('confirm.retry_title'), MonitorPages::label($duplicateRisk ? 'confirm.retry_duplicate' : 'confirm.retry', ['%transport%' => $target]), MonitorPages::label('action.retry'), $duplicateRisk ? 'danger' : 'primary'), 'primary', ['data-testid' => 'mmk-retry']);
            }
            $actions[] = $this->post('delete', 'action.remove', MessengerMonitorRoutes::REMOVE, $params, self::ACTION_CSRF_ID, new Confirm(MonitorPages::label('confirm.remove_title'), MonitorPages::label('confirm.remove'), MonitorPages::label('action.remove')), 'danger', ['data-testid' => 'mmk-remove']);
        }

        $stamps = [];
        foreach ($queued->stamps() as $stamp) {
            $short          = $stamp->shortName();
            $stamps[$short] = ($stamps[$short] ?? 0) + 1;
        }
        $stampItems = [];
        foreach ($stamps as $short => $count) {
            $stampItems[] = new DetailItem($short, $count);
        }

        $header = new PageHeader(
            $queued->message()->shortName(),
            MonitorPages::label('message.intro', ['%transport%' => $transport]),
            $actions,
            tabs: $this->nav('transports', $canManage),
            back: new BackLink($this->urls->generate(MessengerMonitorRoutes::TRANSPORT, ['name' => $transport]), MonitorPages::label('message.back'), 'mmk-back'),
        );

        return [
            'page' => new ShowPage($header, [
                new DetailSection(MonitorPages::label('history.section.summary'), [
                    new DetailItem(MonitorPages::label('column.id'), $params['id']),
                    new DetailItem(MonitorPages::label('column.type'), $queued->message()->class()),
                    new DetailItem(MonitorPages::label('column.transport'), $transport),
                    new DetailItem(MonitorPages::label('column.dispatched'), MonitorFormat::date($queued->dispatchedAt())),
                    new DetailItem(MonitorPages::label('column.target'), $target),
                    new DetailItem(MonitorPages::label('column.tags'), (string) $queued->tags()),
                ], 'summary'),
                new DetailSection(MonitorPages::label('message.section.error'), [
                    new DetailItem(MonitorPages::label('column.failure_type'), $queued->exception()?->class()),
                ], 'error'),
                new DetailSection(MonitorPages::label('message.section.stamps'), $stampItems, 'stamps'),
            ], [], MonitorPages::BUNDLE, $this->pages->layout(), ['data-testid' => 'mmk-message']),
            'payload' => $payload,
            'error'   => $error,
        ];
    }

    /**
     * Settings: the runtime switches form (or a read-only note with `settings.store: config`) and the manual purge.
     */
    public function settings(?FormView $switches, FormView $purge, MonitorSettings $settings, int $historyCount, ?DateTimeImmutable $lastAutoPurge): SettingsPage
    {
        $intro = $switches instanceof FormView
            ? MonitorPages::label('settings.intro_store')
            : MonitorPages::label('settings.intro_readonly', ['%recording%' => $settings->recording ? 'on' : 'off', '%retention%' => $settings->retention->value]);

        return new SettingsPage(
            header: $this->header('settings', 'settings.title', 'settings.intro', true),
            sections: [
                new SettingsSection(
                    id: 'switches',
                    label: MonitorPages::label('settings.switches'),
                    form: $switches,
                    intro: $intro,
                    submitLabel: MonitorPages::label('settings.save'),
                ),
                new SettingsSection(
                    id: 'purge',
                    label: MonitorPages::label('purge.title'),
                    form: $purge,
                    intro: MonitorPages::label('purge.intro', [
                        '%count%' => $historyCount,
                        '%last%'  => MonitorFormat::date($lastAutoPurge) ?? '—',
                    ]),
                    submitLabel: MonitorPages::label('purge.submit'),
                ),
            ],
            bundle: MonitorPages::BUNDLE,
            layout: $this->pages->layout(),
            attributes: ['data-testid' => 'mmk-settings'],
        );
    }

    /**
     * Area navigation of the monitor (tabs of every page header).
     */
    public function nav(string $current, bool $canManage): AreaNav
    {
        $items = [];
        foreach (['dashboard' => MessengerMonitorRoutes::DASHBOARD, 'history' => MessengerMonitorRoutes::HISTORY, 'transports' => MessengerMonitorRoutes::TRANSPORTS, 'settings' => MessengerMonitorRoutes::SETTINGS] as $area => $route) {
            if ($area === 'settings' && !$canManage) {
                continue;
            }
            $items[] = new AreaNavItem(MonitorPages::label('nav.' . $area), $this->urls->generate($route), $area === $current, ['data-testid' => 'mmk-nav-' . $area]);
        }

        return new AreaNav($items, MonitorPages::label('nav.label'));
    }

    /**
     * Row of the history tables.
     *
     * @return array<string, int|string|null>
     */
    private function historyRow(ProcessedMessage $message): array
    {
        $id = $message->id();

        return [
            'id'        => is_scalar($id) || $id instanceof Stringable ? (string) $id : '',
            'finished'  => MonitorFormat::date($message->finishedAt()),
            'type'      => $message->type()->shortName(),
            'transport' => $message->transport(),
            'status'    => $message->isFailure() ? 'failed' : 'success',
            'attempt'   => $message->attempt(),
            'wait'      => MonitorFormat::duration($message->timeInQueue()),
            'handle'    => MonitorFormat::duration($message->timeToHandle()),
            'tags'      => (string) $message->tags(),
        ];
    }

    /**
     * Sorting of the history list: by finish time only (the order zenstruck's storage supports), newest first.
     */
    public static function historySorting(): SortRequest
    {
        return new SortRequest('finished', SortRequest::DESC, ['finished' => SortRequest::DESC]);
    }

    /**
     * @return list<Column>
     */
    private function historyColumns(bool $sortable = false): array
    {
        return [
            new Column('finished', MonitorPages::label('column.finished'), sortable: $sortable, sortFirst: 'desc'),
            new Column('type', MonitorPages::label('column.type'), required: true),
            new Column('transport', MonitorPages::label('column.transport'), priority: 2),
            new Column('status', MonitorPages::label('column.status')),
            new Column('attempt', MonitorPages::label('column.attempt'), align: 'end', priority: 3),
            new Column('wait', MonitorPages::label('column.wait'), align: 'end', priority: 3),
            new Column('handle', MonitorPages::label('column.handle'), align: 'end', priority: 2),
            new Column('tags', MonitorPages::label('column.tags'), priority: 3),
        ];
    }

    /**
     * @param list<array<string, int|string|null>> $rows
     */
    private function historyTable(array $rows, Label $caption, bool $compact = false): Table
    {
        $columns = $this->historyColumns();
        if ($compact) {
            // Dashboard card: finish time, message and status only, as a plain (scrolling) table.
            $columns = [$columns[0], $columns[1], $columns[3]];
        }

        return new Table(
            $columns,
            $rows,
            fn (array $row): array => [Action::view($this->urls->generate(MessengerMonitorRoutes::HISTORY_SHOW, ['id' => $row['id']]), MonitorPages::label('action.view'))],
            caption: $caption,
            captionVisible: false,
            responsive: $compact ? 'scroll' : 'cards',
            bundle: MonitorPages::BUNDLE,
            attributes: ['data-testid' => 'mmk-history-table'],
        );
    }

    private function transportsTable(QueueDepthReport $depth): Table
    {
        $rows = [];
        foreach ($depth->transports as $transport) {
            $rows[] = [
                'name'     => $transport->name,
                'kind'     => $transport->failure ? 'failure' : 'queue',
                'count'    => $transport->count ?? '—',
                'listable' => $transport->listable,
            ];
        }

        return new Table(
            [
                new Column('name', MonitorPages::label('column.transport'), required: true),
                new Column('kind', MonitorPages::label('column.kind')),
                new Column('count', MonitorPages::label('column.count'), align: 'end'),
            ],
            $rows,
            fn (array $row): array => $row['listable'] === true ? [Action::view($this->urls->generate(MessengerMonitorRoutes::TRANSPORT, ['name' => $row['name']]), MonitorPages::label('action.messages'))] : [],
            caption: MonitorPages::label('dashboard.transports'),
            captionVisible: false,
            responsive: 'scroll',
            bundle: MonitorPages::BUNDLE,
            attributes: ['data-testid' => 'mmk-transports-table'],
        );
    }

    private function header(string $area, string $title, string $intro, bool $canManage): PageHeader
    {
        return new PageHeader(MonitorPages::label($title), MonitorPages::label($intro), tabs: $this->nav($area, $canManage));
    }

    /**
     * POST action with a FormKit CSRF-only form and a confirm dialog.
     *
     * @param array<string, string> $params
     * @param array<string, string> $attributes
     */
    private function post(string $kind, string $label, string $route, array $params, string $csrfId, Confirm $confirm, string $variant, array $attributes): Action
    {
        $url = $this->urls->generate($route, $params);

        return new Action(
            kind: $kind,
            label: MonitorPages::label($label),
            href: $url,
            method: 'POST',
            confirm: $confirm,
            variant: $variant,
            attributes: $attributes,
            form: $this->csrfForms->createNamed($url, $csrfId)->createView(),
        );
    }
}
