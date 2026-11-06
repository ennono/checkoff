# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

CheckOff is a WordPress plugin (v1.0.0, text domain `checkoff`) that lets visitors build their own multi-level checklists from building blocks and tick them off. Structure and implementation follow the sister plugin **MixMyMeals** (`ennono/mixmymeals`): singleton main class, a settings class with tabs, an admin API that renders the fields, one class per concern in `includes/lib/`, shortcodes and their AJAX handlers in one class, jQuery frontend with a debug and a `.min.js` flavour, catalogue in `lang/`.

## Prefixes

- PHP classes `Checkoff_*`, files `class-checkoff-*.php`, functions `checkoff_*`, options and transients `checkoff_*`, filters/actions `checkoff_*`.
- Post type `cko_checklist`, post meta `_cko_*`, shortcodes `cko_*`, AJAX actions `cko_*`, CSS classes `cko-*`, query parameter `cko`.
- Source language English; German in `lang/checkoff-de_DE.{po,mo,l10n.php}`.

## Development commands

```bash
composer install
./vendor/bin/phpcs --standard=phpcs.xml checkoff.php uninstall.php includes
./vendor/bin/phpcbf --standard=phpcs.xml <file>

# Assertions against a real install (creates and deletes its own checklists)
wp eval-file wp-content/plugins/checkoff/tools/checklist-test.php

# Translations
wp i18n make-pot . lang/checkoff.pot --exclude=tools,vendor
wp i18n make-mo lang/ && wp i18n make-php lang/
```

JavaScript exists twice: `assets/js/*.js` (debug, used with `SCRIPT_DEBUG`) and `assets/js/*.min.js`. After editing, rebuild the minified file (`npx terser assets/js/frontend.js -c -m -o assets/js/frontend.min.js`).

## Architecture

### Bootstrap

`checkoff.php` requires all class files → `checkoff()` creates `Checkoff::instance()` and `Checkoff_Settings` → wrapper methods instantiate post types, meta boxes, shortcodes, cleanup and privacy.

### Data model

One `cko_checklist` post per checklist (not public, no permalink, `create_posts` disabled — checklists are made on the frontend only). Meta:

| Meta | Content |
|---|---|
| `_cko_public_id` | the random ID (stored lower case, no dashes) |
| `_cko_structure` | the block tree |
| `_cko_values` | leaf ID → value |
| `_cko_revision` | counted up on every structure save |
| `_cko_last_access` | unix time, written at most once an hour on views |

**The public ID is the only way in.** `Checkoff_Public_Id` generates it (alphabet without 0/o/1/l/i, length setting, default 12 ≈ 59 bits), normalises input (case, spaces, dashes) and formats it in groups of four. The post ID never reaches the frontend — `Checkoff_Checklist::to_client()` is what the browser gets. Failed lookups are rate-limited per visitor (`Checkoff_Rate_Limit`, salted IP hash in a transient); once the limit is reached every lookup is refused for the hour, correct ones included, or the refusal would tell a guesser when they hit.

**Structure and values are separate on purpose.** A tick sends one leaf (`cko_set_value`); an edit sends the whole tree (`cko_save_structure`) with the revision it was based on, and a stale revision is answered with 409 so two tabs cannot overwrite each other. On every structure save the values are re-validated: values of removed leaves go, the rest are checked against the (possibly changed) block settings via `revalidate_value()`.

**Tree format.** Block: `{id, kind:"block", type, title, description, settings, items[]}`; leaf: `{id, kind:"item", label}`. Only block types with `supports_children()` may hold blocks among their items. `Checkoff_Checklist::sanitize_structure()` validates on write **and on read**: unknown types become `simple`, unsupported children are dropped, duplicate/invalid IDs are replaced, depth (`checkoff_max_depth`) and size (`checkoff_max_nodes`) are enforced by dropping the excess rather than refusing the save.

### Building blocks

`includes/lib/blocks/`: abstract `Checkoff_Block_Type` plus one class per block (`simple`, `weekdays`, `color_scale`, `number_scale`, `counter`, `note`). A block class is the only place that knows its settings and allowed values (`default_settings`, `settings_schema`, `sanitize_settings`, `sanitize_value`, `revalidate_value`, `value_for_display`). `Checkoff_Block_Types` is the registry (filter `checkoff_block_types`), knows which blocks are switched on (`checkoff_block_<key>_enabled`, default on; `simple` always) and hands the definitions to the frontend, so the builder never offers what the server would refuse.

**Never rename a block key** — stored checklists refer to it.

Weekdays with the weekly reset store `{days, week:"2026-W45"}`; the week is always set by the server, `value_for_display()` hides last week's ticks without deleting them, and `revalidate_value()` keeps the stored week so re-saving the structure cannot move old ticks into the current week.

### Frontend

`[cko_checklist]` embeds the checklist as JSON in `script.cko-data` (encoded with `JSON_HEX_*`, so a title cannot close the script element) and `assets/js/frontend.js` draws it. Text inputs update the model without redrawing (focus and caret stay); clicks redraw with focus restored by `data-focus` keys. Progress is computed in the browser only. The recently opened list is localStorage only (`cko_recent`). Assets are registered on `wp_enqueue_scripts` but enqueued only by the shortcodes (`Checkoff::enqueue_frontend_assets()`).

Pages carrying `?cko=` get `noindex, nofollow` (`Checkoff_Post_Types::noindex_checklist_views()`), because the address contains the secret.

### Settings

Tabs General, Building blocks, Limits, Maintenance under CheckOff → Settings (`edit.php?post_type=cko_checklist&page=checkoff_settings`). Fields are declared in `Checkoff_Settings::settings_fields()` (filter `checkoff_settings_fields`) and rendered by type in `Checkoff_Admin_API::display_field()` (filter `checkoff_render_settings_field`). Every reader passes its default to `get_option()`, since `register_setting()` defaults only exist during admin requests.

### Cleanup and uninstall

`Checkoff_Cleanup` deletes checklists unused for `checkoff_cleanup_inactive_days` (off by default) via daily cron. `uninstall.php` always removes options and transients and deletes checklists only with `checkoff_delete_data_on_uninstall`.
