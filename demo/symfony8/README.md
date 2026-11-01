# Messenger Monitor Kit Bundle — Symfony 8 demo

FrankenPHP (worker mode by default) + MySQL. What it shows:

- `App\Message\SendInvoiceEmail`, `SyncInventory` and `TrackPageView` routed to a Doctrine queue `async`, with a
  listable Doctrine failure transport `failed` (`config/packages/messenger.yaml`);
- `app:demo:seed` dispatches them and runs a worker (`messenger:consume async`): the invoice to an `.invalid` address
  and the `OLD-*` SKUs fail and are parked on `failed`, page views are skipped by the history (optional class);
- the monitor under `/admin/messenger` (`config/packages/nowo_messenger_monitor_kit.yaml`): dashboard, history with
  filters, transports, failed messages with retry / remove (CSRF + confirm), redacted payloads, the settings page
  (recording switch, retention, manual purge).

Commands (from this directory):

- `make up` / `make down` – start / stop (prints `Demo started at: http://localhost:8094`)
- `make setup` – schema + Messenger tables + demo messages
- `make seed` – reset and replay the demo messages (idempotent)
- `php bin/console nowo:messenger-monitor:purge --auto` – the automatic purge the host schedules
- `make test-e2e` / `make demo-screenshots` – Playwright (official image)

The demo opens the monitor without login (`security.allow_unauthenticated: true`, debug only). Never copy that to
production.
