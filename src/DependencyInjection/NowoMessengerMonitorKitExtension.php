<?php

declare(strict_types=1);

namespace Nowo\MessengerMonitorKitBundle\DependencyInjection;

use LogicException;
use Nowo\FormKitBundle\Form\CsrfOnlyFormFactory;
use Nowo\FormKitBundle\Form\GetFilterFormFactory;
use Nowo\HealthKitBundle\Probe\HealthProbeInterface;
use Nowo\InstanceSettingsBundle\Settings\InstanceSettingsInterface;
use Nowo\MessengerMonitorKitBundle\Command\PurgeHistoryCommand;
use Nowo\MessengerMonitorKitBundle\Controller\DashboardController;
use Nowo\MessengerMonitorKitBundle\Controller\HistoryController;
use Nowo\MessengerMonitorKitBundle\Controller\SettingsController;
use Nowo\MessengerMonitorKitBundle\Controller\TransportsController;
use Nowo\MessengerMonitorKitBundle\DependencyInjection\Compiler\HistoryStoragePass;
use Nowo\MessengerMonitorKitBundle\DependencyInjection\Compiler\MessengerIntegrationPass;
use Nowo\MessengerMonitorKitBundle\Doctrine\TablePrefixListener;
use Nowo\MessengerMonitorKitBundle\Entity\ProcessedMessage;
use Nowo\MessengerMonitorKitBundle\EventListener\MessengerMonitorAccessListener;
use Nowo\MessengerMonitorKitBundle\Form\HistoryFilterType;
use Nowo\MessengerMonitorKitBundle\Form\MonitorSettingsType;
use Nowo\MessengerMonitorKitBundle\Form\PurgeType;
use Nowo\MessengerMonitorKitBundle\Health\QueueDepthProbe;
use Nowo\MessengerMonitorKitBundle\History\AutoPurger;
use Nowo\MessengerMonitorKitBundle\History\HistoryPurger;
use Nowo\MessengerMonitorKitBundle\History\PurgePeriod;
use Nowo\MessengerMonitorKitBundle\Messenger\FailedTransportDsnEnvVarProcessor;
use Nowo\MessengerMonitorKitBundle\Messenger\StreamOptionsAmqpTransportFactory;
use Nowo\MessengerMonitorKitBundle\Messenger\StreamOptionsDoctrineTransportFactory;
use Nowo\MessengerMonitorKitBundle\NowoMessengerMonitorKitBundle;
use Nowo\MessengerMonitorKitBundle\Payload\KeyPatternRedactor;
use Nowo\MessengerMonitorKitBundle\Payload\PayloadNormalizer;
use Nowo\MessengerMonitorKitBundle\Payload\PayloadRedactorInterface;
use Nowo\MessengerMonitorKitBundle\Payload\PayloadView;
use Nowo\MessengerMonitorKitBundle\Payload\Redactor;
use Nowo\MessengerMonitorKitBundle\Queue\QueueDepth;
use Nowo\MessengerMonitorKitBundle\Recording\RecordingSwitch;
use Nowo\MessengerMonitorKitBundle\Recording\ToggleableMonitorStorage;
use Nowo\MessengerMonitorKitBundle\Retry\FailedMessageRetrier;
use Nowo\MessengerMonitorKitBundle\Routing\MessengerMonitorRouteLoader;
use Nowo\MessengerMonitorKitBundle\Security\AccessGuard;
use Nowo\MessengerMonitorKitBundle\Security\AllowAllMessengerMonitorAccessChecker;
use Nowo\MessengerMonitorKitBundle\Security\ConfigurableMessengerMonitorAccessChecker;
use Nowo\MessengerMonitorKitBundle\Security\MessengerMonitorAccessCheckerInterface;
use Nowo\MessengerMonitorKitBundle\Settings\CacheMonitorSettingsStore;
use Nowo\MessengerMonitorKitBundle\Settings\ConfigMonitorSettingsStore;
use Nowo\MessengerMonitorKitBundle\Settings\InstanceSettingsMonitorSettingsStore;
use Nowo\MessengerMonitorKitBundle\Settings\MessengerMonitorSettingsSection;
use Nowo\MessengerMonitorKitBundle\Settings\MonitorSettings;
use Nowo\MessengerMonitorKitBundle\Settings\MonitorSettingsStoreInterface;
use Nowo\MessengerMonitorKitBundle\Settings\SettingsStore;
use Nowo\MessengerMonitorKitBundle\Twig\MessengerMonitorTwigExtension;
use Nowo\MessengerMonitorKitBundle\Ui\MonitorPageFactory;
use Nowo\MessengerMonitorKitBundle\Ui\MonitorPages;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\Argument\TaggedIteratorArgument;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\KernelEvents;

