# Templates

Pages of MessengerMonitorKitBundle on the UiKit page contract (REQ-UI-003, UiKit 2.20+). The admin UI
(`web_ui.path_prefix`, default `/admin/messenger`, enabled with `web_ui.enabled: true`) has seven page templates.
`Nowo\MessengerMonitorKitBundle\Ui\MonitorPageFactory` builds the UiKit view models (`Nowo\UiKitBundle\View\*`) for the
controllers; `Nowo\MessengerMonitorKitBundle\Ui\MonitorPages` resolves the layout (`web_ui.layout_template`, null =
`nowo_ui_kit.layouts.admin`) and the per-page overrides (`web_ui.templates.<page>`). Every page extends a UiKit
scaffold. Contract test: `tests/Integration/Contract/PageContractTest.php` (UiKit `PageContractTestCase`, strict
missing-translation rule, plus "no personal data in the HTML"). Twig namespace: `@NowoMessengerMonitorKitBundle` (views
in `src/Resources/views/`).

## Table of contents

- [Pages](#pages)
- [Personal data in the pages](#personal-data-in-the-pages)
- [Unique pages](#unique-pages)
- [Per-page overrides](#per-page-overrides)
- [Overriding templates (REQ-TWIG-001)](#overriding-templates-req-twig-001)
- [Bundle partials and assets](#bundle-partials-and-assets)
- [Template contract](#template-contract)

## Pages

Every view model passes `bundle: 'NowoMessengerMonitorKitBundle'` (theme exception
`nowo_ui_kit.theme.bundles.NowoMessengerMonitorKitBundle`) and `layout: MonitorPages::layout()`. Labels are
`NowoMessengerMonitorKitBundle` translation keys (`MonitorPages::label()`); every page header carries the area nav
(`nav.*`; Settings only for managers).

| Page (`web_ui.templates` id) | Template | Scaffold | View model | Data blocks filled |
| --- | --- | --- | --- | --- |
| Dashboard (`dashboard`) | `monitor/dashboard.html.twig` | `page/dashboard` | `DashboardPage` from `MonitorPageFactory::dashboard()`: 8 `KpiTile`s (processed, failed + rate, wait, handling, pending, parked, workers, recording + retention); also `transports`, `workers`, `failures` (`Table`) and `settings`; root `data-testid="mmk-dashboard"` | `nowo_ui_dashboard_cards` (three `card` partials with the tables) |
| History (`history`) | `monitor/history.html.twig` | `page/list` | `ListPage` from `MonitorPageFactory::history()`: filters (`HistoryFilterType` FormView), columns finished (sortable, `SortRequest`), message, transport, status, attempt, wait, handling, tags; row action `view`; `Pagination`; empty state; root `data-testid="mmk-history"` | `nowo_ui_list_row` (`_cells.html.twig`: status badge) |
| History row (`history_show`) | `monitor/history_show.html.twig` | `page/show` | `ShowPage` from `MonitorPageFactory::historyShow()`: sections summary / timing / failure, back link; also `attempts` (`Table`), `results` (redacted JSON or null), `failure` (redacted text or null); root `data-testid="mmk-history-show"` | `nowo_ui_show_after` (failure message, results, attempts cards; texts escaped in `<pre>`) |
| Transports (`transports`) | `monitor/transports.html.twig` | `page/list` | `ListPage` from `MonitorPageFactory::transports()`: name, kind, count; row action `messages` on listable transports; root `data-testid="mmk-transports"` | `nowo_ui_list_row` (kind badge) |
| Transport (`transport`) | `monitor/transport.html.twig` | `page/list` | `ListPage` from `MonitorPageFactory::transport()`: id, message, dispatched, error, retry target, tags; on a failure transport for managers header actions "retry all" / "remove all" and row actions retry / remove (POST, FormKit CSRF-only forms, UiKit `Confirm`); root `data-testid="mmk-transport"` | none |
| Message (`message`) | `monitor/message.html.twig` | `page/show` | `ShowPage` from `MonitorPageFactory::message()`: sections summary / error / stamps, header actions retry / remove (failure transport, managers); also `payload` (redacted JSON or null) and `error` (redacted text); root `data-testid="mmk-message"` | `nowo_ui_show_after` (error message, payload cards) |
| Settings (`settings`) | `monitor/settings.html.twig` | `page/settings` | `SettingsPage` from `MonitorPageFactory::settings()`: section `switches` (`MonitorSettingsType`, or a read-only intro with `settings.store: config`) and `purge` (`PurgeType`), stacked; root `data-testid="mmk-settings"` | none (scaffold default: `form_start`, REQ-TWIG-003 child loop, submit, `form_end`) |

## Personal data in the pages

Message payloads, handler results and failure messages can hold personal data. They reach the templates already
redacted (`Redactor`: key list, e-mail and long-number masks, host redactors) and are printed with Twig auto-escaping
inside `<pre class="nowo-ui-code-block">` — never `|raw`. Payloads and handler results are passed only for the manage
area; viewers get an empty state.

## Unique pages

None: every page extends a UiKit scaffold.

## Per-page overrides

`web_ui.templates.<page>` replaces one page template; extend the vendor one and override a block:

```twig
{# templates/admin/messenger/history.html.twig #}
{% extends '@!NowoMessengerMonitorKitBundle/monitor/history.html.twig' %}

{% block nowo_ui_list_after %}<p class="nowo-ui-muted">Rows older than 1 month are purged nightly.</p>{% endblock %}
```

```yaml
nowo_messenger_monitor_kit:
    web_ui:
        templates:
            history: 'admin/messenger/history.html.twig'
```

## Overriding templates (REQ-TWIG-001)

Full-file overrides live under `templates/bundles/NowoMessengerMonitorKitBundle/<subpath>` and always win
(`TwigPathsPass` prepends that directory). **Freeze rule:** an application file at the override path hides every vendor
change to that path until you delete or merge it. Prefer the theme / layout, then `web_ui.templates`, then a full-file
override. See [USAGE.md](USAGE.md#overriding-templates) for the procedure and the `<subpath>` list.

## Bundle partials and assets

| Partial | Purpose |
| --- | --- |
| `monitor/_cells.html.twig` | Row cells: `status` (success / failed) and `kind` (queue / failure) as translated badges through the `badge_*` theme slots; other values escaped, empty values as a muted dash |
| `monitor/_table.html.twig` | A monitor `Table` through the UiKit `table` partial with `_cells.html.twig` rows |

The bundle ships no CSS / JS and no local copy of a UiKit partial (pagination, confirm, modal, flashes, empty state all
come from UiKit). Confirm dialogs need UiKit's `nowo-ui-confirm` script (included by the UiKit `scripts` partial).

## Template contract

Stable for 1.x: the page templates and partials above, the `web_ui.templates` ids, the data blocks listed, the view
model fields used, and the `data-testid` hooks (`mmk-*`). Changes are recorded in [UPGRADING.md](UPGRADING.md) under
"Template contract".
