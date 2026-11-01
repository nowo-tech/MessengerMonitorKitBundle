<?php

declare(strict_types=1);

namespace Nowo\MessengerMonitorKitBundle\Controller;

use Nowo\MessengerMonitorKitBundle\Event\MessengerMonitorActionEvent;
use Nowo\MessengerMonitorKitBundle\NowoMessengerMonitorKitBundle;
use Nowo\MessengerMonitorKitBundle\Security\AccessGuard;
use Nowo\MessengerMonitorKitBundle\Security\MessengerMonitorAccessCheckerInterface;
use Nowo\MessengerMonitorKitBundle\Ui\ActionForms;
use Nowo\MessengerMonitorKitBundle\Ui\MonitorPageFactory;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Shared plumbing of the admin controllers: access areas, fail-closed CSRF of the POST actions (FormKit CSRF-only
 * forms scoped to the action and its target), audit events and translated flashes. The view area is also enforced by the request listener for every
 * bundle controller; mutations additionally require the manage area here.
 */
abstract class AbstractMonitorController extends AbstractController
{
    /**
     * @param AccessGuard $guard access checker of the current user
     * @param MonitorPageFactory $pages view models
     * @param ActionForms $actionForms scoped CSRF forms of the failure transport actions
     * @param EventDispatcherInterface|null $events dispatcher of {@see MessengerMonitorActionEvent}
     * @param TranslatorInterface|null $translator flash messages
     */
    public function __construct(
        protected readonly AccessGuard $guard,
        protected readonly MonitorPageFactory $pages,
        protected readonly ActionForms $actionForms,
        protected readonly ?EventDispatcherInterface $events = null,
        protected readonly ?TranslatorInterface $translator = null,
    ) {
    }

    /**
     * Whether the current user may manage (mutations, payloads).
     */
    protected function canManage(): bool
    {
        return $this->guard->isGranted(MessengerMonitorAccessCheckerInterface::AREA_MANAGE);
    }

    /**
     * Throws unless the current user may manage.
     */
    protected function requireManage(): void
    {
        $this->guard->denyUnlessGranted(MessengerMonitorAccessCheckerInterface::AREA_MANAGE);
    }

    /**
     * Validates the scoped CSRF form of a POST action (token id bound to the route, the transport and the message id or
     * "all"); a missing, invalid or other-action token is a 403 (fail closed).
     *
     * @param array<string, string> $params route parameters of the action
     */
    protected function requireCsrf(Request $request, string $route, array $params): void
    {
        $form = $this->actionForms->create($route, $params);
        $form->handleRequest($request);
        if (!$form->isSubmitted() || !$form->isValid()) {
            throw new AccessDeniedHttpException('Invalid or missing CSRF token.');
        }
    }

    /**
     * Dispatches the audit event of a mutation.
     *
     * @param array<string, mixed> $context
     */
    protected function audit(string $action, array $context): void
    {
        $this->events?->dispatch(new MessengerMonitorActionEvent($action, $context, $this->guard->user()));
    }

    /**
     * Adds a translated flash message (bundle domain).
     *
     * @param array<string, mixed> $params
     */
    protected function flash(string $type, string $key, array $params = []): void
    {
        $this->addFlash($type, $this->translator?->trans($key, $params, NowoMessengerMonitorKitBundle::TRANSLATION_DOMAIN) ?? $key);
    }
}