use function array_key_exists;
use function array_merge;
use function array_values;
use function interface_exists;
use function is_array;
use function is_bool;
use function is_string;
use function sprintf;

/**
 * Loads `nowo_messenger_monitor_kit`: recording switch and settings store, batched purge and retention, failed message
 * retrier, queue depth, redaction, optional Messenger integrations (retry strategy, transport factories, failure DSN
 * env processor, HealthKit probe, InstanceSettings section) and, when enabled, the admin UI with its access checker,
 * route loader and FormKit profile.
 */
final class NowoMessengerMonitorKitExtension extends Extension implements PrependExtensionInterface
{
    /** Default store of the runtime switches. */
    public const SETTINGS_STORE_ID = MonitorSettingsStoreInterface::class;

    /**
     * `nowo_messenger_monitor_kit`.
     */
    public function getAlias(): string
    {
        return Configuration::ALIAS;
    }

    /**
     * Prepends the Doctrine mapping and the zenstruck history entity (bundle entity, unless the host configured its
     * own) and the FormKit profile of the bundle forms.
     */
    public function prepend(ContainerBuilder $container): void
    {
        if ($this->bundleEntityEnabled($container) && !$this->hostEntityConfigured($container)) {
            if ($container->hasExtension('doctrine')) {
                $container->prependExtensionConfig('doctrine', [
                    'orm' => [
                        'mappings' => [
                            'NowoMessengerMonitorKitBundle' => [
                                'type'      => 'attribute',
                                'dir'       => __DIR__ . '/../Entity',
                                'prefix'    => 'Nowo\MessengerMonitorKitBundle\Entity',
                                'alias'     => 'NowoMessengerMonitorKit',
                                'is_bundle' => false,
                            ],
                        ],
                    ],
                ]);
            }
            if ($container->hasExtension('zenstruck_messenger_monitor')) {
                $container->prependExtensionConfig('zenstruck_messenger_monitor', ['storage' => ['orm' => ['entity_class' => ProcessedMessage::class]]]);
            }
        }

        $this->prependFormKitProfile($container);
    }

