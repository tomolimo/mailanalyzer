# Mail Analyzer — GLPI Plugin

> Automatically combines CC/reply emails into a single Ticket instead of creating duplicates.

[![License: GPL v2+](https://img.shields.io/badge/License-GPLv2%2B-blue.svg)](https://www.gnu.org/licenses/old-licenses/gpl-2.0.en.html)

---

## What it does

When an email is sent to GLPI **and** CC'd to other recipients, and those CC recipients use **Reply to All**, GLPI would normally create a new ticket for each reply. This plugin prevents that by:

1. **Deduplicating** — if the same `Message-ID` arrives again, the email is silently moved to the refused folder.
2. **Threading** — if an email references a previous ticket (via `References` or `Thread-Index` headers), it becomes a **followup** on that ticket instead of a new one.
3. **Linking** — if the referenced ticket is closed, a new ticket is created and linked to it.

---

## Compatibility

| Plugin version | GLPI version |
|---|---|
| **4.0.x** | **11.0.x** |
| 3.2.x | 10.0.18+ |
| 2.1.x | 9.5.x |

---

## Installation

### From marketplace / zip

1. Download the release zip and extract into `<glpi>/plugins/mailanalyzer/`
2. Run `composer install --no-dev --optimize-autoloader` inside the plugin directory
3. Go to **Setup → Plugins** and install + activate **Mail Analyzer**

### From source (development)

```bash
cd /var/www/glpi/plugins
git clone https://github.com/tomolimo/mailanalyzer.git mailanalyzer
cd mailanalyzer
git checkout main   # or dev/glpi-11 for the GLPI 11 branch
composer install --no-dev
```

Then install via **Setup → Plugins**.

---

## Configuration

After activation, go to **Setup → General → Mail Analyzer** tab.

| Option | Description |
|---|---|
| **Use Thread-Index** | When enabled, the Microsoft `Thread-Index` header is used in addition to `References` to detect email threads. Useful for Outlook/Exchange environments. Requires an additional connection to the mail server per collected email (slight performance impact). |

---

## How threading works

```
Email A → GLPI creates Ticket #100, stores Message-ID in DB
Email B (Reply-All to A) → References header contains Message-ID of A
    → Plugin finds Ticket #100 → adds as Followup → moves mail to accepted folder
Email B again (duplicate) → Same Message-ID already in DB → refused folder
```

---

## GLPI 11 Migration notes (v3.x → v4.x)

- **PSR-4 autoloading**: classes moved from `inc/` to `src/` with `namespace GlpiPlugin\Mailanalyzer`
- **Hook constants**: now uses `Glpi\Plugin\Hooks::PRE_ITEM_ADD` etc.
- **Database**: `$DB->query()` replaced with `Migration` API + `$DB->request()` / `insert()` / `update()`
- **Config UI**: raw HTML replaced with `TemplateRenderer` + Twig (Bootstrap 5/Tabler)
- **No custom CSS**: UI uses GLPI 11 native Tabler/Bootstrap classes

---

## CLI utility

Decode a raw `Thread-Index` header value:

```bash
php plugins/mailanalyzer/scripts/threadindex.php AQHbR1gAJkY7...
# → 3a1f8b2c4d5e6f7a8b9c0d1e2f3a4b5c
```

---

## License

GPL v2 or later — see [LICENSE](LICENSE).

Original author: Olivier Moron / Raynet SAS — A.Raymond Network.
