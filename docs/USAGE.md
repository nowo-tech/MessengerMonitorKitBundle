# Usage

## Table of contents

- [What the kit adds to zenstruck](#what-the-kit-adds-to-zenstruck)
- [Admin pages](#admin-pages)
- [Retrying failed messages](#retrying-failed-messages)
- [Runtime switches](#runtime-switches)
- [Purge and retention](#purge-and-retention)
- [Queue depth](#queue-depth)
- [Redaction hooks](#redaction-hooks)
- [Audit events](#audit-events)
- [Layout and theme](#layout-and-theme)
- [Overriding templates](#overriding-templates)
  - [Override vs upgrade](#override-vs-upgrade)
  - [Procedure](#procedure)
  - [Overridable templates](#overridable-templates)
  - [Recovering upstream after a freeze](#recovering-upstream-after-a-freeze)
- [Forms](#forms)
- [Translations](#translations)
- [FrankenPHP worker mode](#frankenphp-worker-mode)

## What the kit adds to zenstruck

[zenstruck/messenger-monitor-bundle](https://github.com/zenstruck/messenger-monitor-bundle) records processed
messages, tracks workers and lists transports. The kit **builds on it** (no fork): it decorates zenstruck's history
storage, reads zenstruck's `Transports`, `Workers`, `Specification` and `Snapshot`, and adds:

| Kit | Uses from zenstruck |
| --- | --- |
| UiKit admin UI (dashboard, history, transports, failed messages, settings) | `Storage`, `Specification`, `Snapshot`, `Transports`, `Workers` |
| Runtime recording switch, optional classes, results minimisation | decorates `zenstruck_messenger_monitor.history.storage` |
| Batched keyset purge, automatic retention | the history entity's Doctrine metadata (replaces the single-statement `Storage::purge()` in the UI / CLI) |
| Retry to the original transport, removal, batches, locks | `TransportInfo`, `QueuedMessage`, `TagStamp` |
| Queue depth service (+ HealthKit probe) | `Transports::filter()` |
| Redacted payload view | `QueuedMessage::envelope()` |

zenstruck's own Bootstrap / CDN controller and templates are not used.

## Admin pages

Routes under `web_ui.path_prefix` (default `/admin/messenger`), names in `MessengerMonitorRoutes`:

| Route | Path | Page |
| --- | --- | --- |
| `nowo_messenger_monitor_kit_dashboard` | `` | Last 24 hours (processed, failed, wait / handling time), queues, workers, recording state, recent failures |
| `nowo_messenger_monitor_kit_history` | `/history` | History list: GET filters (period, status, transport, message type, page size), paginated, sortable by finish time |
| `nowo_messenger_monitor_kit_history_show` | `/history/{id}` | One row: summary, timing, failure (redacted), handler results (managers, redacted), attempts of the run |
| `nowo_messenger_monitor_kit_transports` | `/transports` | Transports and their depth |
| `nowo_messenger_monitor_kit_transport` | `/transports/{name}` | Queued messages of a listable transport; retry / remove on failure transports |
| `nowo_messenger_monitor_kit_message` | `/transports/{name}/messages/{id}` | One queued message: error (redacted), stamps, payload (managers, redacted) |
| `nowo_messenger_monitor_kit_retry` / `_remove` | `…/messages/{id}/retry`, `/remove` | POST + CSRF |
| `nowo_messenger_monitor_kit_retry_all` / `_remove_all` | `/transports/{name}/retry-all`, `/remove-all` | POST + CSRF, first `retry.batch_limit` |
| `nowo_messenger_monitor_kit_settings` | `/settings` | Runtime switches + manual purge |
| `nowo_messenger_monitor_kit_purge` | `/purge` | POST + CSRF |

```twig
<a href="{{ path('nowo_messenger_monitor_kit_dashboard') }}">Messenger</a>
```

## Retrying failed messages

A retry:

1. requires the manage area, a valid CSRF token for **this** action and message (token id
   `nowo_mmk.<route>.<transport>.<id>`, `all` for the batch actions) and a **failure** transport (`failure_transports`,
   else Symfony's failure transports, else names holding a `fail` / `failed` / `failure` word) — any other transport
   answers 404;
2. locks `transport + id` (with `symfony/lock`) and looks the message up again inside the lock — a double submit or a
   second admin gets "no longer on the failure transport" / "being handled";
3. sends the envelope back to the transport named by its `SentToFailureTransportStamp` (or `retry.fallback_transport`
   when that transport is gone; otherwise it stays parked), through the routable bus, **without** the failure /
   received / transport-name / transport-id stamps and tagged `retry` + `manual`;
4. keeps the handled stamps (handlers that already succeeded are skipped) and the redelivery counter (the transport
   retry policy is not restarted: a retried message that fails again goes back to the failure transport);
5. rejects it from the failure transport, logs the action and dispatches a `MessengerMonitorActionEvent`.

"Retry all" retries the first `retry.batch_limit` messages the same way but skips `retry.duplicate_risk_classes`: those
are retried one by one, after their stronger confirmation.

It never runs the message inline in the admin request. "Retry all" / "remove all" handle the first
`retry.batch_limit` messages; the flash reports done / skipped counts.

## Runtime switches

```php
use Nowo\MessengerMonitorKitBundle\Settings\MonitorSettings;
use Nowo\MessengerMonitorKitBundle\Settings\MonitorSettingsStoreInterface;

public function __construct(private MonitorSettingsStoreInterface $store) {}

$this->store->save(new MonitorSettings(recording: false)); // workers stop recording within check_interval
```

## Purge and retention

```bash
php bin/console nowo:messenger-monitor:purge --older-than=3-months           # successful rows only
php bin/console nowo:messenger-monitor:purge --older-than=all --include-failed
php bin/console nowo:messenger-monitor:purge --auto                          # schedule every 15 minutes
```

```php
use Nowo\MessengerMonitorKitBundle\History\HistoryPurger;
use Nowo\MessengerMonitorKitBundle\History\PurgePeriod;

$result = $purger->purge(PurgePeriod::OneMonth, keepFailed: true, maxBatches: 10);
$result->deleted;   // rows deleted
$result->complete;  // false when the batch cap stopped it
```

Retention is the RGPD storage-limitation control for message history: keep `auto_purge` on with the shortest period
your operations need.

## Queue depth

```php
use Nowo\MessengerMonitorKitBundle\Queue\QueueDepth;

$report = $queueDepth->report();
$report->pending();          // ?int, non-failure transports
$report->failed();           // ?int, failure transports
$report->toArray();          // ['async' => 3, 'failed' => 1, 'amqp' => null]
```

Feed host metrics with it, or enable the HealthKit probe (`health_probe.enabled`).

## Redaction hooks

```php
use Nowo\MessengerMonitorKitBundle\Payload\PayloadRedactorInterface;

final class OrderRedactor implements PayloadRedactorInterface
{
    public function redact(array $data, ?string $messageClass = null): array
    {
        if ($messageClass === PlaceOrder::class && isset($data['shippingNotes'])) {
            $data['shippingNotes'] = '[redacted]';
        }

        return $data;
    }
}
```

Autoconfigured (tag `nowo_messenger_monitor_kit.payload_redactor`); runs after the built-in key / pattern redactor on
payloads, handler results (before they are stored) and failure messages (`['text' => …]`).

## Audit events

```php
use Nowo\MessengerMonitorKitBundle\Event\MessengerMonitorActionEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener]
public function onMonitorAction(MessengerMonitorActionEvent $event): void
{
    // $event->action: retried | removed | purged | settings_updated
    // $event->context: transport, count, types, targets / older_than, deleted / settings — never a payload
    // $event->user: the authenticated user (null with allow_unauthenticated)
}
```

## Layout and theme

The pages are UiKit scaffolds (dashboard, list, show, settings) rendered inside `nowo_ui_kit.layouts.admin` — set it
once to the host admin layout (or a one-file bridge mapping `nowo_ui_content` into the host content block). Styles and
scripts stack with `{{ parent() }}`; colours come from `--nowo-ui-*` tokens and the `nowo_ui_kit.theme` class map.
Per-bundle exceptions go under `nowo_ui_kit.theme.bundles.NowoMessengerMonitorKitBundle`. See
[TEMPLATES.md](TEMPLATES.md).

```twig
{# templates/layouts/admin.html.twig — host bridge #}
{% extends 'base.html.twig' %}
{% use '@NowoUiKit/layout/_bridge.html.twig' %}
{% block body %}{{ block('nowo_ui_bridge_body') }}{% endblock %}
```

## Overriding templates

### Override vs upgrade

Prefer, in this order: `nowo_ui_kit` theme / layout (every bundle page follows), `web_ui.templates.<page>` with a thin
`{% extends '@!NowoMessengerMonitorKitBundle/monitor/<page>.html.twig' %}` overriding one block, and only then a
full-file override under `templates/bundles/NowoMessengerMonitorKitBundle/`. **An application file at the override path
always wins and will not pick up vendor changes for that path until you remove or merge it** (freeze rule).

### Procedure

1. Pick the `<subpath>` from the table below.
2. Create `templates/bundles/NowoMessengerMonitorKitBundle/<subpath>` in the application (same relative path).
3. Clear the cache (`php bin/console cache:clear`).

The bundle registers the `@NowoMessengerMonitorKitBundle` namespace with the application override directory first and
its own views last (`TwigPathsPass`), so the application file wins (REQ-TWIG-001 / REQ-TWIG-002).

### Overridable templates

| `<subpath>` | Purpose |
| --- | --- |
| `monitor/dashboard.html.twig` | Dashboard (UiKit dashboard scaffold; cards block) |
| `monitor/history.html.twig` | History list (UiKit list scaffold; status badge cells) |
| `monitor/history_show.html.twig` | One history row (UiKit show scaffold; failure, results, attempts) |
| `monitor/transports.html.twig` | Transports (UiKit list scaffold) |
| `monitor/transport.html.twig` | Queued messages of a transport (UiKit list scaffold, retry / remove actions) |
| `monitor/message.html.twig` | One queued message (UiKit show scaffold; error, payload) |
| `monitor/settings.html.twig` | Settings (UiKit settings scaffold; switches + purge forms) |
| `monitor/_cells.html.twig` | Cells of history / transport rows (status and kind badges) |
| `monitor/_table.html.twig` | Monitor tables through the UiKit `table` partial |

### Recovering upstream after a freeze

Delete (or rename aside) the application override, clear the Twig cache, then re-apply only surgical changes
(`web_ui.templates` + one block). Diff against `vendor/nowo-tech/messenger-monitor-kit-bundle/src/Resources/views/`
first.

## Forms

Every form is a Symfony Form Type rendered with `form_start` / child loop / `form_end` by the UiKit scaffolds
(REQ-TWIG-003 / REQ-TWIG-005): `HistoryFilterType` (FormKit `AbstractGetFilterType`, GET), `MonitorSettingsType` and
`PurgeType` (FormKit profile `messenger_monitor_kit`, POST + CSRF), and FormKit `CsrfOnlyType` forms for retry / remove
(`ActionForms`: one form name and CSRF token id per action and target).
Hosts overriding templates must not reintroduce raw `<form>` / `<input>` markup.

## Translations

Domain `NowoMessengerMonitorKitBundle`, locales en, es, fr, it, pt, de, nl. Override keys in
`translations/NowoMessengerMonitorKitBundle.<locale>.yaml`:

```yaml
# translations/NowoMessengerMonitorKitBundle.en.yaml
nav:
    label: Queues
```

## FrankenPHP worker mode

All services are stateless except `RecordingSwitch`, which keeps the two switches for `recording.check_interval`
seconds on purpose (one store read per interval instead of one per message; configuration only, never request data).
The access guard trusts a token only when a firewall matched the current main request. Verified by PHPStan FrankenPHP
rulesets and Igor (see [FRANKENPHP-WORKER-AUDIT.md](FRANKENPHP-WORKER-AUDIT.md)).