    /**
     * Registers the services from the processed configuration.
     */
    public function load(array $configs, ContainerBuilder $container): void
    {
        $config = $this->processConfiguration(new Configuration(), $configs);
        $this->assertLiteralBooleans($config);

        /** @var array{history: array{bundle_entity: bool}, doctrine: array{table_prefix: string}, recording: array{enabled: bool, optional_message_classes: list<string>, record_optional: bool, check_interval: int, store_results: bool}, settings: array{store: string, cache_pool: string}, retention: array{auto_purge: bool, older_than: string, keep_failed: bool, min_interval: int}, purge: array{batch_size: int, max_batches_per_request: int}, retry: array{batch_limit: int, fallback_transport: string|null, duplicate_risk_classes: list<string>, lock_ttl: float}, failure_transports: list<string>, queue_depth: array{transports: list<string>}, health_probe: array{enabled: bool, name: string, pending_warn_at: int|null, pending_fail_at: int|null, failed_warn_at: int|null, failed_fail_at: int|null, critical: bool}, payload: array{enabled: bool, max_depth: int, max_items: int, max_string_length: int}, redaction: array{keys: list<string>, extra_keys: list<string>, mask: string, mask_emails: bool, mask_long_numbers: bool}, retry_strategy: array{no_retry_on_decoding_failure: list<string>}, transport_factories: array{amqp: array{enabled: bool, prefix: string}, doctrine: array{enabled: bool}, failed_dsn_env: array{enabled: bool, queue_env: string, redis_env: string}}, web_ui: array{enabled: bool, path_prefix: string, layout_template: string|null, templates: array<string, string>, per_page: int, transport_list_limit: int}, security: array{access_roles: list<string>, manage_roles: list<string>|null, access_checker: string|null, allow_unauthenticated: bool}} $config */
        $webUi = $config['web_ui']['enabled'];
        if ($webUi && !$config['security']['allow_unauthenticated'] && !$this->hasSecurityBundle($container)) {
            throw new LogicException('NowoMessengerMonitorKitBundle: the admin UI requires symfony/security-bundle (or security.allow_unauthenticated: true in dev / demo only).');
        }

        $container->setParameter('nowo_messenger_monitor_kit.doctrine.table_prefix', $config['doctrine']['table_prefix']);
        $container->setParameter('nowo_messenger_monitor_kit.web_ui.enabled', $webUi);
        $container->setParameter('nowo_messenger_monitor_kit.web_ui.path_prefix', $config['web_ui']['path_prefix']);
        $container->setParameter('nowo_messenger_monitor_kit.security.allow_unauthenticated', $config['security']['allow_unauthenticated']);
        $container->setParameter(MessengerIntegrationPass::NO_RETRY_PARAMETER, $config['retry_strategy']['no_retry_on_decoding_failure']);

        $container->registerForAutoconfiguration(PayloadRedactorInterface::class)->addTag(NowoMessengerMonitorKitBundle::PAYLOAD_REDACTOR_TAG);

        $defaults = new Definition(MonitorSettings::class, [
            $config['recording']['enabled'],
            $config['recording']['record_optional'],
            $config['retention']['auto_purge'],
            PurgePeriod::from($config['retention']['older_than']),
            $config['retention']['keep_failed'],
        ]);

        $this->registerRedaction($container, $config['redaction'], $config['payload']);
        $this->registerSettings($container, $config['settings'], $defaults);
        $this->registerHistory($container, $config);
        $this->registerMessenger($container, $config);
        $this->registerHealthProbe($container, $config['health_probe']);
        $this->registerAccess($container, $config['security']);
        $this->registerWebUi($container, $config);
    }

    /**
     * Default FormKit profile of the bundle POST forms: bundle translation domain, no auto help / placeholder keys, no
     * framework classes (the UiKit scaffold theme paints the fields through its slots, REQ-UI-003).
     *
     * @return array<string, mixed>
     */
    public static function formKitProfile(): array
    {
        return [
            'alias'              => NowoMessengerMonitorKitBundle::FORM_KIT_PROFILE,
            'translation_domain' => NowoMessengerMonitorKitBundle::TRANSLATION_DOMAIN,
            'auto_help'          => false,
            'auto_placeholder'   => false,
            'defaults'           => ['attr' => [], 'row_attr' => []],
            'field_types'        => [],
        ];
    }

    /**
     * Fails the configuration when an optional integration is enabled without its package.
     *
     * @param string $interface interface the integration needs
     * @param string $option configuration path that enabled it
     * @param string $package Composer package providing it
     */
    public static function requirePackage(string $interface, string $option, string $package): void
    {
        if (!interface_exists($interface)) {
            throw new InvalidConfigurationException(sprintf('nowo_messenger_monitor_kit.%s needs %s (composer require %s).', $option, $package, $package));
        }
    }

    /**
     * @param array{keys: list<string>, extra_keys: list<string>, mask: string, mask_emails: bool, mask_long_numbers: bool} $redaction
     * @param array{enabled: bool, max_depth: int, max_items: int, max_string_length: int} $payload
     */
    private function registerRedaction(ContainerBuilder $container, array $redaction, array $payload): void
    {
        $container->setDefinition(KeyPatternRedactor::class, new Definition(KeyPatternRedactor::class, [
            array_values(array_merge($redaction['keys'], $redaction['extra_keys'])),
            $redaction['mask'],
            $redaction['mask_emails'],
            $redaction['mask_long_numbers'],
        ]));
        $container->setDefinition(Redactor::class, (new Definition(Redactor::class, [
            new Reference(KeyPatternRedactor::class),
            new TaggedIteratorArgument(NowoMessengerMonitorKitBundle::PAYLOAD_REDACTOR_TAG),
        ]))->setPublic(true));
        $container->setDefinition(PayloadNormalizer::class, new Definition(PayloadNormalizer::class, [$payload['max_depth'], $payload['max_items'], $payload['max_string_length']]));
        $container->setDefinition(PayloadView::class, (new Definition(PayloadView::class, [
            new Reference(PayloadNormalizer::class),
            new Reference(Redactor::class),
            $payload['enabled'],
        ]))->setPublic(true));
    }

