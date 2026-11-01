<?php

declare(strict_types=1);

namespace Nowo\MessengerMonitorKitBundle\Controller;

use Nowo\FormKitBundle\Form\CsrfOnlyFormFactory;
use Nowo\MessengerMonitorKitBundle\Event\MessengerMonitorActionEvent;
use Nowo\MessengerMonitorKitBundle\Payload\PayloadView;
use Nowo\MessengerMonitorKitBundle\Payload\Redactor;
use Nowo\MessengerMonitorKitBundle\Queue\QueueDepth;
use Nowo\MessengerMonitorKitBundle\Retry\BatchResult;
use Nowo\MessengerMonitorKitBundle\Retry\FailedMessageRetrier;
use Nowo\MessengerMonitorKitBundle\Retry\RetryOutcome;
use Nowo\MessengerMonitorKitBundle\Retry\RetryStatus;
use Nowo\MessengerMonitorKitBundle\Routing\MessengerMonitorRoutes;
use Nowo\MessengerMonitorKitBundle\Security\AccessGuard;
use Nowo\MessengerMonitorKitBundle\Ui\MonitorPageFactory;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;
use Zenstruck\Messenger\Monitor\Transport\QueuedMessage;
use Zenstruck\Messenger\Monitor\Transport\TransportInfo;
use Zenstruck\Messenger\Monitor\Transports;

use function in_array;
use function is_scalar;

/**
 * Transports (depth), the queued messages of a listable transport, one queued message (redacted payload for
 * managers) and the failure transport actions: retry / remove one, retry all / remove all (first `retry.batch_limit`).
 * Every action is POST + CSRF + manage area, and only targets a failure transport (404 elsewhere).
 */
final class TransportsController extends AbstractMonitorController
{
    /** Route requirement of a transport name. */
    public const NAME_REQUIREMENT = '[A-Za-z0-9_.\-]{1,100}';

    /** Route requirement of a queued message id. */
    public const ID_REQUIREMENT = '[A-Za-z0-9_.\-]{1,100}';

    /**
     * @param int $listLimit queued messages listed per page (`web_ui.transport_list_limit`)
     * @param int $batchLimit messages handled by retry all / remove all (`retry.batch_limit`)
     */
    public function __construct(
        private readonly Transports $transports,
        private readonly QueueDepth $queueDepth,
        private readonly FailedMessageRetrier $retrier,
        private readonly PayloadView $payloadView,
        private readonly Redactor $redactor,
        private readonly int $listLimit,
        private readonly int $batchLimit,
        AccessGuard $guard,
        MonitorPageFactory $pages,
        CsrfOnlyFormFactory $csrfForms,
        ?EventDispatcherInterface $events = null,
        ?TranslatorInterface $translator = null,
    ) {
        parent::__construct($guard, $pages, $csrfForms, $events, $translator);
    }

    /**
     * Transports and their message count.
     */
    #[Route('/transports', name: MessengerMonitorRoutes::TRANSPORTS, methods: ['GET'])]
    public function index(): Response
    {
        return $this->render($this->pages->template('transports'), [
            'page' => $this->pages->transports($this->queueDepth->report(), $this->canManage()),
        ]);
    }

    /**
     * Queued messages of one listable transport.
     */
    #[Route('/transports/{name}', name: MessengerMonitorRoutes::TRANSPORT, requirements: ['name' => self::NAME_REQUIREMENT], methods: ['GET'])]
    public function show(string $name): Response
    {
        $transport = $this->listable($name);
        $failure   = $this->retrier->isFailureTransport($name);
        $messages  = $transport->list($this->listLimit)->eager()->all();
        $targets   = [];
        $risky     = [];
        foreach ($messages as $queued) {
            $id = $this->id($queued);
            if ($failure && $id !== null) {
                $targets[$id] = $this->retrier->targetFor($queued);
                if ($this->retrier->isDuplicateRisk($queued)) {
                    $risky[] = $id;
                }
            }
        }

        return $this->render($this->pages->template('transport'), [
            'page' => $this->pages->transport($name, $messages, $failure, $this->canManage(), $this->listLimit, $this->batchLimit, $targets, $risky),
        ]);
    }

    /**
     * One queued message; the payload is shown to managers only, redacted and escaped.
     */
    #[Route('/transports/{name}/messages/{id}', name: MessengerMonitorRoutes::MESSAGE, requirements: ['name' => self::NAME_REQUIREMENT, 'id' => self::ID_REQUIREMENT], methods: ['GET'])]
    public function message(string $name, string $id): Response
    {
        $queued  = $this->listable($name)->find($id) ?? throw $this->createNotFoundException();
        $failure = $this->retrier->isFailureTransport($name);
        $manage  = $this->canManage();

        return $this->render($this->pages->template('message'), $this->pages->message(
            $name,
            $queued,
            $manage ? $this->payloadView->render($queued->envelope()->getMessage()) : null,
            $this->redactor->redactText($queued->exception()?->description()),
            $failure,
            $manage,
            $failure ? $this->retrier->targetFor($queued) : null,
            $failure && $this->retrier->isDuplicateRisk($queued),
        ));
    }

