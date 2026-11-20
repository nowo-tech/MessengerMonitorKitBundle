# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses [Semantic Versioning](https://semver.org/).

## Table of contents

- [[Unreleased]](#unreleased)
- [[1.3.0] - 2026-11-19](#130---2026-11-19)
  - [Added](#added)
  - [Changed](#changed)
  - [Fixed](#fixed)
- [[1.2.0] - 2026-11-17](#120---2026-11-17)
  - [Changed](#changed-1)
  - [Demo](#demo)
  - [Documentation](#documentation)
  - [Tests](#tests)
- [[1.1.0] - 2026-11-11](#110---2026-11-11)
  - [Fixed](#fixed-1)
  - [Added](#added-1)
- [[1.0.1] - 2026-11-01](#101---2026-11-01)
  - [Changed](#changed-2)
  - [Fixed](#fixed-2)
  - [Documentation](#documentation-1)
- [[1.0.0] - 2026-11-01](#100---2026-11-01)
  - [Added](#added-2)
  - [Security](#security)

## [Unreleased]

### Changed

- Requires `nowo-tech/transaction-doctrine` ^1.8.1 (fixes GHSA-9wh3-m3m5-jffx and GHSA-65w2-9r4h-848g).

## [1.3.0] - 2026-11-19

### Added

- **Conflict answer (REQ-RUNTIME-003 rule 6).** A lock conflict (deadlock, lock wait timeout, serialization failure)
  on a bundle route answers **409** with `X-Conflict-Code`: `CONFLICT_001` and `Retry-After` when nothing was
  committed, `CONFLICT_002` without `Retry-After` when part of the request was (a retry whose message was already sent
  back, a batch or a purge after its first step, a commit earlier in the request, a Messenger handler that already
  ran). Before, it was a 500. JSON / XHR requests get `{"error", "code", "message"}`, pages the "try again" page.
  Options `conflict_response.status` (`409` | `503`) and `conflict_response.error_page`. Unique violations and lost
  connections are not mapped. See [CONCURRENCY.md](CONCURRENCY.md).
- `retry.lock_factory`: the `LockFactory` service of the per-message retry / removal lock (default `lock.factory`;
  a local flock store when it does not exist). Name a factory on a shared store when several servers run the admin UI.
- `docs/CONCURRENCY.md`: write paths, conflict codes, host options and residual risks.
- Tests: two-connection race tests on MySQL 8.4, MariaDB 11.4 and PostgreSQL 16 (retry versus remove, retry versus
  retry, lock waits answering `CONFLICT_001` / `CONFLICT_002`, purge partial commit); conflict mapping end to end.
- CI: the real-database job **Databases (MySQL 8.4, MariaDB 11.4, PostgreSQL 16, DBAL 3 / 4)** (MariaDB and DBAL 3
  added; it replaces "History writer on MySQL and PostgreSQL" as the required check), and the **Dependency floor** job
  (lowest `nowo-tech/transaction-*`, demo lock freshness, `.scripts/check-demo-lock.py`).

### Changed

- Requires `symfony/lock` (was suggested) and `nowo-tech/transaction-doctrine` ^1.6. The retry / removal lock is
  always on: two admins (or a double submit) can no longer send the same failed message back twice, nor retry a
  message another admin is removing.
- A lock conflict after a committed step of a retry, a batch or a purge is thrown as a committed
  `Nowo\TransactionDoctrine\WriteUnit\ConcurrencyConflict` (the database error is its previous exception). Code that
  calls `FailedMessageRetrier` or `HistoryPurger` directly and catches DBAL exceptions should catch
  `Nowo\TransactionContract\TransientStorageFailure` too.
- Demo: lock refreshed to install transaction-doctrine 1.6 (and symfony/security-http 8.1.11).

### Fixed

- History rows on PostgreSQL with DBAL 3: when the history id uses a pre-insert generator (a sequence, ORM 3's `AUTO`
  on DBAL 3), the DBAL writer now fetches the id from the generator; the insert failed with a NOT NULL violation.
- A deadlock or lock wait timeout on a bundle admin action answered a 500 (contention only, no data loss).

## [1.2.0] - 2026-11-17

### Changed

- `zenstruck/messenger-monitor-bundle` constraint widened to `^0.6 || ^0.7` (supersedes Dependabot #20): 0.6 hosts
  change nothing; on 0.7 the history `description` column becomes `TEXT` (long descriptions are stored instead of
  failing the insert on strict databases) and needs a migration, see [UPGRADING](UPGRADING.md#to-120). The lock,
  the demo and CI run 0.7; a CI job keeps testing 0.6.

### Demo

- Demo uses the UiKit base style (REQ-DEMO-014)
- Demo screenshots regenerated on the UiKit base style

### Documentation

- Table of contents in the changelog and the worker audit; Spec Kit inventory maps the three 1.1.0 files (82/82); README coverage counts refreshed (REQ sweep 2026-11-16)
- INSTALLATION: a table created under zenstruck 0.6 or earlier needs the `description` migration on 0.7; `--em=<name>` for a dedicated history manager; UPGRADING: widen a direct `^0.6` root constraint first; README UiKitBundle `^2.21` matches composer.json

### Tests

- Kept-alive history test: 1,000 messages recorded in one worker process (#15)

## [1.1.0] - 2026-11-11

Message history rows are written with DBAL and never flush the host's entity manager; a closed default manager no longer stops the consumer; new doctrine.entity_manager option. See [UPGRADING](https://github.com/nowo-tech/MessengerMonitorKitBundle/blob/v1.1.0/docs/UPGRADING.md#to-110).

### Fixed

- Rule 6: recording the history no longer flushes the host's entity manager. zenstruck's ORM storage did `persist()`
  + `flush()` on the manager that maps the history entity (the host's default one) after every handled and failed
  message. With DoctrineBundle's worker clear subscriber (priority 0, clears every manager before the history listener
  at -100) the handler's own pending changes were not affected; what was committed were changes persisted by host
  `WorkerMessageHandledEvent` / `WorkerMessageFailedEvent` listeners between priorities 0 and -100, or anything
  pending in hosts without that subscriber. History rows are now inserted and deleted with plain DBAL statements built
  from the entity metadata (`DbalHistoryWriter`), inside a SAVEPOINT when a transaction is open (the original error is
  rethrown if the insert fails). Affected: 1.0.0 – 1.0.1.
- A handler that closed the default entity manager no longer stops the consumer: the history listener threw
  `EntityManagerClosed` from `WorkerMessageFailedEvent` (the worker loop stopped and the history row was lost). The
  DBAL writer records the row on the connection.
- `doctrine.entity_manager` naming the host's default manager (e.g. `default`) only selects it: the bundle never
  clears or resets the host default, and a single-manager (Flex shorthand) host gets the mapping on it.

### Added

- `doctrine.entity_manager` (default `null`): map the bundle history entity on a dedicated entity manager. It is
  mapped there ONLY (`HistoryEntityManagerPass` removes it from every other manager, `auto_mapping` included; zenstruck's
  superclass mapping follows), reads and purges use it, the bundle clears it at the end of each request, message and
  command, the host's `default_entity_manager` stays the host's, and the build fails clearly when the manager is not
  declared.
- `MessengerMonitorActionEvent::$userIdentifier` (rule 4). `$user` stays for standard hosts and is deprecated (removed
  in 2.0).
- CI job running the history writer tests on MySQL 8.4 and PostgreSQL 17 (plus SQLite).

## [1.0.1] - 2026-11-01

Docs/demo follow-ups: SECURITY audit grades, demo install race, ui-kit ^2.21 See [UPGRADING](UPGRADING.md#to-101).

### Changed

- Requires `nowo-tech/ui-kit-bundle` ^2.21 (was ^2.20); the lock resolves UiKit 2.21.0.

### Fixed

- Demo: `make up` waits for the entrypoint's first `composer install` (the server answering) before it runs
  `composer install` again, instead of racing it on the shared `vendor/`.
- Release notes link UPGRADING with an absolute URL (`release.yml` rewrites relative `UPGRADING.md` links to the tagged
  file); relative links broke on the GitHub release page.

### Documentation

- SECURITY.md records the AI security audit grades (Appendix CB: first pass Pass (conditional) / Medium,
  re-verification Pass (good) / Low, N1 / N2 fixed before the 1.0.0 tag) instead of "pending".
- CHANGELOG / UPGRADING: removed the release-candidate leftovers; README coverage figures refreshed.

## [1.0.0] - 2026-11-01

First release: admin UI, retry, redaction, purge and queue depth on top of zenstruck/messenger-monitor-bundle. See [UPGRADING](https://github.com/nowo-tech/MessengerMonitorKitBundle/blob/main/docs/UPGRADING.md#to-100).

### Added

- **History decorator** `ToggleableMonitorStorage` on zenstruck's storage: runtime recording switch (no redeploy,
  `recording.check_interval`), optional high-volume message classes, handler results redacted (or dropped with
  `store_results: false`) before they are stored.
- **Runtime switches** `MonitorSettings` with three stores: shared PSR-6 cache (default), an InstanceSettings section
  `messenger_monitor` (optional, `nowo-tech/instance-settings-bundle`), read-only configuration.
- **Batched purge** `HistoryPurger` (keyset batches by primary key, keep-failed option, per-request cap) and
  **automatic retention** `AutoPurger` + `nowo:messenger-monitor:purge [--auto] [--force] [--older-than] [--include-failed]`.
- **Bundle history entity** (`messenger_processed_messages`, indexes on `finished_at` / `run_id`) mapped and set as
  zenstruck's entity unless the host has one; `doctrine.table_prefix`.
- **Failed message retry** `FailedMessageRetrier`: back to the original transport (or `retry.fallback_transport`)
  through the routable bus, never inline; failure / received / transport stamps removed, handled stamps and redelivery
  counter kept; per-message lock with `symfony/lock`; single and batch (`retry.batch_limit`) retry / removal;
  duplicate-risk classes.
- **Queue depth** `QueueDepth` service and optional HealthKit readiness probe `QueueDepthProbe`.
- **Payload view** with reflection normaliser (bounded depth / items / strings) and redaction chain (key list, e-mail
  and long-number masks, `PayloadRedactorInterface` hooks).
- `NoRetryOnDecodingFailureRetryStrategy` (opt-in per transport), broker-neutral transport factories for AMQP and
  Doctrine, `messenger_failed_dsn` env processor (opt-in).
- **UiKit admin UI** under `/admin/messenger`: dashboard, history (FormKit GET filters, pagination, server-side sorting
  by finish time), history row with attempts, transports, failed messages with retry / remove (FormKit CSRF forms,
  UiKit confirm), message detail, settings with manual purge; `web_ui.templates` overrides; 7 locales.
- **Security**: `security.access_roles` / `manage_roles` / `access_checker` / `allow_unauthenticated` (debug only,
  literal boolean), empty role lists refused, fail-closed CSRF pass, opt-in routes (REQ-SF-004 rule 7);
  `MessengerMonitorActionEvent` audit events.
- Symfony 8.1 FrankenPHP demo (MySQL, Doctrine transports, seeded failures) with Playwright e2e and screenshots;
  Flex recipe; docs, Spec Kit baseline, CI (PHP 8.2–8.5 × Symfony 7.4 / 8.1, 100% coverage gate).

### Security

Fixes from the independent REQ-SEC-004 audit of the 1.0.0 candidate (Appendix CB, 2026-11-01):

- **M1** Failure (exception) messages and message descriptions are shown to the manage area only; view-only users get
  metadata (type, transport, dates, status, tags, counts), as `docs/SECURITY.md` states. Descriptions are redacted too,
  and free-text redaction always masks credentials: URL userinfo, `Bearer` / `Basic` values, `password=` / `pwd:` /
  `token=` / `api_key=` / `key=` / `dsn=`-style pairs, `sk_live_` / `pk_test_` / `ghp_` / `xoxb-` / `AKIA…` keys
  and JWTs.
- **L1** Every failure-transport action has its own CSRF token id
  `nowo_mmk|<route>|<transport>|<message id or all>` and form name (`ActionForms`): a retry token for one message can
  no longer remove another message or remove all.
- **L2** Payload normaliser: global value budget (`payload.max_nodes`, default 2000), JSON byte cap
  (`payload.max_bytes`, default 256 KiB), `(ref: Class)` for an object already shown anywhere in the graph; on PHP
  8.4 raw property values (`ReflectionProperty::getRawValue()`), virtual (hook-only) properties skipped and
  uninitialised lazy objects shown as `(lazy: Class)` — no property hook or lazy initialiser runs.
- **L3** Wider default redaction keys (`pwd`, `pass`, `otp`, `dsn`, `auth`, `jwt`, `accesskey`, `username`,
  `firstname`, `lastname`, `dni`, `nif`, `nie`, `ip`, `postcode`, … and whole-name `name`, `code`, `key`, `pin`,
  `zip`, `pan`); `=name` syntax for whole-name keys; 3-letter keys now match inside names (`customerDni`); number mask
  tolerant of `.`, `/` and parentheses (ISO dates kept); Spanish DNI / NIE mask; Unicode (IDN) and dot-less e-mail
  domains.
- **L4** The Flex recipe no longer ships `config/packages/security_nowo_messenger_monitor_kit.yaml` (a second
  `security.access_control` list replaces the host's own); the `access_control` line is printed in `post-install.txt`
  and documented in the README and INSTALLATION.
- **I1** The retry race without `symfony/lock` is documented with its window (residual risks).
- **I2** `failure_transports: []` now uses the receivers Symfony tags `is_failure_transport`; the name heuristic is a
  last resort and only matches a `fail` / `failed` / `failure` word (not `mail_failover`), case-insensitively.
- **I3** "Retry all" skips `retry.duplicate_risk_classes` (they keep their stronger per-message confirmation) and its
  confirmation says so.
- **I4** `FailedTransportDsnEnvVarProcessor::messengerRedisDsn()` marks its DSN `#[SensitiveParameter]`.
- **I5** Documented: with an empty Redis variable the failure DSN is the queue DSN (set `queue_name` / `stream`).
- **I6** A failed retry / removal logs the exception class and its redacted message, not the exception object.
- **I7** Documented: the HealthKit probe publishes transport names and counts.
- **I8** Documented: zenstruck stores `failure_message` and `description` unredacted in the history table.
- **N1** (re-verification) CSRF token id parts are joined with `|`, a character outside the transport-name and id
  alphabet, so `(failed.high, 5)` and `(failed, high.5)` no longer share a token id.
- **N2** (re-verification) Free-text redaction false negatives closed: prefixed credential keys (`client_secret=`,
  `refresh_token=`, `db_password=`, `x_api_key=`) are masked; the ISO-date exemption keeps only real dates and tests
  the rest of the digit run (`2100-0418-45-0200051332`, `2026-11-01 4111 1111 1111 1111` → `2026-11-01 [redacted]`);
  URL userinfo is masked up to the last `@` before the host (an unencoded `/` or `@` in the password no longer leaks).
