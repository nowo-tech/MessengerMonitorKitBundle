# MessengerMonitorKitBundle Constitution

## Core Principles

### I. Documented integrator contract
Product behavior lives in `specs/001-baseline/spec.md`, `docs/SPEC-DRIVEN-DEVELOPMENT.md`, and integrator docs (`USAGE.md`, `CONFIGURATION.md`). Demos are illustrative unless promoted in the spec.

### II. Spec-first, test-proven
PHPUnit and PHPStan (and Vitest when frontend exists) are the mechanical proof. Behavioral changes require tests.

### III. 100% code inventory traceability
Every production file under `src/` must appear in `specs/001-baseline/code-inventory.md`. New files require spec updates in the same PR.

### IV. Cursor + Spec Kit
GitHub Spec Kit is initialized with **Cursor Agent** (`cursor-agent`). Skills live in `.cursor/skills/speckit-*`.

### V. Symfony compatibility
Follow declared PHP/Symfony ranges in `composer.json` and README badges.

### VI. Build on zenstruck, fail closed
The kit extends `zenstruck/messenger-monitor-bundle` (decorates its storage, reads its transports / workers / history
models) and never forks it. The admin UI is private by default (view / manage roles, custom checker); an empty roles
list denies; CSRF is mandatory on every mutation; the container refuses insecure wiring (no form CSRF, demo switch
outside debug, UI without a history storage). Retries go to the original transport, never inline.

### VII. Personal data minimised, work bounded
Payloads, handler results and failure messages are redacted before display (results also before storage); payloads are
for managers only; retention purges history in keyset batches. Lists are paginated, batches and purges capped, and no
service keeps request or user data between requests.

## Governance
Amendments update this file, baseline spec when principles affect behavior, and `CHANGELOG.md` when consumer-visible.

**Version**: 1.0.0 | **Ratified**: 2026-11-01 | **Last Amended**: 2026-11-01
