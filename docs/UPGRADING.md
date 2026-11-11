# Upgrading

## Table of contents

- [Unreleased](#unreleased)
- [To 1.1.0](#to-110)
- [To 1.0.1](#to-101)
- [To 1.0.0](#to-100)
  - [Requirements](#requirements)
  - [Adopting the kit from an in-app monitor](#adopting-the-kit-from-an-in-app-monitor)
  - [Template contract](#template-contract)
  - [Breaking changes](#breaking-changes)

## Unreleased

## To 1.1.0

Standard hosts: **no configuration change**. History rows are written with DBAL instead of zenstruck's
`persist()` + `flush()`: the worker no longer flushes your default entity manager after each message.

- Audit listeners: prefer `$event->userIdentifier` over `$event->user` (deprecated, removed in 2.0).
- New optional `doctrine.entity_manager: <name>` (DDD hosts, or to keep the history off your default manager):
  declare the manager under `doctrine.orm.entity_managers` (no mappings needed; the bundle adds its own), and create
  or migrate `messenger_processed_messages` on its connection. It only applies to the bundle history entity
  (`history.bundle_entity: true` and no zenstruck `entity_class` of your own).
- A custom zenstruck `Storage` is untouched; with zenstruck's ORM storage, `save()` and `delete()` now go through
  `DbalHistoryWriter` (reads and purges still delegate).

## To 1.0.1

From **1.0.0**:

```bash
composer update nowo-tech/messenger-monitor-kit-bundle
```

The bundle now requires `nowo-tech/ui-kit-bundle` ^2.21. No configuration or code change:

```bash
composer update nowo-tech/messenger-monitor-kit-bundle nowo-tech/ui-kit-bundle --with-dependencies
```

## To 1.0.0

Initial release: there is nothing to upgrade from.

### Requirements

PHP 8.2+, Symfony 7.4 / 8.0 / 8.1, Doctrine ORM 3.3+, `zenstruck/messenger-monitor-bundle` ^0.6, UiKit ^2.20,
FormKit ^2.17. A listable failure transport for the retry pages.

### Adopting the kit from an in-app monitor

Applications that already run zenstruck with their own UI:

1. Keep the existing history entity: the kit reads `zenstruck_messenger_monitor.storage.orm.entity_class`, or drop the
   host entity and let the kit map its own (same table name `messenger_processed_messages` and columns: no data
   migration; compare the index names with `doctrine:migrations:diff`).
2. Remove the in-app storage decorator, purger, retrier, queue-depth service and admin controller; import the kit
   routes and set `web_ui.path_prefix` to the old prefix if links must survive.
3. Move the runtime switches: `settings.store: instance_settings` (section `messenger_monitor`) or the cache store;
   old database columns can be dropped once copied.
4. Replace the scheduled cron-based purge with `nowo:messenger-monitor:purge --auto` every 15 minutes and
   `retention.min_interval`.
5. Map audit logging to `MessengerMonitorActionEvent`.

### Template contract

Initial contract: see [TEMPLATES.md](TEMPLATES.md).

### Breaking changes

None (first release).