    /**
     * @param array{store: string, cache_pool: string} $settings
     */
    private function registerSettings(ContainerBuilder $container, array $settings, Definition $defaults): void
    {
        $store = SettingsStore::from($settings['store']);
        $pool  = new Reference($settings['cache_pool']);

        if ($store === SettingsStore::InstanceSettings) {
            self::requirePackage(InstanceSettingsInterface::class, 'settings.store: instance_settings', 'nowo-tech/instance-settings-bundle');
            $container->setDefinition(MessengerMonitorSettingsSection::class, (new Definition(MessengerMonitorSettingsSection::class, [$defaults]))
                ->addTag('nowo_instance_settings.section'));
            $definition = new Definition(InstanceSettingsMonitorSettingsStore::class, [
                new Reference(InstanceSettingsInterface::class),
                $defaults,
                new Reference('logger', ContainerInterface::NULL_ON_INVALID_REFERENCE),
            ]);
        } elseif ($store === SettingsStore::Config) {
            $definition = new Definition(ConfigMonitorSettingsStore::class, [$defaults]);
        } else {
            $definition = new Definition(CacheMonitorSettingsStore::class, [$pool, $defaults, new Reference('logger', ContainerInterface::NULL_ON_INVALID_REFERENCE)]);
        }

        $container->setDefinition(self::SETTINGS_STORE_ID, $definition->setPublic(true));
    }

    /**
     * @param array<string, mixed> $config
     */
    private function registerHistory(ContainerBuilder $container, array $config): void
    {
        /* @var array{doctrine: array{table_prefix: string}, recording: array{optional_message_classes: list<string>, check_interval: int, store_results: bool}, settings: array{cache_pool: string}, retention: array{min_interval: int}, purge: array{batch_size: int}} $config */
        $container->setDefinition(TablePrefixListener::class, (new Definition(TablePrefixListener::class, [$config['doctrine']['table_prefix']]))
            ->addTag('doctrine.event_listener', ['event' => 'loadClassMetadata']));

        $container->setDefinition(RecordingSwitch::class, new Definition(RecordingSwitch::class, [
            new Reference(self::SETTINGS_STORE_ID),
            new Reference('clock'),
            $config['recording']['check_interval'],
        ]));
        // Decorates zenstruck's storage in HistoryStoragePass (removed when there is no ORM history storage).
        $container->setDefinition(ToggleableMonitorStorage::class, new Definition(ToggleableMonitorStorage::class, [
            new Reference('.inner'),
            new Reference(RecordingSwitch::class),
            new Reference(Redactor::class),
            $config['recording']['optional_message_classes'],
            $config['recording']['store_results'],
        ]));

        $container->setDefinition(HistoryPurger::class, (new Definition(HistoryPurger::class, [
            new Reference('doctrine'),
            ProcessedMessage::class,
            new Reference('clock'),
            $config['purge']['batch_size'],
            new Reference('logger', ContainerInterface::NULL_ON_INVALID_REFERENCE),
        ]))->setPublic(true));
        $container->setDefinition(AutoPurger::class, (new Definition(AutoPurger::class, [
            new Reference(HistoryPurger::class),
            new Reference(self::SETTINGS_STORE_ID),
            new Reference($config['settings']['cache_pool']),
            new Reference('clock'),
            $config['retention']['min_interval'],
            new Reference('logger', ContainerInterface::NULL_ON_INVALID_REFERENCE),
        ]))->setPublic(true));
        $container->setDefinition(PurgeHistoryCommand::class, (new Definition(PurgeHistoryCommand::class, [
            new Reference(HistoryPurger::class),
            new Reference(AutoPurger::class),
        ]))->addTag('console.command', ['command' => PurgeHistoryCommand::NAME]));
    }

