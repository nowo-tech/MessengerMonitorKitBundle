# Architecture

## Table of contents

- [Overview](#overview)
- [Request flow](#request-flow)
- [Worker flow](#worker-flow)
- [Components](#components)
- [Container wiring](#container-wiring)
- [Extension points](#extension-points)

## Overview

```
Messenger worker ──► zenstruck listeners ──► ToggleableMonitorStorage (switch, optional classes, redacted results)
                                                   │ decorates
                                                   ▼
                                    zenstruck ORMStorage ──► messenger_processed_messages (bundle or host entity)

HTTP (route loader, prefix) ──► MessengerMonitorAccessListener ──► AccessGuard ──► access checker (view / manage)
   │
   ├─ DashboardController ──► Snapshot (zenstruck), QueueDepth, Workers, settings store
   ├─ HistoryController   ──► Specification / Storage (paginated, filters, SortRequest), Redactor
   ├─ TransportsController ─► Transports, FailedMessageRetrier (lock, routable bus), PayloadView
   └─ SettingsController  ──► settings store, HistoryPurger (keyset batches)
        └─ MonitorPageFactory ──► UiKit view models ──► UiKit scaffolds (@NowoMessengerMonitorKitBundle/monitor/*)

CLI nowo:messenger-monitor:purge ──► HistoryPurger / AutoPurger (runtime retention, cache bookkeeping)
```

## Request flow

1. The host firewall, then `MessengerMonitorAccessListener` (kernel.request, priority 7) asks `AccessGuard` for the
   view area on every request under the prefix and every bundle controller.
2. Mutating controllers require the manage area and validate a CSRF form (fail closed), then act through a service.
3. `MonitorPageFactory` turns zenstruck models into UiKit view models (plain values, redacted texts, FormViews).
4. Mutations dispatch `MessengerMonitorActionEvent` and add a translated flash.

## Worker flow

zenstruck's `HandleMonitorStampListener` calls `Storage::save()` after each handled / failed message. The decorator
reads the switches through `RecordingSwitch` (store read at most once per `check_interval`), skips the write when
recording is off or the message is optional, and redacts (or drops) the handler results before delegating.

## Components

| Component | Role |
| --- | --- |
| `Recording\ToggleableMonitorStorage`, `RecordingSwitch` | Runtime recording switch on zenstruck's storage |
| `Settings\*` | `MonitorSettings` value object, stores (cache, InstanceSettings, config), InstanceSettings section |
| `History\HistoryPurger`, `AutoPurger`, `PurgePeriod`, `PurgeResult`; `Doctrine\KeysetDeleter` | Batched purge and retention |
| `Doctrine\TablePrefixListener`, `Entity\ProcessedMessage` | Bundle history entity and table prefix |
| `Retry\FailedMessageRetrier`, `RetryOutcome`, `RetryStatus`, `BatchResult` | Retry / removal of failed messages |
| `Retry\NoRetryOnDecodingFailureRetryStrategy` | Optional retry strategy decorator |
| `Queue\QueueDepth`, `QueueDepthReport`, `TransportDepth`; `Health\QueueDepthProbe` | Queue depth (+ HealthKit) |
| `Messenger\StreamOptions*TransportFactory`, `FailedTransportDsnEnvVarProcessor` | Broker-neutral transport options |
| `Payload\*` | Normaliser, redaction chain, payload view |
| `Security\*`, `EventListener\MessengerMonitorAccessListener` | Private access (REQ-UI-002) |
| `Controller\*`, `Form\*`, `Routing\*`, `Ui\*`, `Twig\*` | Admin UI |
| `Command\PurgeHistoryCommand` | CLI |
| `DependencyInjection\*` | Configuration, extension, compiler passes (Twig paths, CSRF, demo-only access, storage decoration, Messenger integrations) |

## Container wiring

- The extension registers services explicitly (no resource scan). Controllers are not autoconfigured (REQ-SF-004
  rule 7) and are mounted only by the `nowo_messenger_monitor_kit` route loader.
- `prepend()` maps the bundle entity and sets it as zenstruck's `entity_class` (unless the host has one) and adds the
  FormKit profile.
- `HistoryStoragePass` decorates zenstruck's storage and hands the entity class to the purger;
  `MessengerIntegrationPass` decorates retry strategies and checks wrapped transport factories;
  `CsrfProtectionPass` and `UnauthenticatedAccessPass` fail closed.

## Extension points

`MessengerMonitorAccessCheckerInterface` (`security.access_checker`), `PayloadRedactorInterface` (tagged),
`MonitorSettingsStoreInterface` (service alias), `MessengerMonitorActionEvent` (audit), `web_ui.templates.<page>` and
Twig overrides, `nowo_ui_kit` theme, FormKit profile `messenger_monitor_kit`.
