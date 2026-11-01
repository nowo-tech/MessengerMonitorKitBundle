# Configuration

Root key: `nowo_messenger_monitor_kit`. Every node is validated (REQ-SF-006): unknown keys, closed-set values and
out-of-range numbers fail the container build with the offending path.

## Table of contents

- [Full reference](#full-reference)
- [History storage](#history-storage)
- [Recording switches](#recording-switches)
  - [Settings stores](#settings-stores)
  - [InstanceSettings section](#instancesettings-section)
- [Retention and purge](#retention-and-purge)
- [Retry](#retry)
- [Queue depth and HealthKit](#queue-depth-and-healthkit)
- [Payloads and redaction](#payloads-and-redaction)
- [Retry strategy](#retry-strategy)
- [Transport factories](#transport-factories)
- [Admin UI](#admin-ui)
  - [Layout, CSS framework and row actions](#layout-css-framework-and-row-actions)
  - [FormKit profile](#formkit-profile)
- [Security](#security)
- [Profiles](#profiles)
- [Timeouts and blocking work](#timeouts-and-blocking-work)
- [Translations](#translations)

## Full reference

```yaml
nowo_messenger_monitor_kit:
    history:
        bundle_entity: true            # map the bundle entity unless zenstruck has an entity_class
    doctrine:
        table_prefix: ''               # app_ → app_messenger_processed_messages
    recording:
        enabled: true                  # default of the runtime "record history" switch
        optional_message_classes: []   # high-volume classes recorded only with "record optional"
        record_optional: false         # default of the runtime "record optional messages" switch
        check_interval: 30             # seconds a worker reuses the switches (0..3600)
        store_results: true            # false = drop handler results before saving
    settings:
        store: cache                   # cache | instance_settings | config
        cache_pool: cache.app          # shared by web and workers
    retention:
        auto_purge: false              # default of the runtime "purge automatically" switch
        older_than: 1-month            # 1-day | 1-week | 1-month | 3-months | 6-months | 1-year
        keep_failed: true
        min_interval: 3600             # seconds between two automatic purges (60..604800)
    purge:
        batch_size: 1000               # rows per DELETE (100..50000)
        max_batches_per_request: 20    # HTTP purge cap (the CLI has none)
    retry:
        batch_limit: 50                # retry all / remove all (1..500)
        fallback_transport: null       # target when the original transport is gone
        duplicate_risk_classes: []     # stronger confirmation before a retry
        lock_ttl: 30                   # seconds (needs symfony/lock)
    failure_transports: []             # empty = Symfony's failure transports (is_failure_transport)
    queue_depth:
        transports: []                 # empty = every countable transport
    health_probe:
        enabled: false                 # needs nowo-tech/health-kit-bundle
        name: messenger_queue_depth
        pending_warn_at: null
        pending_fail_at: null
        failed_warn_at: 1
        failed_fail_at: null
        critical: false
    payload:
        enabled: true
        max_depth: 4
        max_items: 50
        max_string_length: 500
        max_nodes: 2000
        max_bytes: 262144
    redaction:
        keys: [password, passwd, pwd, pass, secret, token, apikey, accesskey, encryptionkey, privatekey, '=key',
               authorization, auth, jwt, bearer, cookie, session, credential, signature, otp, totp, '=code',
               verificationcode, dsn, databaseurl, webhookurl, iban, cardnumber, '=pan', cvv, cvc, '=pin', pincode,
               ssn, dni, nif, '=nie', nienumber, taxid, '=vat', vatid, vatnumber, passport, email, phone, mobile,
               address, postcode, '=zip', birth, dob, ip, ipaddress, clientip, remoteip, username, '=login', '=name',
               firstname, lastname, fullname, surname, customername, contactname, holdername, recipient, to, cc, bcc,
               from, replyto]
        extra_keys: []
        mask: '[redacted]'
        mask_emails: true
        mask_long_numbers: true
    retry_strategy:
        no_retry_on_decoding_failure: []   # transport names
    transport_factories:
        amqp: { enabled: false, prefix: '' }
        doctrine: { enabled: false }
        failed_dsn_env: { enabled: false, queue_env: MESSENGER_TRANSPORT_DSN, redis_env: REDIS_URL }
    web_ui:
        enabled: true
        path_prefix: /admin/messenger
        layout_template: null          # null = nowo_ui_kit.layouts.admin
        templates: {}                  # dashboard | history | history_show | transports | transport | message | settings
        per_page: 25                   # 10 | 25 | 50 | 100
        transport_list_limit: 50       # queued messages listed per transport (1..500)
    security:
        access_roles: [ROLE_ADMIN]
        manage_roles: null             # null = access_roles
        access_checker: null           # service id of a MessengerMonitorAccessCheckerInterface
        allow_unauthenticated: false   # DEV / DEMO only, refused without kernel.debug
```

## History storage

| Key | Default | Meaning |
| --- | --- | --- |
| `history.bundle_entity` | `true` | Map `Nowo\MessengerMonitorKitBundle\Entity\ProcessedMessage` and set it as `zenstruck_messenger_monitor.storage.orm.entity_class` — only when the host did not configure its own entity. With `false` and no zenstruck entity, the admin UI refuses to compile (it needs the history) and the history services are removed when the UI is off. |
| `doctrine.table_prefix` | `''` | Prefix of the bundle table **and** index names (REQ-ORM-002), applied by a `loadClassMetadata` listener scoped to the bundle namespace: `app_` gives `app_messenger_processed_messages`, `app_idx_mpm_finished_at`. Host entities are never renamed. |

## Recording switches

The switches are **runtime** values: changing them on the Settings page (or through the store service) applies to every
worker within `recording.check_interval` seconds — no redeploy, no worker restart.

| Switch (stored key) | Config default | Effect |
| --- | --- | --- |
| `recording` | `recording.enabled` | Off: workers stop writing history rows. Reads, purges and retries keep working. |
| `record_optional` | `recording.record_optional` | Off: messages of `recording.optional_message_classes` (class, parent or interface) are not recorded. |
| `auto_purge` | `retention.auto_purge` | `nowo:messenger-monitor:purge --auto` purges only while on. |
| `retention` | `retention.older_than` | Age of the rows the automatic purge deletes. |
| `keep_failed` | `retention.keep_failed` | The automatic purge keeps failed rows. |

`recording.store_results: false` saves no handler results at all (return values, e-mail headers, process output); with
`true` they are redacted (see [Payloads and redaction](#payloads-and-redaction)) before they are stored.

### Settings stores

| `settings.store` | Where | Settings page |
| --- | --- | --- |
| `cache` (default) | One non-expiring item (`nowo_messenger_monitor_kit.settings`) of `settings.cache_pool`. The pool must be shared by web and worker processes: Redis / Doctrine, or a filesystem pool on a single host. A pool error never breaks message handling (defaults apply, warning logged). | Editable |
| `instance_settings` | The `messenger_monitor` section of [InstanceSettingsBundle](https://github.com/nowo-tech/InstanceSettingsBundle) (needs `nowo-tech/instance-settings-bundle`, a `suggest`): editable here **and** on the instance settings pages, exported / imported with the other settings. | Editable |
| `config` | The YAML defaults above, read-only. | Read-only note; POST refused (403) |

The last automatic purge instant always lives in `settings.cache_pool` (operational state, not a setting).

### InstanceSettings section

With `settings.store: instance_settings` the kit registers `MessengerMonitorSettingsSection` (tag
`nowo_instance_settings.section`): fields `recording`, `record_optional`, `auto_purge` (bool), `retention` (choice) and
`keep_failed` (bool), defaults from this configuration, labels in the `NowoMessengerMonitorKitBundle` domain.

## Retention and purge

The purge deletes in **keyset batches** by primary key (`SELECT id … WHERE id > ? … LIMIT n`, then `DELETE … WHERE id
IN (…)`), so a month of history never becomes one huge statement holding locks while workers insert. Filters:
`finished_at <= cutoff` and, when failed rows are kept, `failure_type IS NULL`.

| Key | Default | Meaning |
| --- | --- | --- |
| `purge.batch_size` | `1000` | Rows per batch. |
| `purge.max_batches_per_request` | `20` | Cap of one purge from the Settings page; the flash says when rows remain (purge again, or let the CLI / automatic purge finish). |
| `retention.min_interval` | `3600` | Seconds between two automatic purges; schedule the command more often (every 15 minutes). |

CLI: `nowo:messenger-monitor:purge --older-than=1-month [--include-failed]` (manual, no cap) and
`nowo:messenger-monitor:purge --auto [--force]` (runtime retention when on; `--force` ignores the interval).

## Retry

| Key | Default | Meaning |
| --- | --- | --- |
| `failure_transports` | `[]` | Transports the retry / remove actions accept. Empty = the receivers Symfony tags `is_failure_transport` (`framework.messenger.failure_transport` and per-transport `failure_transport`); only when there is none, names holding a `fail` / `failed` / `failure` word (`async_failed`, not `mail_failover`). Prefer listing them explicitly. Every other transport answers 404 to the actions. |
| `retry.fallback_transport` | `null` | Target when the original transport no longer exists or is not a queue (scheduler, sync). `null` keeps the message parked ("not retryable"). |
| `retry.batch_limit` | `50` | Messages handled by "retry all" / "remove all". |
| `retry.duplicate_risk_classes` | `[]` | Message classes whose handlers may have partially run (e-mails, notifications, payments): their retry confirm warns about repeated side effects. |
| `retry.lock_ttl` | `30.0` | With `symfony/lock` (service `lock.factory`), a retry / removal locks `transport + id` and looks the message up again inside the lock. |

## Queue depth and HealthKit

`QueueDepth::report()` counts every countable transport (sync and scheduler excluded), or only
`queue_depth.transports`; failure transports are summed apart (`pending()` / `failed()`). A broker error makes one
count `null` and logs a warning.

`health_probe.enabled: true` (needs `nowo-tech/health-kit-bundle`) registers `QueueDepthProbe` as a HealthKit
readiness probe named `health_probe.name`: degraded at `*_warn_at`, error at `*_fail_at` (`*_warn_at` must be ≤
`*_fail_at`), per-transport counts in the probe data (shown to HealthKit detail callers only). `critical: false`
(default) makes a failure degrade the report (HTTP 200) instead of failing it. HealthKit's own per-transport
`probes.messenger` keeps working; use one or the other.

## Payloads and redaction

| Key | Default | Meaning |
| --- | --- | --- |
| `payload.enabled` | `true` | `false` never shows a payload. Payloads are shown to the **manage** area only. |
| `payload.max_depth` / `max_items` / `max_string_length` | `4` / `50` / `500` | Bounds of the normalised payload (properties read by reflection: no getter, `__toString()` or property hook runs; PHP 8.4 virtual properties and uninitialised lazy objects are skipped). |
| `payload.max_nodes` | `2000` | Values normalised in the whole object graph; the rest shows `(truncated: payload too large)`. An object already shown anywhere in the graph shows `(ref: Class)`. |
| `payload.max_bytes` | `262144` | Bytes of JSON shown per payload / handler result; longer output is cut and ends with `… (truncated)`. |
| `redaction.keys` | see reference | Keys whose value is masked, case-insensitive with `_`, `-`, `.` and spaces ignored; keys of 2 characters and keys written `=name` match the whole name only (`to` does not mask `total`, `=name` does not mask `transportName`, `=pin` does not mask `shipping`), the others match inside a name (`email` masks `customerEmail`, `dni` masks `customerDni`). Replaces the default list. |
| `redaction.extra_keys` | `[]` | Added to the list. |
| `redaction.mask` | `[redacted]` | Replacement. |
| `redaction.mask_emails` | `true` | Masks e-mail addresses inside any string (payloads, handler results, failure messages, descriptions), Unicode (IDN) and dot-less hosts included. |
| `redaction.mask_long_numbers` | `true` | Masks Spanish DNI / NIE and digit runs holding 9+ digits separated by spaces, `-`, `.`, `/` or parentheses (phones, cards, accounts); ISO dates are kept. |

Credentials are always masked in free text, whatever the options: URL userinfo (`redis://user:pass@` →
`redis://[redacted]@`), `Bearer` / `Basic` values, `password=` / `pwd:` / `token=` / `api_key=` / `key=` / `dsn=`
pairs, `sk_live_` / `pk_test_` / `ghp_` / `xoxb-` / `AKIA…` keys and JWTs.

Hosts add redactors by implementing `Nowo\MessengerMonitorKitBundle\Payload\PayloadRedactorInterface` (autoconfigured,
tag `nowo_messenger_monitor_kit.payload_redactor`); they run after the built-in one.

## Retry strategy

`retry_strategy.no_retry_on_decoding_failure: [async, …]` decorates
`messenger.retry.multiplier_retry_strategy.<transport>` with `NoRetryOnDecodingFailureRetryStrategy`: a message that
cannot be decoded goes straight to the failure transport instead of being retried (on some Messenger releases the
retry looped forever; fixed in symfony/messenger 8.1.9 — the decorator stays harmless). An unknown transport, or one
with a custom `retry_strategy.service`, fails the build.

## Transport factories

Opt-in, for one `messenger.yaml` written with Redis-stream options (`stream`, `group`, `consumer`, …) that must also
work with other brokers chosen by the DSN:

| Key | Effect |
| --- | --- |
| `transport_factories.amqp.enabled` + `prefix` | `amqp://` transports get their own exchange and queue named `prefix + stream`, retry delays in `prefix + delays`; Redis-only options are dropped. Needs `symfony/amqp-messenger`. |
| `transport_factories.doctrine.enabled` | `doctrine://` transports use `stream` as `queue_name`; options outside the Doctrine set are dropped. Needs `symfony/doctrine-messenger`. |
| `transport_factories.failed_dsn_env.enabled` | Env processor `messenger_failed_dsn`: `%env(messenger_failed_dsn:default::MESSENGER_FAILED_TRANSPORT_DSN)%` returns the variable when set, else the queue DSN (`queue_env`) for Redis / Doctrine, else — for AMQP — the Redis URL (`redis_env`) turned into a Messenger DSN (`/N` → `?dbindex=N`), so the failure transport stays listable. |

## Admin UI

| Key | Default | Meaning |
| --- | --- | --- |
| `web_ui.enabled` | `true` | `false`: no routes, controllers, forms; services and CLI stay. Literal boolean only. |
| `web_ui.path_prefix` | `/admin/messenger` | Prefix of every route (the loader prepends it to the `#[Route]` paths). Protect it with `access_control`. |
| `web_ui.layout_template` | `null` | Layout the pages extend; `null` = `nowo_ui_kit.layouts.admin`. |
| `web_ui.templates.<page>` | — | Per-page override: `dashboard`, `history`, `history_show`, `transports`, `transport`, `message`, `settings` (see [TEMPLATES.md](TEMPLATES.md)). |
| `web_ui.per_page` | `25` | Default page size of the history (10, 25, 50, 100). |
| `web_ui.transport_list_limit` | `50` | Queued messages listed per transport page. |

The history list sorts by finish time on the server (UiKit `SortRequest`, query `?sort=finished&dir=asc|desc`, newest
first by default) — the only order zenstruck's storage supports.

### Layout, CSS framework and row actions

The kit adds no layout global and no class map: pages are UiKit scaffolds (REQ-UI-003). `css_framework`
(`bootstrap5`, `tailwind`, `foundation`, `custom`, …), `icon_set`, `row_actions_display` (`icon`, `text`, `icon_text`)
and the theme come from `nowo_ui_kit` once for every bundle:

```yaml
nowo_ui_kit:
    css_framework: bootstrap5      # or tailwind | foundation | custom
    icon_set: bootstrap-icons
    row_actions_display: icon      # or text | icon_text
    layouts:
        admin: 'layouts/admin.html.twig'
```

### FormKit profile

The POST forms (`MonitorSettingsType`, `PurgeType`) use the FormKit profile `messenger_monitor_kit` (bundle translation
domain, no auto help / placeholder, no framework classes), prepended unless the host defines it; the history filter
uses FormKit's built-in `filter` profile (GET, no CSRF); the retry / remove buttons are FormKit CSRF-only forms. Override
the profile in the host:

```yaml
nowo_form_kit:
    profiles:
        messenger_monitor_kit:
            translation_domain: NowoMessengerMonitorKitBundle
            defaults: { attr: { class: 'form-control' }, row_attr: {} }
```

## Security

| Key | Default | Meaning |
| --- | --- | --- |
| `security.access_roles` | `[ROLE_ADMIN]` | Roles opening the read-only pages (any). Must not be empty without `access_checker` / `allow_unauthenticated`. |
| `security.manage_roles` | `null` | Roles for retry / remove / purge / settings and payloads; `null` = `access_roles`; an explicit empty list is refused. |
| `security.access_checker` | `null` | Service id implementing `MessengerMonitorAccessCheckerInterface::canAccess(?object $user, string $area)` (`view` / `manage`). |
| `security.allow_unauthenticated` | `false` | DEV / DEMO only: open the UI without login; the container refuses it without `kernel.debug`. Literal boolean only. |

See [SECURITY.md](SECURITY.md).

## Profiles

The kit has a single configuration surface (one monitor per application): REQ-CFG-001 named profiles do not apply.

## Timeouts and blocking work

The bundle starts no subprocess and makes no outbound HTTP call. Its blocking work is bounded:

| Work | Bound |
| --- | --- |
| Purge from the Settings page | `purge.batch_size × purge.max_batches_per_request` rows per request. |
| Retry all / remove all | `retry.batch_limit` messages per request. |
| Transport pages | `web_ui.transport_list_limit` messages listed. |
| History | paginated (one count + one page query), pages above 10 000 refused. |
| Queue depth | one count call per transport; the broker client timeout applies (Redis / AMQP `read_timeout`, DB). |

Keep the PHP `max_execution_time` above the slowest purge request, and the proxy write timeout above PHP
([DEMO-FRANKENPHP.md](DEMO-FRANKENPHP.md)).

## Translations

UI copy lives in the `NowoMessengerMonitorKitBundle` domain in 7 locales (en, es, fr, it, pt, de, nl). Override any
key in the host: `translations/NowoMessengerMonitorKitBundle.<locale>.yaml` (the host file wins key by key).
