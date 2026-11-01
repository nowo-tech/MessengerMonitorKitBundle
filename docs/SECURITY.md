# Security

Bundle-specific security guide (threat model, mitigations, release checklist). The short GitHub policy (supported
versions, private reporting) is [`.github/SECURITY.md`](../.github/SECURITY.md).

## Table of contents

- [Scope](#scope)
- [Attack surface](#attack-surface)
- [Threat model and mitigations](#threat-model-and-mitigations)
- [Admin UI exposure and allow_unauthenticated](#admin-ui-exposure-and-allow_unauthenticated)
- [Personal data (RGPD / GDPR)](#personal-data-rgpd--gdpr)
- [CSRF](#csrf)
- [Logging](#logging)
- [Resource limits](#resource-limits)
- [Secrets and cryptography](#secrets-and-cryptography)
- [Dependencies and updates](#dependencies-and-updates)
- [Release security checklist (12.4.1)](#release-security-checklist-1241)
- [AI security audit (REQ-SEC-004)](#ai-security-audit-req-sec-004)
- [Residual risks and host guidance](#residual-risks-and-host-guidance)
- [Reporting a vulnerability](#reporting-a-vulnerability)

## Scope

Covered: the admin UI and its actions (retry, remove, purge, settings), the history decorator, the purge commands,
the payload / results / failure views and their redaction, the transport factories and the failure DSN env processor.
Not covered: zenstruck's own controller and templates (not used), the host's Messenger transports and handlers, and
data written by zenstruck's storage before redaction applies (failure messages and descriptions, see residual risks).

## Attack surface

| Input | Where | Notes |
| --- | --- | --- |
| HTTP GET under `web_ui.path_prefix` | 5 pages | Query: `period`, `status`, `transport`, `type`, `per_page`, `page`, `sort`, `dir` — all validated against closed sets / the existing transports and types; route ids constrained by regex. |
| HTTP POST under the prefix | retry, remove, retry all, remove all, purge, settings | Session-authenticated mutations. |
| Message envelopes | payload view, history decorator | Data produced by the host's dispatchers (may contain personal data or secrets). |
| Configuration | `nowo_messenger_monitor_kit` | Validated tree; security switches must be literal booleans. |
| Environment | `messenger_failed_dsn` env processor | Reads two configured variable names. |
| CLI | `nowo:messenger-monitor:purge` | Operator only. |

## Threat model and mitigations

| Threat | Mitigation |
| --- | --- |
| Unauthenticated access to the UI | Default `access_roles: [ROLE_ADMIN]`; a kernel request listener (priority 7, after the firewall) denies every request under the prefix and every bundle controller (sub-requests included); the host must also add `access_control` (two layers, REQ-UI-002). Empty role lists are refused at compile time (fail closed, P1). Tokens are trusted only when a firewall matched the current main request (no worker leakage). |
| Privilege escalation (viewer runs actions) | `manage_roles` (default = `access_roles`) checked in the controllers for every mutation, the settings page and payloads / results / failure messages / descriptions. |
| CSRF on mutations | Every POST is a Symfony Form with CSRF (FormKit CSRF-only forms, `MonitorSettingsType`, `PurgeType`); each row / header action has its own token id `nowo_mmk.<route>.<transport>.<message id or "all">` (`ActionForms`), so a token captured for one action cannot be replayed on another (a retry token for message 1 cannot remove all); invalid / missing tokens answer 403 or 422; `CsrfProtectionPass` refuses to compile the UI without form CSRF or the token manager (fail closed, P2). |
| Acting on arbitrary transports | Retry / remove only on failure transports (`failure_transports`, else the receivers Symfony tags `is_failure_transport`, else a `fail` / `failed` / `failure` name word — set `failure_transports` explicitly when in doubt); any other transport is a 404. Retries go to the message's original transport (or the configured fallback) — never inline, never to an arbitrary transport chosen by the request. |
| Duplicate side effects (double submit, two admins) | Per-message lock (`symfony/lock`) and re-lookup inside the lock; handled stamps kept so succeeded handlers do not run again; duplicate-risk classes get a stronger confirm and are skipped by "retry all" (retried one by one). |
| XSS through payloads / failure messages | Payloads are normalised by reflection into scalars (no getter, `__toString()` or property hook executed; raw values on PHP 8.4, virtual properties and uninitialised lazy objects skipped), JSON-encoded and printed with Twig auto-escaping in `<pre>`; no `|raw`; contract test asserts no inline handlers. |
| Information disclosure (personal data, secrets) | Key-based masks (`password`, `pwd`, `token`, `key`, `dsn`, `email`, `phone`, `iban`, `dni`, `name`, `ip`, …), credential masks in free text (URL userinfo, `Bearer` values, `password=` / `token:` pairs, `sk_live_` / `ghp_` / `xoxb-` / `AKIA` keys, JWTs), e-mail, DNI / NIE and long-number masks, host redactors; payloads, results, failure messages and descriptions only for the manage area (viewers get metadata only); `payload.enabled: false` disables them; results redacted (or dropped with `store_results: false`) before they are stored. |
| Deserialization | The kit never unserializes request data; envelopes are decoded by the host's Messenger serializer when transports are read. |
| SQL injection | Doctrine QueryBuilder (zenstruck) and DBAL bound parameters; the keyset purge's identifiers come from Doctrine metadata and are validated as plain identifiers. |
| DoS through large lists / purges / payloads | Pagination, page cap, transport list limit, batch limits, purge batches per request; payload graph budget (`payload.max_nodes`, objects shown once, `(ref: Class)` afterwards) and JSON byte cap (`payload.max_bytes`). |
| Demo config in production | `allow_unauthenticated: true` is refused without `kernel.debug`; env placeholders are refused for security switches (they would read as true). |

## Admin UI exposure and allow_unauthenticated

`security.allow_unauthenticated: true` opens retry / remove / purge and payloads to anybody: it exists for demos only,
needs `kernel.debug`, and should be combined with a loopback-bound port (as the demo does). Production hosts keep it
`false`, set `access_roles` / `manage_roles` (or `access_checker`), and add `access_control` for the prefix.

## Personal data (RGPD / GDPR)

Message history and parked messages are processing records that may contain personal data.

- **Minimisation:** `recording.enabled` (runtime off switch), `optional_message_classes` for high-volume messages,
  `store_results: false`, and write-time redaction of handler results.
- **Storage limitation:** automatic purge with a retention period (`retention.*`, scheduled `--auto`), manual purge,
  keep-failed option.
- **Access control:** `manage_roles` for payloads, handler results, failure (exception) messages and message
  descriptions; viewers see metadata only (type, transport, dates, status, tags, counts). Managers see those texts
  redacted (keys, credentials, e-mails, national ids, long numbers).
- **Redaction hooks:** `PayloadRedactorInterface` for domain-specific fields.
- **Right to erasure:** failed messages can be removed one by one or in batches; history rows are purged by age.

## CSRF

See the threat table: every session mutation validates a CSRF token before side effects, fail-closed (REQ-SEC-005).
No Bearer / API-token endpoint exists.

## Logging

Info logs for retries, removals and purges (transport, id, message short name, target, counts); warnings when the queue
depth, the settings store or the purge bookkeeping is unavailable. A failed retry / removal logs the exception class and
its **redacted** message (`exception_class`, `exception_message`), never the exception object or its trace. Never
logged: payloads, handler results, failure messages of parked messages, DSNs, tokens.

## Resource limits

`web_ui.per_page` (10–100), history page ≤ 10 000, `web_ui.transport_list_limit` (≤ 500), `retry.batch_limit` (≤ 500),
`purge.batch_size × purge.max_batches_per_request` per HTTP purge, `payload.max_depth / max_items /
max_string_length / max_nodes / max_bytes`, `retry.lock_ttl`. No subprocess, no outbound HTTP (REQ-RUNTIME-001: see
[CONFIGURATION.md](CONFIGURATION.md#timeouts-and-blocking-work)).

## Secrets and cryptography

The kit stores no secret and uses no cryptography. The transport factories and the env processor mark DSN parameters
`#[SensitiveParameter]` so they never appear in stack traces. Fixtures use obvious placeholders (`REDACTED-TEST-TOKEN`).

## Dependencies and updates

`composer audit` runs in CI on every matrix cell; Dependabot groups Composer and GitHub Actions updates. Security
fixes are released as patch versions.

## Release security checklist (12.4.1)

| Item | Status for 1.0.0 |
| --- | --- |
| `docs/SECURITY.md` and `.github/SECURITY.md` present | ✅ |
| `.env` files ignored; no secrets in the repository | ✅ (demo uses placeholders; root and demo `.gitignore`) |
| Safe recipe / config defaults | ✅ (`ROLE_ADMIN`, `allow_unauthenticated: false`, payloads and failure texts for managers; the recipe ships no `security.access_control` file) |
| Input validation / output escaping | ✅ (closed-set query values, Twig auto-escape, contract test) |
| `composer audit` clean | ✅ (CI) |
| No secrets / personal data in logs | ✅ |
| Cryptography | — (none) |
| Permissions / exposure | ✅ (two layers, manage area) |
| Limits / DoS | ✅ |
| AI security audit passed (REQ-SEC-004) | ⏳ pending independent audit before tagging |

## AI security audit (REQ-SEC-004)

Not self-graded: the 1.0.0 candidate needs an independent AI security audit recorded in the monorepo security report
before the tag. The first audit (Appendix CB, 2026-11-01) found M1, L1–L4 and info items I1–I8; their fixes are listed
under `Security` in the [CHANGELOG](CHANGELOG.md) and await re-verification. Suggested scope: `src/` (controllers, `FailedMessageRetrier`, `ToggleableMonitorStorage`, redaction,
`KeysetDeleter`), `src/Resources/views/`, the Flex recipe, the demo `.env.example` and both SECURITY files.

## Residual risks and host guidance

- zenstruck stores the exception message (`failure_message`) and the message description (`description`, from a
  `DescriptionStamp` or `__toString()`) of every processed message as is in `messenger_processed_messages`; the UI shows
  them redacted and only to the manage area, but the database row keeps the original. Restrict database access, rely
  on retention, and keep personal data and secrets out of exception messages and `__toString()`.
- Free-text redaction cannot recognise names or addresses without a key, full-width / non-ASCII digits, or numbers
  split by other characters; key redaction matches normalised names (case, `_`, `-`, `.` ignored; short keys such as
  `name`, `code`, `key`, `ip`, `pin` only as the whole name).
- Redaction is pattern-based: names, addresses without a sensitive key name, or encoded data may pass. Add host
  redactors for domain fields, or disable payloads (`payload.enabled: false`).
- Without `symfony/lock` two concurrent retries of the same message can both dispatch it in a narrow window (between
  the dispatch and the reject; reproduced in the audit with a re-entrant bus); install `symfony/lock` with a shared
  store when several admins use the UI.
- The failure transport name heuristic (`fail` / `failed` / `failure` as a name word) applies only when
  `failure_transports` is empty **and** Symfony tags no receiver `is_failure_transport`; set `failure_transports`
  explicitly when a queue name could match.
- The `messenger_failed_dsn` env processor with an empty Redis variable and a non-AMQP queue returns the queue DSN
  itself: the failure transport then shares the live queue unless you set its `queue_name` (Doctrine) or `stream`
  (Redis) option.
- The HealthKit probe (`QueueDepthProbe`) publishes transport names and pending / failed counts; their exposure follows
  the HealthKit detail policy of the host (restrict the detailed health endpoint).
- The cache settings store must use a pool shared by web and workers; a per-process pool makes the switch ineffective
  (not unsafe).

## Reporting a vulnerability

Email **hectorfranco@nowo.tech** (see [`.github/SECURITY.md`](../.github/SECURITY.md)). Do not open a public issue.