    /**
     * @param array<string, mixed> $config
     */
    private function registerMessenger(ContainerBuilder $container, array $config): void
    {
        /* @var array{retry: array{batch_limit: int, fallback_transport: string|null, duplicate_risk_classes: list<string>, lock_ttl: float}, failure_transports: list<string>, queue_depth: array{transports: list<string>}, transport_factories: array{amqp: array{enabled: bool, prefix: string}, doctrine: array{enabled: bool}, failed_dsn_env: array{enabled: bool, queue_env: string, redis_env: string}}} $config */
        $container->setDefinition(FailedMessageRetrier::class, (new Definition(FailedMessageRetrier::class, [
            new Reference('zenstruck_messenger_monitor.transports'),
            new Reference('messenger.routable_message_bus'),
            $config['failure_transports'],
            $config['retry']['fallback_transport'],
            $config['retry']['duplicate_risk_classes'],
            new Reference('lock.factory', ContainerInterface::NULL_ON_INVALID_REFERENCE),
            $config['retry']['lock_ttl'],
            new Reference('logger', ContainerInterface::NULL_ON_INVALID_REFERENCE),
        ]))->setPublic(true));
        $container->setDefinition(QueueDepth::class, (new Definition(QueueDepth::class, [
            new Reference('zenstruck_messenger_monitor.transports'),
            new Reference(FailedMessageRetrier::class),
            $config['queue_depth']['transports'],
            new Reference('logger', ContainerInterface::NULL_ON_INVALID_REFERENCE),
        ]))->setPublic(true));

        $factories = $config['transport_factories'];
        $inner     = [];
        if ($factories['amqp']['enabled']) {
            $inner['amqp'] = 'messenger.transport.amqp.factory';
            $container->setDefinition(StreamOptionsAmqpTransportFactory::class, (new Definition(StreamOptionsAmqpTransportFactory::class, [new Reference($inner['amqp']), $factories['amqp']['prefix']]))
                ->addTag('messenger.transport_factory', ['priority' => 10]));
        }
        if ($factories['doctrine']['enabled']) {
            $inner['doctrine'] = 'messenger.transport.doctrine.factory';
            $container->setDefinition(StreamOptionsDoctrineTransportFactory::class, (new Definition(StreamOptionsDoctrineTransportFactory::class, [new Reference($inner['doctrine'])]))
                ->addTag('messenger.transport_factory', ['priority' => 10]));
        }
        $container->setParameter(MessengerIntegrationPass::FACTORIES_PARAMETER, $inner);

        if ($factories['failed_dsn_env']['enabled']) {
            $container->setDefinition(FailedTransportDsnEnvVarProcessor::class, (new Definition(FailedTransportDsnEnvVarProcessor::class, [$factories['failed_dsn_env']['queue_env'], $factories['failed_dsn_env']['redis_env']]))
                ->addTag('container.env_var_processor'));
        }
    }

    /**
     * @param array{enabled: bool, name: string, pending_warn_at: int|null, pending_fail_at: int|null, failed_warn_at: int|null, failed_fail_at: int|null, critical: bool} $probe
     */
    private function registerHealthProbe(ContainerBuilder $container, array $probe): void
    {
        if (!$probe['enabled']) {
            return;
        }
        self::requirePackage(HealthProbeInterface::class, 'health_probe.enabled', 'nowo-tech/health-kit-bundle');

        $container->setDefinition(QueueDepthProbe::class, (new Definition(QueueDepthProbe::class, [
            new Reference(QueueDepth::class),
            $probe['pending_warn_at'],
            $probe['pending_fail_at'],
            $probe['failed_warn_at'],
            $probe['failed_fail_at'],
        ]))->addTag('nowo_health_kit.probe', ['name' => $probe['name'], 'groups' => ['ready'], 'critical' => $probe['critical']]));
    }

