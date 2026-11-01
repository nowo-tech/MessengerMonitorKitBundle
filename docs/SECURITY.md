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
data written by zenstruck's storage before redaction applies (failure messages, see residual risks).

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
| Privilege escalation (viewer runs actions) | `manage_roles` (default = `access_roles`) checked in the controllers for every mutation, the settings page and payloads / results. |
| CSRF on mutations | Every POST is a Symfony Form with CSRF (FormKit CSRF-only forms bound to the action URL, `MonitorSettingsType`, `PurgeType`); invalid / missing tokens answer 403 or 422; `CsrfProtectionPass` refuses to compile the UI without form CSRF or the token manager (fail closed, P2). |
| Acting on arbitrary transports | Retry / remove only on configured failure transports; any other transport is a 404. Retries go to the message's original transport (or the configured fallback) — never inline, never to an arbitrary transport chosen by the request. |
| Duplicate side effects (double submit, two admins) | Per-message lock (`symfony/lock`) and re-lookup inside the lock; handled stamps kept so succeeded handlers do not run again; duplicate-risk classes get a stronger confirm. |
| XSS through payloads / failure messages | Payloads are normalised by reflection into scalars (no getter / `__toString()` executed), JSON-encoded and printed with Twig auto-escaping in `<pre>`; no `|raw`; contract test asserts no inline handlers. |
| Information disclosure (personal data, secrets) | Key-based masks (`password`, `token`, `email`, `phone`, `iban`, …), e-mail and long-number masks in free text, host redactors; payloads / results only for the manage area; `payload.enabled: false` disables them; results redacted (or dropped with `store_results: false`) before they are stored. |
| Deserialization | The kit never unserializes request data; envelopes are decoded by the host's Messenger serializer when transports are read. |
| SQL injection | Doctrine QueryBuilder (zenstruck) and DBAL bound parameters; the keyset purge's identifiers come from Doctrine metadata and are validated as plain identifiers. |
| DoS through large lists / purges | Pagination, page cap, transport list limit, batch limits, purge batches per request. |
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
- **Access control:** `manage_roles` for payloads and results; viewers see metadata only.
- **Redaction hooks:** `PayloadRedactorInterface` for domain-specific fields.
- **Right to erasure:** failed messages can be removed one by one or in batches; history rows are purged by age.

## CSRF

See the threat table: every session mutation validates a CSRF token before side effects, fail-closed (REQ-SEC-005).
No Bearer / API-token endpoint exists.

## Logging

Info logs for retries, removals and purges (transport, id, message short name, target, counts); warnings when the queue
depth, the settings store or the purge bookkeeping is unavailable. Never logged: payloads, handler results, failure
messages, DSNs, tokens.

## Resource limits

`web_ui.per_page` (10–100), history page ≤ 10 000, `web_ui.transport_list_limit` (≤ 500), `retry.batch_limit` (≤ 500),
`purge.batch_size × purge.max_batches_per_request` per HTTP purge, `payload.max_depth / max_items /
max_string_length`, `retry.lock_ttl`. No subprocess, no outbound HTTP (REQ-RUNTIME-001: see
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
| Safe recipe / config defaults | ✅ (`ROLE_ADMIN`, `allow_unauthenticated: false`, payloads for managers) |
| Input validation / output escaping | ✅ (closed-set query values, Twig auto-escape, contract test) |
| `composer audit` clean | ✅ (CI) |
| No secrets / personal data in logs | ✅ |
| Cryptography | — (none) |
| Permissions / exposure | ✅ (two layers, manage area) |
| Limits / DoS | ✅ |
| AI security audit passed (REQ-SEC-004) | ⏳ pending independent audit before tagging |

## AI security audit (REQ-SEC-004)

Not self-graded: the 1.0.0 candidate needs an independent AI security audit recorded in the monorepo security report
before the tag. Suggested scope: `src/` (controllers, `FailedMessageRetrier`, `ToggleableMonitorStorage`, redaction,
`KeysetDeleter`), `src/Resources/views/`, the Flex recipe, the demo `.env.example` and both SECURITY files.

## Residual risks and host guidance

- zenstruck stores the exception message of a failed message as is (`failure_message`); the UI shows it redacted, but
  the database row keeps the original. Rely on retention, and avoid personal data in exception messages.
- Redaction is pattern-based: names, addresses without a sensitive key name, or encoded data may pass. Add host
  redactors for domain fields, or disable payloads (`payload.enabled: false`).
- Without `symfony/lock` two concurrent retries of the same message can both dispatch it in a narrow window; install
  `symfony/lock` with a shared store when several admins use the UI.
- The cache settings store must use a pool shared by web and workers; a per-process pool makes the switch ineffective
  (not unsafe).

## Reporting a vulnerability

Email **hectorfranco@nowo.tech** (see [`.github/SECURITY.md`](../.github/SECURITY.md)). Do not open a public issue.
