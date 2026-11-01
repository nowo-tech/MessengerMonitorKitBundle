<?php

declare(strict_types=1);

namespace Nowo\MessengerMonitorKitBundle;

use Nowo\MessengerMonitorKitBundle\DependencyInjection\Compiler\CsrfProtectionPass;
use Nowo\MessengerMonitorKitBundle\DependencyInjection\Compiler\HistoryStoragePass;
use Nowo\MessengerMonitorKitBundle\DependencyInjection\Compiler\MessengerIntegrationPass;
use Nowo\MessengerMonitorKitBundle\DependencyInjection\Compiler\TwigPathsPass;
use Nowo\MessengerMonitorKitBundle\DependencyInjection\Compiler\UnauthenticatedAccessPass;
use Nowo\MessengerMonitorKitBundle\DependencyInjection\NowoMessengerMonitorKitExtension;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\HttpKernel\Bundle\Bundle;

/**
 * Symfony Messenger monitoring on top of zenstruck/messenger-monitor-bundle: runtime recording toggle, batched purge
 * and retention, safe retry to the original transport, queue depth, payload redaction and a UiKit admin UI.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class NowoMessengerMonitorKitBundle extends Bundle
{
    /** Translation domain of the bundle UI copy (REQ-I18N-003) and Twig namespace (REQ-TWIG-002). */
    public const TRANSLATION_DOMAIN = 'NowoMessengerMonitorKitBundle';

    /** FormKit profile (`nowo_form_kit.profiles.messenger_monitor_kit`) of the bundle POST forms (REQ-TWIG-005 rule 11). */
    public const FORM_KIT_PROFILE = 'messenger_monitor_kit';

    /** Service tag of the payload redactors ({@see Payload\PayloadRedactorInterface}). */
    public const PAYLOAD_REDACTOR_TAG = 'nowo_messenger_monitor_kit.payload_redactor';

    /**
     * Registers the compiler passes: Twig paths, CSRF fail-closed guard, demo-only unauthenticated access, history
     * storage decoration and the decoding-failure retry strategies.
     */
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);
        $container->addCompilerPass(new TwigPathsPass());
        $container->addCompilerPass(new CsrfProtectionPass());
        $container->addCompilerPass(new UnauthenticatedAccessPass());
        $container->addCompilerPass(new HistoryStoragePass());
        $container->addCompilerPass(new MessengerIntegrationPass());
    }

    /**
     * The extension alias is `nowo_messenger_monitor_kit` (not the default derived from the class name).
     */
    public function getContainerExtension(): ?ExtensionInterface
    {
        if ($this->extension === null) {
            // @igor-ignore - Boot-time Symfony Bundle extension cache (not request state).
            $this->extension = new NowoMessengerMonitorKitExtension();
        }

        return $this->extension instanceof ExtensionInterface ? $this->extension : null;
    }
}