    /**
     * @param array{access_roles: list<string>, manage_roles: list<string>|null, access_checker: string|null, allow_unauthenticated: bool} $security
     */
    private function registerAccess(ContainerBuilder $container, array $security): void
    {
        $customId = $security['access_checker'];
        if (is_string($customId) && $customId !== '') {
            $container->setAlias(MessengerMonitorAccessCheckerInterface::class, $customId)->setPublic(true);
        } elseif ($security['allow_unauthenticated']) {
            $container->setDefinition(AllowAllMessengerMonitorAccessChecker::class, new Definition(AllowAllMessengerMonitorAccessChecker::class));
            $container->setAlias(MessengerMonitorAccessCheckerInterface::class, AllowAllMessengerMonitorAccessChecker::class)->setPublic(true);
        } else {
            $container->setDefinition(ConfigurableMessengerMonitorAccessChecker::class, new Definition(ConfigurableMessengerMonitorAccessChecker::class, [
                new Reference('security.authorization_checker', ContainerInterface::NULL_ON_INVALID_REFERENCE),
                Configuration::roles($security['access_roles']),
                is_array($security['manage_roles']) ? Configuration::roles($security['manage_roles']) : null,
            ]));
            $container->setAlias(MessengerMonitorAccessCheckerInterface::class, ConfigurableMessengerMonitorAccessChecker::class)->setPublic(true);
        }

        $container->setDefinition(AccessGuard::class, (new Definition(AccessGuard::class, [
            new Reference(MessengerMonitorAccessCheckerInterface::class),
            $security['allow_unauthenticated'],
            new Reference('security.token_storage', ContainerInterface::NULL_ON_INVALID_REFERENCE),
            new Reference('request_stack', ContainerInterface::NULL_ON_INVALID_REFERENCE),
            new Reference('security.firewall.map', ContainerInterface::NULL_ON_INVALID_REFERENCE),
        ]))->setPublic(true));
    }

    /**
     * @param array<string, mixed> $config
     */
    private function registerWebUi(ContainerBuilder $container, array $config): void
    {
        /** @var array{web_ui: array{enabled: bool, path_prefix: string, layout_template: string|null, templates: array<string, string>, per_page: int, transport_list_limit: int}, purge: array{max_batches_per_request: int}, retry: array{batch_limit: int}} $config */
        $webUi = $config['web_ui'];

        $container->setDefinition(MessengerMonitorRouteLoader::class, (new Definition(MessengerMonitorRouteLoader::class, [$webUi['enabled'], $webUi['path_prefix']]))
            ->addTag('routing.loader'));
        $container->setDefinition(MessengerMonitorTwigExtension::class, (new Definition(MessengerMonitorTwigExtension::class))->addTag('twig.extension'));

        if (!$webUi['enabled']) {
            return;
        }

        $container->setDefinition(HistoryFilterType::class, (new Definition(HistoryFilterType::class))->setAutowired(true)->addTag('form.type'));
        foreach ([PurgeType::class, MonitorSettingsType::class] as $type) {
            $container->setDefinition($type, (new Definition($type))->addTag('form.type'));
        }

        $csrfForms   = 'nowo_messenger_monitor_kit.csrf_form_factory';
        $filterForms = 'nowo_messenger_monitor_kit.filter_form_factory';
        $container->setDefinition($csrfForms, new Definition(CsrfOnlyFormFactory::class, [new Reference('form.factory')]));
        $container->setDefinition($filterForms, new Definition(GetFilterFormFactory::class, [new Reference('form.factory')]));

        $container->setDefinition(MonitorPages::class, new Definition(MonitorPages::class, [$webUi['layout_template'], $webUi['templates']]));
        $container->setDefinition(MonitorPageFactory::class, new Definition(MonitorPageFactory::class, [
            new Reference('router'),
            new Reference($csrfForms),
            new Reference(MonitorPages::class),
        ]));

        $container->setDefinition(MessengerMonitorAccessListener::class, (new Definition(MessengerMonitorAccessListener::class, [
            new Reference(AccessGuard::class),
            $webUi['path_prefix'],
        ]))->addTag('kernel.event_listener', ['event' => KernelEvents::REQUEST, 'method' => 'onKernelRequest', 'priority' => MessengerMonitorAccessListener::PRIORITY]));

        $storage = new Reference(HistoryStoragePass::STORAGE_ID);
        $common  = [
            new Reference(AccessGuard::class),
            new Reference(MonitorPageFactory::class),
            new Reference($csrfForms),
            new Reference('event_dispatcher', ContainerInterface::NULL_ON_INVALID_REFERENCE),
            new Reference('translator', ContainerInterface::NULL_ON_INVALID_REFERENCE),
        ];

        $this->registerController($container, DashboardController::class, [
            $storage,
            new Reference('zenstruck_messenger_monitor.workers'),
            new Reference(QueueDepth::class),
            new Reference(self::SETTINGS_STORE_ID),
            ...$common,
        ]);
        $this->registerController($container, HistoryController::class, [
            $storage,
            new Reference('zenstruck_messenger_monitor.transports'),
            new Reference($filterForms),
            new Reference(Redactor::class),
            new Reference(PayloadView::class),
            $webUi['per_page'],
            ...$common,
        ]);
        $this->registerController($container, TransportsController::class, [
            new Reference('zenstruck_messenger_monitor.transports'),
            new Reference(QueueDepth::class),
            new Reference(FailedMessageRetrier::class),
            new Reference(PayloadView::class),
            new Reference(Redactor::class),
            $webUi['transport_list_limit'],
            $config['retry']['batch_limit'],
            ...$common,
        ]);
        $this->registerController($container, SettingsController::class, [
            new Reference(self::SETTINGS_STORE_ID),
            new Reference(HistoryPurger::class),
            new Reference(AutoPurger::class),
            $storage,
            $config['purge']['max_batches_per_request'],
            ...$common,
        ]);
    }

