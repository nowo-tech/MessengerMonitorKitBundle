# Code inventory — 100% traceability

**Baseline spec**: [`spec.md`](spec.md)  
**Package**: `nowo-tech/messenger-monitor-kit-bundle`  
**Last audited**: 2026-11-01

Scope: every file under `src/`. Audit command: `find src -type f | wc -l` → **78**.

## Bundle and dependency injection

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `src/NowoMessengerMonitorKitBundle.php` | Bundle and dependency injection | FR-BUNDLE-001 |
| `src/DependencyInjection/Configuration.php` | Bundle and dependency injection | FR-CFG-001 |
| `src/DependencyInjection/NowoMessengerMonitorKitExtension.php` | Bundle and dependency injection | FR-CFG-002, FR-CFG-003, FR-CFG-004 |
| `src/DependencyInjection/Compiler/CsrfProtectionPass.php` | Bundle and dependency injection | FR-SEC-003 |
| `src/DependencyInjection/Compiler/UnauthenticatedAccessPass.php` | Bundle and dependency injection | FR-SEC-004 |
| `src/DependencyInjection/Compiler/HistoryStoragePass.php` | Bundle and dependency injection | FR-REC-001, FR-HIS-001 |
| `src/DependencyInjection/Compiler/MessengerIntegrationPass.php` | Bundle and dependency injection | FR-MSG-001, FR-MSG-002 |
| `src/DependencyInjection/Compiler/TwigPathsPass.php` | Bundle and dependency injection | FR-UI-006 |

## History storage and recording

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `src/Entity/ProcessedMessage.php` | History storage and recording | FR-HIS-001 |
| `src/Doctrine/TablePrefixListener.php` | History storage and recording | FR-HIS-002 |
| `src/Recording/ToggleableMonitorStorage.php` | History storage and recording | FR-REC-001, FR-REC-002, FR-PII-002 |
| `src/Recording/RecordingSwitch.php` | History storage and recording | FR-REC-003 |

## Runtime settings

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `src/Settings/MonitorSettings.php` | Runtime settings | FR-SET-001 |
| `src/Settings/MonitorSettingsStoreInterface.php` | Runtime settings | FR-SET-001 |
| `src/Settings/SettingsStore.php` | Runtime settings | FR-SET-002 |
| `src/Settings/CacheMonitorSettingsStore.php` | Runtime settings | FR-SET-002 |
| `src/Settings/ConfigMonitorSettingsStore.php` | Runtime settings | FR-SET-002 |
| `src/Settings/InstanceSettingsMonitorSettingsStore.php` | Runtime settings | FR-SET-003 |
| `src/Settings/MessengerMonitorSettingsSection.php` | Runtime settings | FR-SET-003 |

## Purge and retention

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `src/History/HistoryPurger.php` | Purge and retention | FR-PUR-001 |
| `src/History/AutoPurger.php` | Purge and retention | FR-PUR-002 |
| `src/History/PurgePeriod.php` | Purge and retention | FR-PUR-001, FR-PUR-002 |
| `src/History/PurgeResult.php` | Purge and retention | FR-PUR-001 |
| `src/Doctrine/KeysetDeleter.php` | Purge and retention | FR-PUR-001 |
| `src/Command/PurgeHistoryCommand.php` | Purge and retention | FR-PUR-003 |

## Retry of failed messages

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `src/Retry/FailedMessageRetrier.php` | Retry of failed messages | FR-RET-001, FR-RET-002, FR-RET-003 |
| `src/Retry/RetryOutcome.php` | Retry of failed messages | FR-RET-001 |
| `src/Retry/RetryStatus.php` | Retry of failed messages | FR-RET-001 |
| `src/Retry/BatchResult.php` | Retry of failed messages | FR-RET-002 |

## Messenger integrations

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `src/Retry/NoRetryOnDecodingFailureRetryStrategy.php` | Messenger integrations | FR-MSG-001 |
| `src/Messenger/StreamOptionsAmqpTransportFactory.php` | Messenger integrations | FR-MSG-002 |
| `src/Messenger/StreamOptionsDoctrineTransportFactory.php` | Messenger integrations | FR-MSG-002 |
| `src/Messenger/FailedTransportDsnEnvVarProcessor.php` | Messenger integrations | FR-MSG-003 |

## Queue depth

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `src/Queue/QueueDepth.php` | Queue depth | FR-QUE-001 |
| `src/Queue/QueueDepthReport.php` | Queue depth | FR-QUE-001 |
| `src/Queue/TransportDepth.php` | Queue depth | FR-QUE-001 |
| `src/Health/QueueDepthProbe.php` | Queue depth | FR-QUE-002 |

## Payloads and redaction

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `src/Payload/PayloadNormalizer.php` | Payloads and redaction | FR-PII-001 |
| `src/Payload/PayloadRedactorInterface.php` | Payloads and redaction | FR-PII-003 |
| `src/Payload/KeyPatternRedactor.php` | Payloads and redaction | FR-PII-001 |
| `src/Payload/Redactor.php` | Payloads and redaction | FR-PII-001, FR-PII-003 |
| `src/Payload/PayloadView.php` | Payloads and redaction | FR-PII-001 |

