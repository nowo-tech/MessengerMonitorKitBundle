<?php

declare(strict_types=1);

namespace Nowo\MessengerMonitorKitBundle\DependencyInjection;

use Nowo\MessengerMonitorKitBundle\History\PurgePeriod;
use Nowo\MessengerMonitorKitBundle\Payload\KeyPatternRedactor;
use Nowo\MessengerMonitorKitBundle\Settings\SettingsStore;
use Nowo\MessengerMonitorKitBundle\Ui\MonitorPages;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;

use function array_diff;
use function array_keys;
use function array_map;
use function array_values;
use function implode;
use function in_array;
use function is_array;
use function is_int;
use function is_string;
use function preg_match;
use function sprintf;
use function str_starts_with;
use function trim;

/**
 * Configuration tree of `nowo_messenger_monitor_kit` (REQ-SF-006: strict nodes, closed sets, safe defaults).
 */
final class Configuration implements ConfigurationInterface
{
    /** Extension alias / root key. */
    public const ALIAS = 'nowo_messenger_monitor_kit';

    /** Default URL prefix of the admin UI. */
    public const DEFAULT_PATH_PREFIX = '/admin/messenger';

    /** Page sizes accepted by the history list (UiKit `Pagination::ALLOWED_PER_PAGE`). */
    public const PER_PAGE_SIZES = [10, 25, 50, 100];

    /**
     * Builds the tree.
     */
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder(self::ALIAS);
        /** @var ArrayNodeDefinition $root */
        $root = $treeBuilder->getRootNode();
        $root->info('Nowo Messenger Monitor Kit — runtime recording switch, batched retention, safe retry, queue depth and a UiKit admin UI on top of zenstruck/messenger-monitor-bundle.');

        $children = $root->children();
        $this->addHistory($children->arrayNode('history'));
        $this->addDoctrine($children->arrayNode('doctrine'));
        $this->addRecording($children->arrayNode('recording'));
        $this->addSettings($children->arrayNode('settings'));
        $this->addRetention($children->arrayNode('retention'));
        $this->addPurge($children->arrayNode('purge'));
        $this->addRetry($children->arrayNode('retry'));
        $children->arrayNode('failure_transports')
            ->info('Failure transport names (retry / remove targets). Empty = every transport whose name contains "fail" (zenstruck detection).')
            ->scalarPrototype()->cannotBeEmpty()->end()
        ->end();
        $this->addQueueDepth($children->arrayNode('queue_depth'));
        $this->addHealthProbe($children->arrayNode('health_probe'));
        $this->addPayload($children->arrayNode('payload'));
        $this->addRedaction($children->arrayNode('redaction'));
        $this->addRetryStrategy($children->arrayNode('retry_strategy'));
        $this->addTransportFactories($children->arrayNode('transport_factories'));
        $this->addWebUi($children->arrayNode('web_ui'));
        $this->addSecurity($children->arrayNode('security'));
        $children->end();