    /**
     * REQ-SF-004 rule 7: not autoconfigured (no `routing.controller` tag from #[Route]); the routes come only from the
     * `nowo_messenger_monitor_kit` loader. Autowired only for AbstractController::setContainer().
     *
     * @param class-string $class
     * @param list<mixed> $arguments
     */
    private function registerController(ContainerBuilder $container, string $class, array $arguments): void
    {
        $container->setDefinition($class, (new Definition($class, $arguments))
            ->setAutowired(true)
            ->setAutoconfigured(false)
            ->setPublic(true)
            ->addTag('controller.service_arguments')
            ->addTag('container.service_subscriber'));
    }

    /**
     * Registers the FormKit profile {@see NowoMessengerMonitorKitBundle::FORM_KIT_PROFILE} unless the host defines it.
     */
    private function prependFormKitProfile(ContainerBuilder $container): void
    {
        if (!$container->hasExtension('nowo_form_kit')) {
            return;
        }

        foreach ($container->getExtensionConfig('nowo_form_kit') as $config) {
            $profiles = $config['profiles'] ?? null;
            if (is_array($profiles) && array_key_exists(NowoMessengerMonitorKitBundle::FORM_KIT_PROFILE, $profiles)) {
                return;
            }
        }

        $container->prependExtensionConfig('nowo_form_kit', [
            'profiles' => [NowoMessengerMonitorKitBundle::FORM_KIT_PROFILE => self::formKitProfile()],
        ]);
    }

    /**
     * `history.bundle_entity` from the raw configuration (default true).
     */
    private function bundleEntityEnabled(ContainerBuilder $container): bool
    {
        $enabled = true;
        foreach ($container->getExtensionConfig(Configuration::ALIAS) as $config) {
            $value = $config['history']['bundle_entity'] ?? null;
            if (is_bool($value)) {
                $enabled = $value;
            }
        }

        return $enabled;
    }

    /**
     * Whether the host already set `zenstruck_messenger_monitor.storage.orm.entity_class`.
     */
    private function hostEntityConfigured(ContainerBuilder $container): bool
    {
        foreach ($container->getExtensionConfig('zenstruck_messenger_monitor') as $config) {
            if (is_string($config['storage']['orm']['entity_class'] ?? null)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether SecurityBundle is registered.
     */
    private function hasSecurityBundle(ContainerBuilder $container): bool
    {
        $bundles = $container->hasParameter('kernel.bundles') ? $container->getParameter('kernel.bundles') : [];

        return (is_array($bundles) && array_key_exists('SecurityBundle', $bundles)) || $container->hasExtension('security');
    }

    /**
     * Security switches must be literal booleans: an `%env(bool:…)%` placeholder is a non-empty string while the
     * container is built and would read as true (fail open).
     *
     * @param array<string, mixed> $config
     */
    private function assertLiteralBooleans(array $config): void
    {
        /** @var array<string, array<string, mixed>> $config */
        $switches = [
            'web_ui.enabled'                 => $config['web_ui']['enabled'] ?? null,
            'security.allow_unauthenticated' => $config['security']['allow_unauthenticated'] ?? null,
            'payload.enabled'                => $config['payload']['enabled'] ?? null,
        ];
        foreach ($switches as $path => $value) {
            if (!is_bool($value)) {
                throw new InvalidConfigurationException(sprintf('nowo_messenger_monitor_kit.%s must be a literal boolean (true or false); environment placeholders such as %%env(bool:...)%% are not supported for security switches.', $path));
            }
        }
    }
}