## Security

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `src/Security/MessengerMonitorAccessCheckerInterface.php` | Security | FR-SEC-001 |
| `src/Security/ConfigurableMessengerMonitorAccessChecker.php` | Security | FR-SEC-001 |
| `src/Security/AllowAllMessengerMonitorAccessChecker.php` | Security | FR-SEC-004 |
| `src/Security/AccessGuard.php` | Security | FR-SEC-001, FR-SEC-002 |
| `src/EventListener/MessengerMonitorAccessListener.php` | Security | FR-SEC-002 |

## Admin UI (HTTP)

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `src/Controller/AbstractMonitorController.php` | Admin UI (HTTP) | FR-UI-001, FR-SEC-003, FR-AUD-001 |
| `src/Controller/DashboardController.php` | Admin UI (HTTP) | FR-UI-002 |
| `src/Controller/HistoryController.php` | Admin UI (HTTP) | FR-UI-003 |
| `src/Controller/TransportsController.php` | Admin UI (HTTP) | FR-UI-004, FR-RET-001, FR-RET-002 |
| `src/Controller/SettingsController.php` | Admin UI (HTTP) | FR-UI-005, FR-PUR-001 |
| `src/Routing/MessengerMonitorRouteLoader.php` | Admin UI (HTTP) | FR-UI-001 |
| `src/Routing/MessengerMonitorRoutes.php` | Admin UI (HTTP) | FR-UI-001 |
| `src/Event/MessengerMonitorActionEvent.php` | Admin UI (HTTP) | FR-AUD-001 |

## Forms

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `src/Form/HistoryFilterType.php` | Forms | FR-UI-003 |
| `src/Form/MonitorSettingsType.php` | Forms | FR-UI-005, FR-SET-001 |
| `src/Form/PurgeType.php` | Forms | FR-UI-005, FR-PUR-001 |

## UI view models and Twig

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `src/Ui/MonitorPageFactory.php` | UI view models and Twig | FR-UI-002, FR-UI-003, FR-UI-004, FR-UI-005 |
| `src/Ui/MonitorPages.php` | UI view models and Twig | FR-UI-006 |
| `src/Ui/MonitorFormat.php` | UI view models and Twig | FR-UI-007 |
| `src/Twig/MessengerMonitorTwigExtension.php` | UI view models and Twig | FR-UI-007 |
| `src/Resources/views/monitor/dashboard.html.twig` | UI view models and Twig | FR-UI-002 |
| `src/Resources/views/monitor/history.html.twig` | UI view models and Twig | FR-UI-003 |
| `src/Resources/views/monitor/history_show.html.twig` | UI view models and Twig | FR-UI-003, FR-PII-001 |
| `src/Resources/views/monitor/transports.html.twig` | UI view models and Twig | FR-UI-004 |
| `src/Resources/views/monitor/transport.html.twig` | UI view models and Twig | FR-UI-004, FR-RET-001 |
| `src/Resources/views/monitor/message.html.twig` | UI view models and Twig | FR-UI-004, FR-PII-001 |
| `src/Resources/views/monitor/settings.html.twig` | UI view models and Twig | FR-UI-005 |
| `src/Resources/views/monitor/_cells.html.twig` | UI view models and Twig | FR-UI-006 |
| `src/Resources/views/monitor/_table.html.twig` | UI view models and Twig | FR-UI-006 |

## Translations

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `src/Resources/translations/NowoMessengerMonitorKitBundle.de.yaml` | Translations | FR-I18N-001 |
| `src/Resources/translations/NowoMessengerMonitorKitBundle.en.yaml` | Translations | FR-I18N-001 |
| `src/Resources/translations/NowoMessengerMonitorKitBundle.es.yaml` | Translations | FR-I18N-001 |
| `src/Resources/translations/NowoMessengerMonitorKitBundle.fr.yaml` | Translations | FR-I18N-001 |
| `src/Resources/translations/NowoMessengerMonitorKitBundle.it.yaml` | Translations | FR-I18N-001 |
| `src/Resources/translations/NowoMessengerMonitorKitBundle.nl.yaml` | Translations | FR-I18N-001 |
| `src/Resources/translations/NowoMessengerMonitorKitBundle.pt.yaml` | Translations | FR-I18N-001 |

## Coverage summary

| Category | Files |
| --- | --- |
| Bundle and dependency injection | 8 |
| History storage and recording | 4 |
| Runtime settings | 7 |
| Purge and retention | 6 |
| Retry of failed messages | 4 |
| Messenger integrations | 4 |
| Queue depth | 4 |
| Payloads and redaction | 5 |
| Security | 5 |
| Admin UI (HTTP) | 8 |
| Forms | 3 |
| UI view models and Twig | 13 |
| Translations | 7 |
| **Total production sources** | **78** |

Mapped 78 / 78 files under `src/` (100%). No generated or build output is shipped under `src/`.
