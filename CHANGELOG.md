# Changelog

## [4.1.0] — 2026-07-03

- Removed legacy `inc/config.class.php` and `inc/mailcollector.class.php`, fully superseded by `src/Config.php` and `src/MailCollectorWrapper.php`
- `src/Config.php` now actually renders `templates/pages/config.html.twig` via `TemplateRenderer` instead of duplicating the form as inline HTML
- Removed dead `Config::configUpdate()` (never called: the form posts to `front/save_config.php`, not through core's `Config::update()`/`config_class` flow)
- Added `locales/mailanalyzer.pot` translation template
- Translated remaining non-English code comments
- Rebased onto upstream master (4.0.1/4.0.2), carrying forward:
  - `message_id` column widened from `VARCHAR(255)` to `VARCHAR(512)`
  - Schema upgrade steps in `plugin_mailanalyzer_install()` now gated behind a stored `dbversion`, so they only run once instead of on every activation/update
  - Fixed a version-bounds check in `plugin_mailanalyzer_check_prerequisites()` that could never trigger (`&&` of two mutually exclusive conditions, now `||`)
  - Default `use_threadindex` config is now only seeded when unset, so upgrading no longer silently resets a saved value back to 0

## [4.0.0] — 2026-03-16

### GLPI 11 compatibility

#### Breaking changes
- **Minimum GLPI version raised to 11.0.0** (use 3.2.x for GLPI 10)
- PHP minimum raised to **8.1**

#### Architecture
- Classes migrated from `inc/` to `src/` with PSR-4 namespace `GlpiPlugin\Mailanalyzer`
  - `inc/config.class.php` → `src/Config.php` (`GlpiPlugin\Mailanalyzer\Config`)
  - `inc/mailcollector.class.php` → `src/MailCollectorWrapper.php` (`GlpiPlugin\Mailanalyzer\MailCollectorWrapper`)
  - `hook.php` class `PluginMailAnalyzer` → `src/MailAnalyzer.php` (`GlpiPlugin\Mailanalyzer\MailAnalyzer`)
- Added `composer.json` with PSR-4 autoload configuration

#### setup.php
- Hook keys now use `Glpi\Plugin\Hooks` constants (`Hooks::PRE_ITEM_ADD`, `Hooks::ITEM_ADD`, etc.)
- Hook callbacks reference `MailAnalyzer::class` instead of bare strings
- Version range updated: `11.0.0` → `12.0.0`
- Removed broken `plugin_mailanalyzer_check_prerequisites()` compatibility check (used `Toolbox::addslashes_deep()` removed in GLPI 11)
- Added `Plugin::isPluginActive()` guard before registering hooks

#### hook.php
- Replaced `$DB->query()` / `or die()` with `Migration::addField()` and `Migration::addKey()` (raw SQL queries are prohibited in GLPI 11)
- Upgrade path preserved: detects and renames legacy `mailgate_id` → `mailcollectors_id`
- `plugin_mailanalyzer_uninstall()` remains intentionally empty (table preserved by design)

#### Config UI
- Replaced raw HTML (`echo "<table class='tab_cadre_fixe'>..."`) with `TemplateRenderer::getInstance()->display()`
- New Twig template: `templates/pages/config.html.twig`
- UI uses GLPI 11 native Tabler/Bootstrap 5 components: `card`, `form-switch`, `btn btn-primary`
- No custom CSS file — zero added assets
- CSRF token added via `{{ csrf_token() }}`

#### Hook callbacks (MailAnalyzer class)
- `plugin_pre_item_add_mailanalyzer` → `MailAnalyzer::preItemAdd(Ticket $item)`
- `plugin_item_add_mailanalyzer` → `MailAnalyzer::itemAdd(Ticket $item)`
- `plugin_item_purge_mailanalyzer` → `MailAnalyzer::itemPurge(Ticket $item)`
- `$DB->request('table', [...])` two-arg syntax → `$DB->request(['FROM' => ..., 'WHERE' => ...])` (two-arg deprecated in GLPI 11)
- Removed `Toolbox::addslashes_deep()` calls (auto-sanitization removed in GLPI 11; data is always raw)

#### front/config.form.php
- Fixed include path: uses `__DIR__` relative path instead of hardcoded `../../../inc/includes.php`
- `Session::setActiveTab` argument updated to match new namespaced class `GlpiPlugin\Mailanalyzer\Config$1`

---

## [3.2.2] — 2025-xx-xx

- Last release compatible with GLPI 10.0.18+

## [3.2.0] — GLPI 10.0.0+

## [2.1.0] — GLPI 9.5.x