        return $treeBuilder;
    }

    /**
     * Non-empty role strings of a configured list.
     *
     * @return list<string>
     */
    public static function roles(mixed $roles): array
    {
        $list = [];
        foreach (is_array($roles) ? array_values($roles) : [] as $role) {
            if (is_string($role) && trim($role) !== '') {
                $list[] = trim($role);
            }
        }

        return $list;
    }

    private function addHistory(ArrayNodeDefinition $node): void
    {
        $node
            ->info('History storage (zenstruck ORM storage).')
            ->addDefaultsIfNotSet()
            ->children()
                ->booleanNode('bundle_entity')
                    ->info('true = map the bundle entity (table messenger_processed_messages) and set it as zenstruck_messenger_monitor.storage.orm.entity_class unless the host configured its own entity.')
                    ->defaultTrue()
                ->end()
            ->end();
    }

    private function addDoctrine(ArrayNodeDefinition $node): void
    {
        $node
            ->addDefaultsIfNotSet()
            ->children()
                ->scalarNode('table_prefix')
                    ->info('Prefix of the bundle table and index names (REQ-ORM-002): "app_" gives app_messenger_processed_messages.')
                    ->defaultValue('')
                    ->validate()
                        ->ifTrue(static fn (mixed $v): bool => !is_string($v) || preg_match('/^[A-Za-z0-9_]*$/', $v) !== 1)
                        ->thenInvalid('nowo_messenger_monitor_kit.doctrine.table_prefix must contain only letters, digits and underscores, got %s.')
                    ->end()
                ->end()
                ->scalarNode('entity_manager')
                    ->info('Doctrine entity manager that maps the bundle history entity (1.1). Null (default): the default manager. When set, the entity is mapped on that manager ONLY (removed from the others, auto_mapping included), every read / purge uses it, the bundle clears it at the end of each request / message / command, and the build fails when the manager does not exist. Recording never flushes any manager (DBAL inserts).')
                    ->defaultNull()
                    ->validate()
                        ->ifTrue(static fn (mixed $v): bool => $v !== null && (!is_string($v) || preg_match('/^\w+$/', $v) !== 1))
                        ->thenInvalid('nowo_messenger_monitor_kit.doctrine.entity_manager must be an entity manager name or null, got %s.')
                    ->end()
                ->end()
            ->end();
    }

    private function addRecording(ArrayNodeDefinition $node): void
    {
        $node
            ->info('History recording. The runtime switches (Settings page / store) override enabled and record_optional.')
            ->addDefaultsIfNotSet()
            ->children()
                ->booleanNode('enabled')
                    ->info('Default of the runtime "record history" switch while the store holds no value.')
                    ->defaultTrue()
                ->end()
                ->arrayNode('optional_message_classes')
                    ->info('High-volume message classes (or parent classes / interfaces) recorded only while the "record optional messages" switch is on.')
                    ->scalarPrototype()->cannotBeEmpty()->end()
                ->end()
                ->booleanNode('record_optional')
                    ->info('Default of the runtime "record optional messages" switch.')
                    ->defaultFalse()
                ->end()
                ->integerNode('check_interval')
                    ->info('Seconds a worker reuses the switches before reading the store again (bounds staleness, avoids one read per message).')
                    ->defaultValue(30)
                    ->min(0)
                    ->max(3600)
                ->end()
                ->booleanNode('store_results')
                    ->info('false = drop handler results (return values, e-mail headers, process output) before saving a history row (data minimisation).')
                    ->defaultTrue()
                ->end()
            ->end();
    }

    private function addSettings(ArrayNodeDefinition $node): void
    {
        $node
            ->info('Where the runtime switches live.')
            ->addDefaultsIfNotSet()
            ->children()
                ->enumNode('store')
                    ->info('cache = a shared PSR-6 pool (default); instance_settings = a "messenger_monitor" section of nowo-tech/instance-settings-bundle; config = read-only YAML defaults (no Settings form).')
                    ->values(array_map(static fn (SettingsStore $store): string => $store->value, SettingsStore::cases()))
                    ->defaultValue(SettingsStore::Cache->value)
                ->end()
                ->scalarNode('cache_pool')
                    ->info('PSR-6 pool of the cache store and of the auto-purge bookkeeping. Must be shared by web and worker processes (Redis / Doctrine / filesystem on one host).')
                    ->defaultValue('cache.app')
                    ->cannotBeEmpty()
                ->end()
            ->end();
    }

    private function addRetention(ArrayNodeDefinition $node): void
    {
        $node
            ->info('Defaults of the automatic purge (nowo:messenger-monitor:purge --auto); runtime switches override them.')
            ->addDefaultsIfNotSet()
            ->children()
                ->booleanNode('auto_purge')->info('Default of the "automatic purge" switch.')->defaultFalse()->end()
                ->enumNode('older_than')
                    ->info('Default retention: history older than this is purged.')
                    ->values(array_map(static fn (PurgePeriod $period): string => $period->value, PurgePeriod::retentionCases()))
                    ->defaultValue(PurgePeriod::OneMonth->value)
                ->end()
                ->booleanNode('keep_failed')->info('Default: keep failed rows when purging.')->defaultTrue()->end()
                ->integerNode('min_interval')
                    ->info('Seconds between two automatic purges; schedule the command more often (e.g. every 15 minutes).')
                    ->defaultValue(3600)
                    ->min(60)
                    ->max(604_800)
                ->end()
            ->end();
    }

    private function addPurge(ArrayNodeDefinition $node): void
    {
        $node
            ->info('Batched (keyset) purge of the history table.')
            ->addDefaultsIfNotSet()
            ->children()
                ->integerNode('batch_size')->info('Rows deleted per statement.')->defaultValue(1000)->min(100)->max(50_000)->end()
                ->integerNode('max_batches_per_request')
                    ->info('Cap of one HTTP purge (batch_size x this); the CLI has no cap. The flash says when rows remain.')
                    ->defaultValue(20)
                    ->min(1)
                    ->max(1000)
                ->end()
            ->end();
    }

    private function addRetry(ArrayNodeDefinition $node): void
    {
        $node
            ->info('Retry of failed messages to their original transport.')
            ->addDefaultsIfNotSet()
            ->children()
                ->integerNode('batch_limit')->info('Messages handled by one "retry all" / "remove all".')->defaultValue(50)->min(1)->max(500)->end()
                ->scalarNode('fallback_transport')
                    ->info('Transport used when the original one no longer exists (or is not a queue, e.g. a scheduler); null = leave the message parked.')
                    ->defaultNull()
                ->end()
                ->arrayNode('duplicate_risk_classes')
                    ->info('Message classes (or parents) whose handlers may have partially run: the retry asks for a stronger confirmation.')
                    ->scalarPrototype()->cannotBeEmpty()->end()
                ->end()
                ->floatNode('lock_ttl')->info('Seconds a retry / removal of one message is locked (needs symfony/lock).')->defaultValue(30.0)->min(1.0)->max(600.0)->end()
            ->end();
    }

    private function addQueueDepth(ArrayNodeDefinition $node): void
    {
        $node
            ->addDefaultsIfNotSet()
            ->children()
                ->arrayNode('transports')
                    ->info('Transports counted by the queue depth service; empty = every countable transport (sync and scheduler excluded).')
                    ->scalarPrototype()->cannotBeEmpty()->end()
                ->end()
            ->end();
    }

    private function addHealthProbe(ArrayNodeDefinition $node): void
    {
        $node
            ->info('HealthKit probe fed by the queue depth service (needs nowo-tech/health-kit-bundle).')
            ->canBeEnabled()
            ->children()
                ->scalarNode('name')
                    ->defaultValue('messenger_queue_depth')
                    ->validate()
                        ->ifTrue(static fn (mixed $v): bool => !is_string($v) || preg_match('/^[a-z0-9_.\-]+$/', $v) !== 1)
                        ->thenInvalid('nowo_messenger_monitor_kit.health_probe.name must match ^[a-z0-9_.-]+$, got %s.')
                    ->end()
                ->end()
                ->integerNode('pending_warn_at')->defaultNull()->min(0)->end()
                ->integerNode('pending_fail_at')->defaultNull()->min(0)->end()
                ->integerNode('failed_warn_at')->defaultValue(1)->min(0)->end()
                ->integerNode('failed_fail_at')->defaultNull()->min(0)->end()
                ->booleanNode('critical')->info('false = a failure only degrades the report (HTTP 200).')->defaultFalse()->end()
            ->end()
            ->validate()
                ->ifTrue(static fn (array $v): bool => self::thresholdsInverted($v, 'pending') || self::thresholdsInverted($v, 'failed'))
                ->thenInvalid('nowo_messenger_monitor_kit.health_probe: *_warn_at must be lower than or equal to *_fail_at.')
            ->end();
    }

    private function addPayload(ArrayNodeDefinition $node): void
    {
        $node
            ->info('Payload view of queued (failed) messages; always redacted, escaped and size-capped.')
            ->addDefaultsIfNotSet()
            ->children()
                ->booleanNode('enabled')->info('false = never show message payloads in the UI.')->defaultTrue()->end()
                ->integerNode('max_depth')->defaultValue(4)->min(1)->max(10)->end()
                ->integerNode('max_items')->info('Items shown per array / object.')->defaultValue(50)->min(1)->max(500)->end()
                ->integerNode('max_string_length')->defaultValue(500)->min(16)->max(10_000)->end()
                ->integerNode('max_nodes')->info('Values normalised in the whole message graph (CPU / memory budget).')->defaultValue(2000)->min(10)->max(100_000)->end()
                ->integerNode('max_bytes')->info('Bytes of JSON shown per payload / result; the rest is cut.')->defaultValue(262_144)->min(1024)->max(16_777_216)->end()
            ->end();
    }

    private function addRedaction(ArrayNodeDefinition $node): void
    {
        $node
            ->info('Redaction of payloads, handler results, failure messages and descriptions shown in the UI (personal data / secrets); credentials in free text are always masked.')
            ->addDefaultsIfNotSet()
            ->children()
                ->arrayNode('keys')
                    ->info('Property / key names whose value is masked (case-insensitive, "_", "-", "." ignored; matched inside a name, or as the whole name for 2-letter keys and keys written "=name"). Replaces the default list.')
                    ->scalarPrototype()->cannotBeEmpty()->end()
                    ->defaultValue(KeyPatternRedactor::DEFAULT_KEYS)
                ->end()
                ->arrayNode('extra_keys')
                    ->info('Keys added to the list above.')
                    ->scalarPrototype()->cannotBeEmpty()->end()
                ->end()
                ->scalarNode('mask')->defaultValue(KeyPatternRedactor::DEFAULT_MASK)->cannotBeEmpty()->end()
                ->booleanNode('mask_emails')->info('Mask e-mail addresses inside any string value.')->defaultTrue()->end()
                ->booleanNode('mask_long_numbers')->info('Mask Spanish DNI / NIE and runs of 9+ digits (phones, cards, accounts) inside any string value.')->defaultTrue()->end()
            ->end();
    }

    private function addRetryStrategy(ArrayNodeDefinition $node): void
    {
        $node
            ->addDefaultsIfNotSet()
            ->children()
                ->arrayNode('no_retry_on_decoding_failure')
                    ->info('Transports whose retry strategy never retries a message that cannot be decoded (sent straight to the failure transport).')
                    ->scalarPrototype()->cannotBeEmpty()->end()
                ->end()
            ->end();
    }

    private function addTransportFactories(ArrayNodeDefinition $node): void
    {
        $node
            ->info('Broker-neutral transport options: one messenger.yaml with Redis-stream options works with amqp:// and doctrine:// DSNs.')
            ->addDefaultsIfNotSet()
            ->children()
                ->arrayNode('amqp')
                    ->canBeEnabled()
                    ->children()
                        ->scalarNode('prefix')
                            ->info('Prefix of the exchange / queue / delay exchange names built from the "stream" option (e.g. "app.").')
                            ->defaultValue('')
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('doctrine')
                    ->canBeEnabled()
                ->end()
                ->arrayNode('failed_dsn_env')
                    ->info('%env(messenger_failed_dsn:NAME)% processor: an empty NAME falls back to the queue DSN, or to the Redis URL when the queue is AMQP.')
                    ->canBeEnabled()
                    ->children()
                        ->scalarNode('queue_env')->defaultValue('MESSENGER_TRANSPORT_DSN')->cannotBeEmpty()->end()
                        ->scalarNode('redis_env')->defaultValue('REDIS_URL')->cannotBeEmpty()->end()
                    ->end()
                ->end()
            ->end();
    }

    private function addWebUi(ArrayNodeDefinition $node): void
    {
        $node
            ->info('Admin UI (REQ-UI-001 / REQ-UI-003): dashboard, history, transports, failed messages, settings.')
            ->addDefaultsIfNotSet()
            ->children()
                ->booleanNode('enabled')->info('false = no admin UI (no routes, no controllers); services and CLI stay available.')->defaultTrue()->end()
                ->scalarNode('path_prefix')
                    ->info('URL prefix of the admin routes; protect it with security.access_control in the host.')
                    ->defaultValue(self::DEFAULT_PATH_PREFIX)
                    ->validate()
                        ->ifTrue(static fn (mixed $v): bool => !is_string($v) || trim($v, '/ ') === '' || !str_starts_with($v, '/'))
                        ->thenInvalid('nowo_messenger_monitor_kit.web_ui.path_prefix must be an absolute path such as "/admin/messenger", got %s.')
                    ->end()
                ->end()
                ->scalarNode('layout_template')->info('Layout the pages extend. Null (default) = nowo_ui_kit.layouts.admin.')->defaultNull()->end()
                ->arrayNode('templates')
                    ->info('Per-page template overrides (page id => template). Extend @!NowoMessengerMonitorKitBundle/monitor/... and override a block.')
                    ->useAttributeAsKey('page')
                    ->normalizeKeys(false)
                    ->scalarPrototype()->cannotBeEmpty()->end()
                    ->validate()
                        ->ifTrue(static fn (array $v): bool => array_diff(array_keys($v), array_keys(MonitorPages::TEMPLATES)) !== [])
                        ->then(static function (array $v): never {
                            throw new InvalidConfigurationException(sprintf('Unknown nowo_messenger_monitor_kit.web_ui.templates page(s) "%s"; allowed: %s.', implode('", "', array_diff(array_keys($v), array_keys(MonitorPages::TEMPLATES))), implode(', ', array_keys(MonitorPages::TEMPLATES))));
                        })
                    ->end()
                ->end()
                ->integerNode('per_page')
                    ->info('Default page size of the history list (10, 25, 50 or 100).')
                    ->defaultValue(25)
                    ->validate()
                        ->ifTrue(static fn (int $v): bool => !in_array($v, self::PER_PAGE_SIZES, true))
                        ->thenInvalid('nowo_messenger_monitor_kit.web_ui.per_page must be one of 10, 25, 50, 100, got %s.')
                    ->end()
                ->end()
                ->integerNode('transport_list_limit')->info('Queued messages listed per transport page.')->defaultValue(50)->min(1)->max(500)->end()
            ->end();
    }

    private function addSecurity(ArrayNodeDefinition $node): void
    {
        $node
            ->info('REQ-UI-002 private access of the admin UI.')
            ->addDefaultsIfNotSet()
            ->children()
                ->arrayNode('access_roles')
                    ->info('Roles that open the read-only pages (any of them). Must not be empty unless access_checker is set or allow_unauthenticated is true.')
                    ->scalarPrototype()->end()
                    ->defaultValue(['ROLE_ADMIN'])
                ->end()
                ->arrayNode('manage_roles')
                    ->info('Roles allowed to retry / remove / purge, change the settings and read payloads. Unset (null) = access_roles.')
                    ->scalarPrototype()->end()
                    ->defaultNull()
                ->end()
                ->scalarNode('access_checker')->info('Service id implementing MessengerMonitorAccessCheckerInterface; null = role-based default.')->defaultNull()->end()
                ->booleanNode('allow_unauthenticated')->info('DEV / DEMO only (refused without kernel.debug): open the UI without login.')->defaultFalse()->end()
            ->end()
            ->validate()
                ->ifTrue(static fn (array $v): bool => !self::hasCustomAccess($v) && (self::roles($v['access_roles'] ?? []) === [] || (is_array($v['manage_roles'] ?? null) && self::roles($v['manage_roles']) === [])))
                ->thenInvalid('nowo_messenger_monitor_kit.security.access_roles (and manage_roles when set) must contain at least one non-empty role when no access_checker is set and allow_unauthenticated is false (an empty list would deny everybody).')
            ->end();
    }

    /**
     * @param array<string, mixed> $security
     */
    private static function hasCustomAccess(array $security): bool
    {
        return ($security['allow_unauthenticated'] ?? false) === true || (is_string($security['access_checker'] ?? null) && $security['access_checker'] !== '');
    }

    /**
     * @param array<string, mixed> $probe
     */
    private static function thresholdsInverted(array $probe, string $kind): bool
    {
        $warn = $probe[$kind . '_warn_at'] ?? null;
        $fail = $probe[$kind . '_fail_at'] ?? null;

        return is_int($warn) && is_int($fail) && $warn > $fail;
    }
}
