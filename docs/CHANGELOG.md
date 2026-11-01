# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses [Semantic Versioning](https://semver.org/).

## [Unreleased]

First release candidate (1.0.0), extracted and generalised from the Symfony Beacon messenger monitor (kit #15 and
KIT-55), built on top of `zenstruck/messenger-monitor-bundle` ^0.6.

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
