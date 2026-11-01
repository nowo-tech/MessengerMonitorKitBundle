# Demo with FrankenPHP

This bundle includes a runnable demo with FrankenPHP in:

- `demo/symfony8` — Symfony **8.1** on FrankenPHP **PHP 8.5** (`dunglas/frankenphp:1-php8.5-alpine`)

**PHP version policy (Symfony 8 demos):** use the newest PHP minor published in official FrankenPHP images when the demo’s `require.php` and Symfony 8 allow it (today: **8.5**). Bump the Dockerfile tag when FrankenPHP adds a newer PHP and constraints still match.

The demo uses:

- Caddy on HTTP `:80` inside the container
- **`Caddyfile`**: **worker** mode (`php_server { worker ... }`) for throughput
- **`Caddyfile.dev`**: classic `php_server` (**no worker**) — hot-reload friendly

Runtime selection is via **`FRANKENPHP_MODE`** (`classic` | `worker`), not `APP_ENV`. Compose still sets **`APP_ENV=dev`** / **`APP_DEBUG=1`** and mounts **`docker/php-dev.ini`**.

## Table of contents

- [Quick start](#quick-start)
- [Switching classic vs worker (`FRANKENPHP_MODE`)](#switching-classic-vs-worker-frankenphp_mode)
- [Worker mode and kernel reset](#worker-mode-and-kernel-reset)
- [Demo services and data](#demo-services-and-data)
- [Timeout hierarchy](#timeout-hierarchy)
- [Development stack in demos](#development-stack-in-demos)
- [Troubleshooting](#troubleshooting)
- [Demo smoke (REQ-TEST-011)](#demo-smoke-req-test-011)
- [Playwright e2e and screenshots (REQ-DEMO-013)](#playwright-e2e-and-screenshots-req-demo-013)

## Quick start

From the bundle root:

```bash
make -C demo up-symfony8
```

Then open:

- Symfony 8.1: `http://localhost:8094`

## Switching classic vs worker (`FRANKENPHP_MODE`)

| Value | Behaviour |
| --- | --- |
| **`worker`** (default) | Use the worker Caddyfile |
| **`classic`** | Entrypoint copies `Caddyfile.dev` (plain `php_server`) |

Set in `.env` / `.env.example`. Compose passes `FRANKENPHP_MODE=${FRANKENPHP_MODE:-worker}` into the PHP service. After changing `.env`, run `docker compose up -d` (or `make up`) so the container is **recreated** — a plain `restart` does not reload env. No image rebuild is required.

## Worker mode and kernel reset

This package is audited as safe when FrankenPHP keeps the Symfony kernel warm **without** resetting it between requests: every service is stateless except the recording switch, a TTL copy of two booleans (`recording.check_interval`, 5 s in the demo). Full checklist: [FRANKENPHP-WORKER-AUDIT.md](FRANKENPHP-WORKER-AUDIT.md). Try it: switch "Record history" off on `/admin/messenger/settings` — running workers stop writing rows within 5 s, without a restart or a redeploy.

## Demo services and data

| Service | Image | Purpose |
| --- | --- | --- |
| `php` | `dunglas/frankenphp:1-php8.5-alpine` (+ `pdo_mysql`) | Symfony 8.1 app on port `${PORT:-8094}` (loopback only); the entrypoint creates the database, the schema (`doctrine:schema:update` — demo only, applications use migrations) and the Messenger tables (`messenger:setup-transports`) |
| `db` | `mysql:8.0` (no host port, REQ-DEMO-006) | `messenger_processed_messages` (history) and `messenger_messages` (Doctrine transports `async` and `failed`) |

`make setup` (or `make seed`) runs `app:demo:seed`: it resets the history, the queues and the runtime switches,
dispatches invoices (`SendInvoiceEmail`), inventory syncs (`SyncInventory`) and page views (`TrackPageView`) to the
`async` Doctrine queue, then runs `messenger:consume async` until the queue is empty. The invoice to an `.invalid`
address and the `OLD-*` SKUs fail (`max_retries: 0`) and are parked on `failed`; page views are optional messages, not
recorded until the switch is on. The monitor is open without login (`security.allow_unauthenticated: true`, accepted
only because the demo runs with `kernel.debug`; port bound to 127.0.0.1).

## Timeout hierarchy

REQ-RUNTIME-001 does not apply to the bundle (no outbound HTTP or processes); the demo keeps the usual FrankenPHP
limits.

| Layer | Demo value |
| --- | --- |
| PHP `max_execution_time` / `max_input_time` | Caddyfile `php_ini` |
| Caddy `servers.timeouts.write` | Caddyfile |
| FrankenPHP `max_wait_time` | Caddyfile (wait for a free PHP thread) |

## Development stack in demos

The demo includes:

- **Symfony Debug** (`symfony/debug-bundle`)
- **Symfony Web Profiler** (`symfony/web-profiler-bundle`)
- **Doctrine ORM** (MySQL), **Messenger** (Doctrine transports), **zenstruck/messenger-monitor-bundle**, **UiKit** and **FormKit**
- **Nowo Twig Inspector** (`nowo-tech/twig-inspector-bundle`) and **Nowo Hot Reload** (`nowo-tech/hot-reload-bundle`) — required together on FrankenPHP demos (dev/test only; Caddyfile Mercure + `hot_reload`, plus `worker { watch }` in worker mode). Do not enable Hot Reload in production.
- **`APP_DEBUG=1`** in `.env.example`

Example `config/bundles.php`:

```php
<?php

declare(strict_types=1);

return [
    Symfony\Bundle\FrameworkBundle\FrameworkBundle::class     => ['all' => true],
    Symfony\Bundle\SecurityBundle\SecurityBundle::class       => ['all' => true],
    Symfony\Bundle\TwigBundle\TwigBundle::class               => ['all' => true],
    Twig\Extra\TwigExtraBundle\TwigExtraBundle::class          => ['all' => true],
    Doctrine\Bundle\DoctrineBundle\DoctrineBundle::class      => ['all' => true],
    Zenstruck\Messenger\Monitor\ZenstruckMessengerMonitorBundle::class => ['all' => true],
    Nowo\UiKitBundle\NowoUiKitBundle::class                    => ['all' => true],
    Nowo\FormKitBundle\NowoFormKitBundle::class                => ['all' => true],
    Symfony\Bundle\DebugBundle\DebugBundle::class             => ['dev' => true, 'test' => true],
    Symfony\Bundle\WebProfilerBundle\WebProfilerBundle::class => ['dev' => true, 'test' => true],
    Nowo\TwigInspectorBundle\NowoTwigInspectorBundle::class   => ['dev' => true, 'test' => true],
    Nowo\MessengerMonitorKitBundle\NowoMessengerMonitorKitBundle::class   => ['all' => true],
];
```

## Troubleshooting

- If app does not respond, run `make -C demo/symfony8 logs`.
- If routes/config changed, run `make -C demo/symfony8 cache-clear`.
- If dependencies are outdated, run `make -C demo/symfony8 update-bundle`.
- `Table '…messenger_processed_messages' doesn't exist` right after `make up`: MySQL was still initialising; run `make -C demo/symfony8 setup`.
- Code changes do not show in worker mode: restart the PHP container (`docker compose restart php`) or switch to `FRANKENPHP_MODE=classic`.

## Demo smoke (REQ-TEST-011)

Boot the FrankenPHP demo, expect **HTTP 200** on the published port, then tear down:

```bash
make demo-smoke
# or: make -C demo demo-smoke
```

Smoke URL: `http://127.0.0.1:8094` (Symfony 8.1 demo). This target is also part of `make -C demo release-check` / root `make release-check`.

## Playwright e2e and screenshots (REQ-DEMO-013)

With the demo running:

```bash
make test-e2e            # or: make -C demo/symfony8 test-e2e
make demo-screenshots    # refreshes docs/images/demo/*.webp
```

Both run in the official Playwright image (`mcr.microsoft.com/playwright`, host network, one worker) and reseed the
messages first (`make seed`). The specs live in `demo/symfony8/e2e/tests/`: `demo.spec.ts` (home queue depth, dashboard,
history filter by status, failed message payload redacted, retry back to the original transport through the UiKit
confirm dialog, settings switch saved) and `screenshots.spec.ts` (two WebP captures with the demo masthead, the monitor
and the web debug toolbar; PNGs are converted with `sharp` and deleted).
