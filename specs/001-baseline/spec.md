# Feature Specification: MessengerMonitorKitBundle baseline

**Feature Branch**: `001-baseline`
**Created**: 2026-11-01
**Status**: Baseline (1.0.0 candidate)
**Input**: Extraction of the Symfony Beacon messenger monitor (kit #15 + KIT-55), generalised on top of
`zenstruck/messenger-monitor-bundle` ^0.6.

Related: [`docs/SPEC-DRIVEN-DEVELOPMENT.md`](../../docs/SPEC-DRIVEN-DEVELOPMENT.md),
[`docs/USAGE.md`](../../docs/USAGE.md), [`docs/CONFIGURATION.md`](../../docs/CONFIGURATION.md),
[`code-inventory.md`](code-inventory.md).

## Summary

The bundle (config root `nowo_messenger_monitor_kit`) guarantees: a decorator of zenstruck's history storage with a
runtime recording switch; runtime switches stored in a shared cache pool, an InstanceSettings section or the
configuration; keyset-batched purge and automatic retention; retry / removal of failed messages back to their original
transport with CSRF, access areas, locks and batch limits; a queue depth service and HealthKit probe; redacted payload,
result and failure views; a decoding-failure retry strategy and broker-neutral transport factories; and a UiKit admin
UI (dashboard, history, transports, failed messages, settings) behind private access.

## User Scenarios & Testing

### US-1 — Inspect the workers' activity (P1)

An operator opens the dashboard and the history.

1. **Given** messages handled and failed in the last 24 hours, **When** the operator opens the dashboard, **Then** the
   processed / failed counts, average wait and handling times, queue depth, workers, recording state and the latest
   failures are shown.
2. **Given** a history, **When** the operator filters by period, status, transport or message type, **Then** only the
   matching rows are listed, paginated, sortable by finish time, and the query string keeps the filters.

### US-2 — Recover failed messages (P1)

1. **Given** a message parked on the failure transport after failing on `async`, **When** a manager confirms "Retry",
   **Then** the message is sent back to `async` (not run inline), removed from the failure transport, an audit event
   is dispatched and a flash names the target.
2. **Given** a message whose original transport no longer exists and no fallback, **When** a retry is attempted,
   **Then** it stays parked and the flash says it is not retryable.
3. **Given** two concurrent retries of one message, **When** both submit, **Then** only one dispatches it.
4. **Given** a forged or missing CSRF token, a viewer, or a non-failure transport, **When** an action is posted,
   **Then** it is refused (403 / 404) without side effects.

### US-3 — Control volume and retention at runtime (P2)

1. **Given** recording switched off on the Settings page, **When** workers handle messages, **Then** no history row is
   written within `check_interval` seconds, without redeploy or restart.
2. **Given** automatic purge on with a retention, **When** `nowo:messenger-monitor:purge --auto` runs after the
   interval, **Then** older rows are deleted in batches (failed rows kept when configured).
3. **Given** a manual purge of many rows from the UI, **When** the per-request cap is reached, **Then** the flash says
   rows remain.

### US-4 — Protect personal data (P1)

1. **Given** a payload with e-mail, phone or token fields, **When** a manager opens the message, **Then** those values
   are masked and the JSON is escaped; viewers see no payload.
2. **Given** handler results with e-mail headers, **When** the row is saved, **Then** the stored results are redacted.

### Edge cases

- A broker that cannot count answers `null` (dash) instead of failing the page.
- An env placeholder used for a security switch fails the build (it would read as true).
- The UI without a history storage refuses to compile; without the UI the history services are removed.

## Requirements

### Bundle and configuration

- **FR-BUNDLE-001**: The bundle registers compiler passes for Twig paths, CSRF, demo-only access, history storage and
  Messenger integrations; its extension alias is `nowo_messenger_monitor_kit`.
- **FR-CFG-001**: The configuration tree validates every key with closed sets and ranges; empty role lists without a
  custom checker are refused.
- **FR-CFG-002**: `prepend()` maps the bundle entity and sets zenstruck's `entity_class` unless the host has one, and
  prepends the FormKit profile `messenger_monitor_kit` unless defined.
- **FR-CFG-003**: Optional integrations (InstanceSettings store, HealthKit probe) fail with a clear message when their
  package is missing.
- **FR-CFG-004**: Security switches (`web_ui.enabled`, `security.allow_unauthenticated`, `payload.enabled`) must be
  literal booleans.

### History and recording

- **FR-HIS-001**: The bundle entity `ProcessedMessage` (table `messenger_processed_messages`, indexes `finished_at`,
  `run_id`) extends zenstruck's model; any host entity configured in zenstruck is used instead.
- **FR-HIS-002**: `doctrine.table_prefix` prefixes the bundle table and index names only.
- **FR-REC-001**: `ToggleableMonitorStorage` decorates zenstruck's storage; writes are skipped while recording is off;
  reads, purges and deletes always delegate.
- **FR-REC-002**: Messages of `recording.optional_message_classes` are recorded only while optional recording is on.
- **FR-REC-003**: Workers read the switches at most once per `recording.check_interval` seconds.

### Runtime settings

- **FR-SET-001**: `MonitorSettings` holds recording, optional recording, automatic purge, retention and keep-failed;
  invalid stored values fall back to the configured defaults.
- **FR-SET-002**: Stores: shared PSR-6 cache (errors fall back to defaults), read-only configuration.
- **FR-SET-003**: With `instance_settings`, a `messenger_monitor` section stores the switches through
  `InstanceSettingsInterface`.

### Purge and retention

- **FR-PUR-001**: The purge deletes `finished_at <= cutoff` (and only successful rows when failed are kept) in keyset
  batches by primary key, with an optional batch cap, using the entity's Doctrine metadata.
- **FR-PUR-002**: The automatic purge runs only when on and after `retention.min_interval`; the last run lives in the
  cache pool; bookkeeping errors do not fail the purge.
- **FR-PUR-003**: `nowo:messenger-monitor:purge` offers manual (`--older-than`, `--include-failed`) and automatic
  (`--auto`, `--force`) modes.

### Retry

- **FR-RET-001**: A retry sends the envelope to the original transport (or the fallback) through the routable bus
  without failure / received / transport-name / transport-id stamps, keeps handled and redelivery stamps, tags it
  `retry` + `manual`, then rejects it from the failure transport.
- **FR-RET-002**: Retry all / remove all handle at most `retry.batch_limit` messages and report done / skipped counts.
- **FR-RET-003**: With a lock factory, each action locks `transport + id` and looks the message up again inside the
  lock; duplicate-risk classes are flagged.

### Messenger integrations

- **FR-MSG-001**: `NoRetryOnDecodingFailureRetryStrategy` decorates the configured transports' multiplier strategies;
  unknown transports fail the build.
- **FR-MSG-002**: Stream-options factories rewrite Redis-stream options for AMQP (prefixed exchange / queue / delays)
  and Doctrine (queue name); a missing wrapped factory fails the build.
- **FR-MSG-003**: The `messenger_failed_dsn` env processor derives a listable failure DSN.

### Queue depth

- **FR-QUE-001**: `QueueDepth` counts countable transports (sync and scheduler excluded), failure transports apart;
  broker errors give `null` and a warning.
- **FR-QUE-002**: `QueueDepthProbe` maps pending / failed totals to HealthKit ok / degraded / error.

### Personal data

- **FR-PII-001**: Payloads are normalised by reflection (bounded) and redacted (keys, e-mails, 9+ digit runs); failure
  messages are redacted; texts are escaped in the UI.
- **FR-PII-002**: Handler results are redacted (or dropped) before they are stored.
- **FR-PII-003**: Tagged `PayloadRedactorInterface` services run after the built-in redactor.

### Security

- **FR-SEC-001**: View and manage areas: `access_roles` / `manage_roles` or a custom checker; anonymous and empty lists
  are denied.
- **FR-SEC-002**: A request listener guards the prefix and every bundle controller; tokens are trusted only with a
  firewall context of the main request.
- **FR-SEC-003**: Every mutation validates a CSRF form (fail closed); the UI refuses to compile without form CSRF.
- **FR-SEC-004**: `allow_unauthenticated` is accepted only with `kernel.debug`.

### Admin UI

- **FR-UI-001**: Routes are declared with `#[Route]` and mounted only by the `nowo_messenger_monitor_kit` loader under
  `web_ui.path_prefix`; controllers are not autoconfigured.
- **FR-UI-002**: Dashboard (UiKit dashboard scaffold).
- **FR-UI-003**: History list (FormKit GET filters, pagination, `SortRequest`) and history row (attempts, redacted
  failure, results for managers).
- **FR-UI-004**: Transports, queued messages with retry / remove actions (CSRF forms, confirm), message detail.
- **FR-UI-005**: Settings page (switches form, read-only note with the config store) and manual purge form.
- **FR-UI-006**: Templates under `@NowoMessengerMonitorKitBundle` with override precedence, layout / per-page overrides.
- **FR-UI-007**: Duration / date / rate formatting, Twig filter `nowo_messenger_monitor_duration`.
- **FR-AUD-001**: `MessengerMonitorActionEvent` is dispatched after each mutation (no payload in the context).
- **FR-I18N-001**: UI copy in the `NowoMessengerMonitorKitBundle` domain, 7 locales with key parity.

### Key entities

- `ProcessedMessage` (history row), `MonitorSettings` (switches), `PurgePeriod`, `QueueDepthReport`, `RetryOutcome`,
  `BatchResult`, `MessengerMonitorActionEvent`.

## Success Criteria

- **SC-001**: PHPUnit statements and elements coverage of `src/` is 100% (CI gate).
- **SC-002**: PHPStan level 8 with FrankenPHP rulesets, Rector dry-run, PHP-CS-Fixer and Igor are clean.
- **SC-003**: The UiKit page contract test passes for every page and fixture; no fixture personal data reaches the HTML.
- **SC-004**: The demo e2e suite passes (dashboard, history filter, redacted payload, retry, settings).
- **SC-005**: The code inventory maps **78/78** files under `src/`.

## Non-goals

Forking zenstruck; zenstruck's own UI; schedules / "run now"; per-type retention; editing payloads; retrying
non-failure transports; inline execution of retried messages.

## Assumptions

A listable failure transport (Doctrine / Redis); a cache pool shared by web and workers for the cache store; Doctrine
ORM for the history.

## Validation

```bash
make test-coverage-100 phpstan cs-check rector-dry igor validate-translations
make release-check
make -C demo/symfony8 test-e2e
```
