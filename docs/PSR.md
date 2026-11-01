# PHP-FIG PSR evaluation (REQ-CS-007)

Package: `nowo-tech/messenger-monitor-kit-bundle` (`symfony-bundle`)

This document records which [PHP-FIG PSRs](https://www.php-fig.org/psr/) apply to this package.
Only contracts that add clear interoperability or maintainability value are **Adopted**.
Others are **N/A** (or already covered by Symfony) so the decision stays auditable.

## Table of contents

- [Baseline (always)](#baseline-always)
- [Interface / contract PSRs](#interface--contract-psrs)
- [Summary](#summary)

## Baseline (always)

| PSR | Decision | How |
| --- | -------- | --- |
| PSR-12 (coding style) | **Adopted** | `@PSR12` in `.php-cs-fixer.dist.php` (Nowo REQ-CS-001). |
| PSR-4 (autoloading) | **Adopted** | `composer.json` `autoload` / `autoload-dev` PSR-4 map for package sources and tests. |

## Interface / contract PSRs

| PSR | Decision | Notes |
| --- | -------- | ----- |
| PSR-3 Logger | **Adopted** | `HistoryPurger`, `AutoPurger`, `FailedMessageRetrier`, `QueueDepth` and the settings stores take an optional `Psr\Log\LoggerInterface` (transport names, ids, counts and message class names only — never payloads). |
| PSR-6 Cache | **Adopted** | The runtime switches (`CacheMonitorSettingsStore`) and the last automatic purge live in a `Psr\Cache\CacheItemPoolInterface` (`settings.cache_pool`, default `cache.app`) shared by web and worker processes. PSR-16 is not used: item metadata (hit / miss) matters to fall back to the configured defaults. |
| PSR-7 / PSR-17 HTTP messages | **N/A** | Symfony HttpFoundation is the correct surface for an admin UI. |
| PSR-18 HTTP client | **N/A** | No outbound HTTP client. |
| PSR-11 Container | **N/A** | No container locator in the public API; constructor injection and tagged iterators only. |
| PSR-14 Event dispatcher | **Adopted** | The admin controllers dispatch `MessengerMonitorActionEvent` through `Psr\EventDispatcher\EventDispatcherInterface` so hosts can audit retries, removals, purges and settings changes. |
| PSR-15 HTTP middleware | **N/A** | Symfony kernel listener for access; no middleware pipeline. |
| PSR-20 Clock | **Adopted** | Purge cutoffs, the automatic purge interval and the recording switch interval use an injected `Psr\Clock\ClockInterface` (framework `clock`); tests use `MockClock`. |

## Summary

- **Adopted beyond baseline:** PSR-3 (logger), PSR-6 (cache), PSR-14 (event dispatcher) and PSR-20 (clock), all type-hinted and wired.
- **Rule:** do not add `psr/*` Composer dependencies without matching type-hints and DI wiring.
- **Re-evaluate** when the package gains HTTP or other SPIs.

---

_REQ-CS-007 evaluation date: 2026-11-01._
