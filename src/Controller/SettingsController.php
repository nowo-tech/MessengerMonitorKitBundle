<?php

declare(strict_types=1);

namespace Nowo\MessengerMonitorKitBundle\Controller;

use Nowo\MessengerMonitorKitBundle\Event\MessengerMonitorActionEvent;
use Nowo\MessengerMonitorKitBundle\Form\MonitorSettingsType;
use Nowo\MessengerMonitorKitBundle\Form\PurgeType;
use Nowo\MessengerMonitorKitBundle\History\AutoPurger;
use Nowo\MessengerMonitorKitBundle\History\HistoryPurger;
use Nowo\MessengerMonitorKitBundle\History\PurgePeriod;
use Nowo\MessengerMonitorKitBundle\Routing\MessengerMonitorRoutes;
use Nowo\MessengerMonitorKitBundle\Security\AccessGuard;
use Nowo\MessengerMonitorKitBundle\Settings\MonitorSettings;
use Nowo\MessengerMonitorKitBundle\Settings\MonitorSettingsStoreInterface;
use Nowo\MessengerMonitorKitBundle\Ui\ActionForms;
use Nowo\MessengerMonitorKitBundle\Ui\MonitorPageFactory;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;
use Zenstruck\Messenger\Monitor\History\Specification;
use Zenstruck\Messenger\Monitor\History\Storage;

use function is_array;

/**
 * Settings page (manage area): the runtime switches (recording, optional recording, automatic purge, retention, keep
 * failed) and the manual batched purge. Both forms are POST + CSRF; the purge runs at most
 * `purge.max_batches_per_request` batches per request.
 */
final class SettingsController extends AbstractMonitorController
{
    /**
     * @param int $maxBatches batches of one HTTP purge (`purge.max_batches_per_request`)
     */
    public function __construct(
        private readonly MonitorSettingsStoreInterface $settings,
        private readonly HistoryPurger $purger,
        private readonly AutoPurger $autoPurger,
        private readonly Storage $storage,
        private readonly int $maxBatches,
        AccessGuard $guard,
        MonitorPageFactory $pages,
        ActionForms $actionForms,
        ?EventDispatcherInterface $events = null,
        ?TranslatorInterface $translator = null,
    ) {
        parent::__construct($guard, $pages, $actionForms, $events, $translator);
    }

    /**
     * Settings page; POST saves the switches (422 on an invalid submission).
     */
    #[Route('/settings', name: MessengerMonitorRoutes::SETTINGS, methods: ['GET', 'POST'])]
    public function settings(Request $request): Response
    {
        $this->requireManage();
        $current  = $this->settings->load();
        $switches = null;

        if ($this->settings->isWritable()) {
            $switches = $this->createForm(MonitorSettingsType::class, $current->toArray(), [
                'action' => $this->generateUrl(MessengerMonitorRoutes::SETTINGS),
                'method' => 'POST',
            ]);
            $switches->handleRequest($request);
            if ($switches->isSubmitted() && $switches->isValid()) {
                $data    = $switches->getData();
                $updated = MonitorSettings::fromArray(is_array($data) ? $data : [], $current);
                if ($updated != $current) {
                    $this->settings->save($updated);
                    $this->audit(MessengerMonitorActionEvent::SETTINGS_UPDATED, $updated->toArray());
                }
                $this->flash('success', 'flash.settings_saved');

                return $this->redirectToRoute(MessengerMonitorRoutes::SETTINGS);
            }
        } elseif ($request->isMethod('POST')) {
            throw new AccessDeniedHttpException('The messenger monitor settings are read-only (settings.store: config).');
        }

        return $this->render($this->pages->template('settings'), [
            'page' => $this->pages->settings(
                $switches?->createView(),
                $this->purgeForm()->createView(),
                $current,
                $this->storage->count(Specification::new()),
                $this->autoPurger->lastRunAt(),
            ),
        ], new Response(null, $switches instanceof FormInterface && $switches->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    /**
     * Manual batched purge (POST + CSRF).
     */
    #[Route('/purge', name: MessengerMonitorRoutes::PURGE, methods: ['POST'])]
    public function purge(Request $request): RedirectResponse
    {
        $this->requireManage();
        $form = $this->purgeForm();
        $form->handleRequest($request);
        if (!$form->isSubmitted() || !$form->isValid()) {
            throw new AccessDeniedHttpException('Invalid purge request (missing or invalid CSRF token).');
        }

        /** @var array{older_than: PurgePeriod, keep_failed: bool|null} $data */
        $data       = $form->getData();
        $keepFailed = $data['keep_failed'] === true;
        $result     = $this->purger->purge($data['older_than'], $keepFailed, $this->maxBatches);
        $this->audit(MessengerMonitorActionEvent::PURGED, ['older_than' => $data['older_than']->value, 'keep_failed' => $keepFailed, 'deleted' => $result->deleted, 'complete' => $result->complete]);
        $this->flash($result->complete ? 'success' : 'warning', $result->complete ? 'flash.purged' : 'flash.purged_partial', ['%count%' => $result->deleted]);

        return $this->redirectToRoute(MessengerMonitorRoutes::SETTINGS);
    }

    /**
     * @return FormInterface<mixed>
     */
    private function purgeForm(): FormInterface
    {
        return $this->createForm(PurgeType::class, null, [
            'action' => $this->generateUrl(MessengerMonitorRoutes::PURGE),
            'method' => 'POST',
        ]);
    }
}
