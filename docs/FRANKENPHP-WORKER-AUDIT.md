# FrankenPHP worker mode audit (kernel not reset between requests)

| Field | Value |
|-------|-------|
| Package | `nowo-tech/messenger-monitor-kit-bundle` (`symfony-bundle`) |
| Audited revision | `1.1.0` (first audit `1.0.0`; 1.1 own-EntityManager clearing added below) |
| Audit date | 2026-11-01, refreshed 2026-11-12 |
| Method | Manual review of every file under `src/`; PHPStan `ruleset-classic` + `ruleset-worker-strict` + `ruleset-hardening`; Igor (`composer igor`, 0 KO / 0 WARN); unit tests of the TTL switch; demo run in worker mode |
| **Verdict** | ✅ **Viable** — every service is `readonly` except `RecordingSwitch` (two configuration booleans cached for `recording.check_interval` seconds, by design) and the route loader's load-once flag; no request or user data survives a request or a message |

## Table of contents

- [Execution model assumed](#execution-model-assumed)
- [Summary](#summary)
- [Services reviewed](#services-reviewed)
- [Findings](#findings)
- [Usage recommendations in worker mode](#usage-recommendations-in-worker-mode)
- [Re-audit triggers](#re-audit-triggers)

## Execution model assumed

FrankenPHP worker mode (and Messenger workers) boot the kernel once and serve many requests / messages with the same
container. Two scenarios are evaluated:

- **A — kernel not rebooted, `services_resetter` still runs.**
- **B — no reset at all:** any state kept in a service survives into the next request.

A bundle safe under **B** is safe under **A**, classic mode and PHP-FPM.

## Summary

| Area | Status | Notes |
|------|--------|-------|
| Mutable state in shared services | ✅ | `RecordingSwitch` (TTL copy of the switches, `@igor-ignore` with justification); route loader load-once flag (cache warm-up) |
| Static properties / `static` locals | ✅ | None |
| `ResetInterface` / `kernel.reset` | ✅ | Not needed for the cached switches (bounded by their TTL, not by requests). Since 1.1 the optional dedicated history manager (`doctrine.entity_manager`) is cleared by `OwnHistoryManagerSubscriber` at the end of every handled / failed message, not by `kernel.reset` |
| Request / user captured in services | ✅ | `AccessGuard` reads `RequestStack::getMainRequest()` and the token per call and trusts the token only with a firewall context |
| Superglobals, `putenv`, `ini_set`, `setlocale`, timezone | ✅ | None (the env processor only receives Symfony's `$getEnv` closure) |
| Doctrine | ✅ | Entity manager resolved per call from the registry; the purge uses DBAL statements, never the identity map |
| Resources held open | ✅ | None; locks are released in `finally` |
| Memory growth across requests | ✅ | None |
| Output, headers, `exit` | ✅ | Responses / console output only |
| PHPStan FrankenPHP rulesets / Igor | ✅ | 0 errors / 0 KO |

## Services reviewed

| Service | Mutable state | Scenario A | Scenario B |
|---------|---------------|------------|------------|
| `RecordingSwitch` | `MonitorSettings` copy + timestamp, reloaded after `check_interval` seconds | ✅ | ✅ (staleness ≤ interval) |
| `MessengerMonitorRouteLoader` | `loaded` flag (cache warm-up only) | ✅ | ✅ |
| `OwnHistoryManagerSubscriber` (1.1, only with a dedicated `doctrine.entity_manager`) | none; clears the bundle's own manager after each message / request and resets it when a failed write closed it | ✅ | ✅ |
| `ToggleableMonitorStorage`, `HistoryPurger`, `AutoPurger`, `KeysetDeleter`, `TablePrefixListener` | none (`readonly`) | ✅ | ✅ |
| `FailedMessageRetrier`, `QueueDepth`, `QueueDepthProbe`, transport factories, env processor, retry strategy | none (`readonly`) | ✅ | ✅ |
| Settings stores, `MessengerMonitorSettingsSection` | none (`readonly`; data in the cache pool / instance settings) | ✅ | ✅ |
| `Redactor`, `KeyPatternRedactor`, `PayloadNormalizer`, `PayloadView` | none (`readonly`; recursion guard is a local variable) | ✅ | ✅ |
| Controllers, `MonitorPageFactory`, `MonitorPages`, form types | none (forms built per request) | ✅ | ✅ |
| `AccessGuard`, `MessengerMonitorAccessListener`, access checkers | none (`readonly`) | ✅ | ✅ |
| `MessengerMonitorTwigExtension`, `PurgeHistoryCommand` | none | ✅ | ✅ |

## Findings

No worker-mode findings under scenario **B**. A switch saved in one process reaches every web / Messenger worker
within `recording.check_interval` seconds (the cache pool must be shared).

## Usage recommendations in worker mode

- Use a cache pool shared by all processes (Redis, Doctrine) for `settings.cache_pool` when web and workers run in
  different containers.
- Lower `recording.check_interval` (down to 0 = read on every message) when an instant switch matters more than one
  store read per message.

## Re-audit triggers

Re-run this audit when a change adds a property to a service, a cache, an event listener that keeps data, a
persistent connection, or any read of `$_SERVER` / `$_ENV` / static state at runtime.

## Kept-alive kernel, reset false (1.1.0)

**The bundle is safe with kernel reset false** (`FRANKENPHP_RESET_KERNEL=false`, `services_resetter` never called).

| Test | What it proves |
| --- | --- |
| `tests/Integration/Persistence/HistoryRecordingKernelTest.php` (`testKeptAliveWorkerClearsTheOwnManagerAndKeepsMemoryFlat`) | ONE booted kernel, 1,000 iterations of handled-message events alternating a monitored and an unmonitored message, no resetter: the bundle's own manager holds zero managed entities after each message, every row is written, memory growth < 4 MiB after warm-up |
| same file (`testFailedMessageDoesNotFlushTheHostsPendingChanges`) | recording a failed message never flushes the host's pending changes |

Rule 7: the bundle never clears the host's default EntityManager; history rows are written through `DbalHistoryWriter` (no
entity enters the host manager), and only the dedicated manager is recovered and cleared.