    /**
     * Retries one failed message (POST + CSRF).
     */
    #[Route('/transports/{name}/messages/{id}/retry', name: MessengerMonitorRoutes::RETRY, requirements: ['name' => self::NAME_REQUIREMENT, 'id' => self::ID_REQUIREMENT], methods: ['POST'])]
    public function retry(Request $request, string $name, string $id): RedirectResponse
    {
        $failed  = $this->failureAction($request, MessengerMonitorRoutes::RETRY, ['name' => $name, 'id' => $id], MonitorPageFactory::ACTION_CSRF_ID);
        $outcome = $this->retrier->retry($failed, $id);
        $this->single($outcome, MessengerMonitorActionEvent::RETRIED, $name, 'flash.retried');

        return $this->redirectToRoute(MessengerMonitorRoutes::TRANSPORT, ['name' => $name]);
    }

    /**
     * Removes one failed message (POST + CSRF).
     */
    #[Route('/transports/{name}/messages/{id}/remove', name: MessengerMonitorRoutes::REMOVE, requirements: ['name' => self::NAME_REQUIREMENT, 'id' => self::ID_REQUIREMENT], methods: ['POST'])]
    public function remove(Request $request, string $name, string $id): RedirectResponse
    {
        $failed  = $this->failureAction($request, MessengerMonitorRoutes::REMOVE, ['name' => $name, 'id' => $id], MonitorPageFactory::ACTION_CSRF_ID);
        $outcome = $this->retrier->discard($failed, $id);
        $this->single($outcome, MessengerMonitorActionEvent::REMOVED, $name, 'flash.removed');

        return $this->redirectToRoute(MessengerMonitorRoutes::TRANSPORT, ['name' => $name]);
    }

    /**
     * Retries the first `retry.batch_limit` failed messages (POST + CSRF).
     */
    #[Route('/transports/{name}/retry-all', name: MessengerMonitorRoutes::RETRY_ALL, requirements: ['name' => self::NAME_REQUIREMENT], methods: ['POST'])]
    public function retryAll(Request $request, string $name): RedirectResponse
    {
        $failed = $this->failureAction($request, MessengerMonitorRoutes::RETRY_ALL, ['name' => $name], MonitorPageFactory::ACTION_CSRF_ID);
        $this->batch($this->retrier->retryAll($failed, $this->batchLimit), MessengerMonitorActionEvent::RETRIED, $name, 'flash.retried_all');

        return $this->redirectToRoute(MessengerMonitorRoutes::TRANSPORT, ['name' => $name]);
    }

    /**
     * Removes the first `retry.batch_limit` failed messages (POST + CSRF).
     */
    #[Route('/transports/{name}/remove-all', name: MessengerMonitorRoutes::REMOVE_ALL, requirements: ['name' => self::NAME_REQUIREMENT], methods: ['POST'])]
    public function removeAll(Request $request, string $name): RedirectResponse
    {
        $failed = $this->failureAction($request, MessengerMonitorRoutes::REMOVE_ALL, ['name' => $name], MonitorPageFactory::ACTION_CSRF_ID);
        $this->batch($this->retrier->discardAll($failed, $this->batchLimit), MessengerMonitorActionEvent::REMOVED, $name, 'flash.removed_all');

        return $this->redirectToRoute(MessengerMonitorRoutes::TRANSPORT, ['name' => $name]);
    }

    /**
     * Manage area, CSRF of the action form, then the failure transport (404 for any other transport).
     *
     * @param array<string, string> $params
     */
    private function failureAction(Request $request, string $route, array $params, string $tokenId): TransportInfo
    {
        $this->requireManage();
        $this->requireCsrf($request, $this->generateUrl($route, $params), $tokenId);

        return $this->retrier->failureTransport($params['name']) ?? throw $this->createNotFoundException();
    }

    private function listable(string $name): TransportInfo
    {
        if (!in_array($name, $this->transports->names(), true)) {
            throw $this->createNotFoundException();
        }
        $transport = $this->transports->get($name);

        return $transport->isListable() ? $transport : throw $this->createNotFoundException();
    }

    private function single(RetryOutcome $outcome, string $action, string $transport, string $flash): void
    {
        if ($outcome->isDone()) {
            $this->audit($action, ['transport' => $transport, 'count' => 1, 'types' => [(string) $outcome->type => 1], 'targets' => $outcome->target === null ? [] : [$outcome->target]]);
            $this->flash('success', $flash, ['%message%' => (string) $outcome->type, '%transport%' => $outcome->target ?? $transport]);

            return;
        }

        $this->flash($outcome->status === RetryStatus::Locked ? 'warning' : 'error', 'flash.' . $outcome->status->value);
    }

    private function batch(BatchResult $result, string $action, string $transport, string $flash): void
    {
        if ($result->done > 0) {
            $this->audit($action, ['transport' => $transport, 'count' => $result->done, 'types' => $result->types, 'targets' => $result->targets]);
        }
        $this->flash($result->done > 0 ? 'success' : 'warning', $flash, ['%count%' => $result->done, '%skipped%' => $result->skipped]);
    }

    private function id(QueuedMessage $queued): ?string
    {
        $id = $queued->id();

        return is_scalar($id) ? (string) $id : null;
    }
}
