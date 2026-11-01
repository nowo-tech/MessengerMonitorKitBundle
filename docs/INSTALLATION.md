# Installation

## Table of contents

- [Requirements](#requirements)
- [Install with Composer](#install-with-composer)
- [Register the bundles](#register-the-bundles)
- [History table and migrations](#history-table-and-migrations)
- [Routes and security](#routes-and-security)
- [Failure transport](#failure-transport)
- [Automatic purge](#automatic-purge)
- [Twig Extra, UiKit assets and FormKit](#twig-extra-uikit-assets-and-formkit)
- [Flex recipe](#flex-recipe)

## Requirements

- PHP 8.2–8.5, Symfony 7.4 / 8.0 / 8.1 (Symfony 8 needs PHP 8.4+).
- `symfony/messenger` with at least one transport; a **listable** failure transport (Doctrine or Redis) for the
  failed message pages and the retry actions.
- Doctrine ORM (`doctrine/doctrine-bundle`, `doctrine/orm`) for the history (zenstruck ORM storage).
- [zenstruck/messenger-monitor-bundle](https://github.com/zenstruck/messenger-monitor-bundle) `^0.6` (installed as a
  dependency), [UiKitBundle](https://github.com/nowo-tech/UiKitBundle) and
  [FormKitBundle](https://github.com/nowo-tech/FormKitBundle) (dependencies).
- `symfony/security-bundle` for the admin UI (unless `security.allow_unauthenticated` in dev / demo only).

## Install with Composer

```bash
composer require nowo-tech/messenger-monitor-kit-bundle
```

Optional integrations:

```bash
composer require symfony/lock                          # serialise concurrent retries / removals (recommended)
composer require nowo-tech/instance-settings-bundle    # switches in an instance settings section
composer require nowo-tech/health-kit-bundle           # queue depth as a readiness probe
```

## Register the bundles

With Flex the recipe registers them. Otherwise, in `config/bundles.php`:

```php
return [
    // ...
    Zenstruck\Messenger\Monitor\ZenstruckMessengerMonitorBundle::class => ['all' => true],
    Nowo\UiKitBundle\NowoUiKitBundle::class => ['all' => true],
    Nowo\FormKitBundle\NowoFormKitBundle::class => ['all' => true],
    Nowo\MessengerMonitorKitBundle\NowoMessengerMonitorKitBundle::class => ['all' => true],
];
```

`Twig\Extra\TwigExtraBundle\TwigExtraBundle` must be registered too (REQ-TWIG-004).

## History table and migrations

By default (`history.bundle_entity: true`) the kit maps its entity
`Nowo\MessengerMonitorKitBundle\Entity\ProcessedMessage` (table `messenger_processed_messages`, indexes on
`finished_at` and `run_id`, plus `doctrine.table_prefix`) and sets it as
`zenstruck_messenger_monitor.storage.orm.entity_class`. Create the table with a migration — never
`doctrine:schema:update --force` in production:

```bash
php bin/console doctrine:migrations:diff
php bin/console doctrine:migrations:migrate
```

Hosts that already have a zenstruck history entity keep it: set `zenstruck_messenger_monitor.storage.orm.entity_class`
yourself and the kit uses that entity (the batched purge reads its table and columns from the Doctrine metadata). The
table layout is zenstruck's, so an existing table needs no migration.

## Routes and security

```yaml
# config/routes/nowo_messenger_monitor_kit.yaml
nowo_messenger_monitor_kit:
    resource: .
    type: nowo_messenger_monitor_kit
```

The bundle controllers are not autoconfigured: a host `routing.controllers` import never exposes them (REQ-SF-004
rule 7). Protect the prefix in the host firewall (REQ-UI-002 layer 1):

```yaml
# config/packages/security.yaml
security:
    access_control:
        - { path: ^/admin/messenger, roles: ROLE_ADMIN }
```

## Failure transport

```yaml
# config/packages/messenger.yaml
framework:
    messenger:
        failure_transport: failed
        transports:
            async: '%env(MESSENGER_TRANSPORT_DSN)%'
            failed: 'doctrine://default?queue_name=failed'   # listable (AMQP is not)
```

With an AMQP queue, the `failed_dsn_env` processor derives a listable Redis or Doctrine DSN (see
[CONFIGURATION.md](CONFIGURATION.md#transport-factories)).

## Automatic purge

Schedule the command often (it purges only when the automatic purge is on and `retention.min_interval` elapsed):

```cron
*/15 * * * * php /app/bin/console nowo:messenger-monitor:purge --auto
```

or with Symfony Scheduler:

```php
RecurringMessage::every('15 minutes', new RunCommandMessage('nowo:messenger-monitor:purge --auto'));
```

## Twig Extra, UiKit assets and FormKit

The pages render with UiKit scaffolds and FormKit forms. Run `php bin/console assets:install` once (UiKit CSS / JS)
and point `nowo_ui_kit.layouts.admin` at the host admin layout (see [USAGE.md](USAGE.md#layout-and-theme)).
`twig/extra-bundle` and `twig/string-extra` are required by the package and must be enabled in the host (REQ-TWIG-004).

## Flex recipe

The recipe lives in `.symfony/recipe/nowo-tech/messenger-monitor-kit-bundle/1.0/` (REQ-RECIPE-001): it registers the
bundles and copies `config/packages/nowo_messenger_monitor_kit.yaml` and `config/routes/nowo_messenger_monitor_kit.yaml`.
It ships **no security file**: a recipe file defining `security.access_control` would replace the host's own list
(Symfony does not merge that prototype across files) and silently drop its other rules. Its `post-install.txt` prints
the `access_control` line to add to `config/packages/security.yaml` (see above), the migration, the failure transport
and the scheduled purge.
