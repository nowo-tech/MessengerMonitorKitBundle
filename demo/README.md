# Messenger Monitor Kit Bundle demos

## Symfony 8 (FrankenPHP + MySQL)

```bash
cd demo/symfony8
cp .env.example .env   # if needed
make up      # prints "Demo started at: http://localhost:8094"
make setup   # schema, Messenger tables, demo messages (a worker run fills the history and the failure transport)
```

- App: http://localhost:8094 (queue depth read through `QueueDepth`)
- Monitor: http://localhost:8094/admin/messenger (dashboard, history, transports, failed messages, settings)
- Default `FRANKENPHP_MODE=worker` (change in `.env` and recreate)
- `make seed` again resets the history and the queues and replays the demo messages
- `make test-e2e` / `make demo-screenshots` (Playwright, REQ-DEMO-013)

The demo opens the monitor without login (`security.allow_unauthenticated: true`, accepted only because the demo runs
with `kernel.debug`, and the port is bound to 127.0.0.1). Never copy that to production.

See [DEMO-FRANKENPHP.md](../docs/DEMO-FRANKENPHP.md).
