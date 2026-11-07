# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

CheckOff is a WordPress plugin (v2.1.1, text domain `checkoff`) that lets visitors build their own multi-level checklists from building blocks and tick them off. Structure and implementation follow the sister plugin **MixMyMeals** (`ennono/mixmymeals`): singleton main class, a settings class with tabs, an admin API that renders the fields, one class per concern in `includes/lib/`, shortcodes and their AJAX handlers in one class, jQuery frontend with a debug and a `.min.js` flavour, catalogue in `lang/`.

## Prefixes

- PHP classes `Checkoff_*`, files `class-checkoff-*.php`, functions `checkoff_*`, options and transients `checkoff_*`, filters/actions `checkoff_*`.
- Post types `cko_checklist` and `cko_subscription`, post meta `_cko_*` / `_cko_sub_*`, shortcodes `cko_*`, AJAX actions `cko_*`, CSS classes `cko-*`, query parameter `cko`.
- Source language English; German in `lang/checkoff-de_DE.{po,mo,l10n.php}`.

## Development commands

```bash
composer install
./vendor/bin/phpcs --standard=phpcs.xml checkoff.php uninstall.php includes
./vendor/bin/phpcbf --standard=phpcs.xml <file>

# Assertions against a real install (create and delete their own checklists,
# titled "T …"; mails are caught with pre_wp_mail and never sent)
wp eval-file wp-content/plugins/checkoff/tools/checklist-test.php
wp eval-file wp-content/plugins/checkoff/tools/subscriptions-test.php
wp eval-file wp-content/plugins/checkoff/tools/rest-test.php

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
| `_cko_public_id` | the random ID that edits (stored lower case, no dashes) |
| `_cko_tick_id` | the random ID that only ticks — lists from before 2.0.0 only; no new ones are made |
| `_cko_structure` | the block tree |
| `_cko_v_<leaf ID>` | one row per entry: `array( 'v' => value )` |
| `_cko_revision` | counted up on every structure save |
| `_cko_last_access` | unix time, written at most once an hour on views |
| `_cko_values_round` | the round (0-based) the stored values belong to |
| `_cko_last_round` | summary of the last closed round: number, done, total, open entries (capped) |
| `_cko_h_<round number>` | a kept closed round (only with the history on): dates, closed, done/total, values, the structure at closing |
| `_cko_password` | hash of the optional password (`wp_hash_password()`); absent = level open |
| `_cko_private` | `'1'`: level private (the password guards viewing too); only with a password |
| `_cko_listed` | `'1'`: shown in the public directory; only counts while the level is protected |
| `_cko_h_oldest` | unix time the oldest kept round closed — what the pruning cron queries |

**The public ID is the only way in.** `Checkoff_Public_Id` generates it (alphabet without 0/o/1/l/i, length setting, default 12 ≈ 59 bits), normalises input (case, spaces, dashes) and formats it in groups of four. The post ID never reaches the frontend — `Checkoff_Checklist::to_client()` is what the browser gets. Failed lookups are rate-limited per visitor (`Checkoff_Rate_Limit`, salted IP hash in a transient); once the limit is reached every lookup is refused for the hour, correct ones included, or the refusal would tell a guesser when they hit.

**One link, three levels (2.0.0) — no accounts, on purpose.** The address shows the list; a password protects it. `Checkoff_Checklist::get_level()` is derived, not stored as such: no `_cko_password` → **open** (the address views, ticks and edits — every list before 1.9 and every new one); a password → **protected** (the address views, ticks, polls and subscribes; structure, reset, delete, level and history need the password, 403 `checkoff_locked`); a password plus `_cko_private` → **private** (without the password the address gets nothing but the prompt, 403 `checkoff_private`). `set_level( $level, $password )` is the one writer: protecting an open list needs a password, afterwards `''` keeps the current one; open deletes password, private flag and listing; private deletes the listing. `Checkoff_Password::change( $checklist, $level, $password )` wraps it with the edit check and the cookie. The level was the user's decision after a review of the concept: the earlier two-ID model protected by the secrecy of the address only, so a list could be neither public nor really private.

Access as opened (`get_access()`): `edit`, `locked` (protected without password), `sealed` (private without password: `to_client()` returns `{id, access, level, protected, url}` and nothing of the list — no title; the page renders `renderSealed()`, a password form, and reloads after `cko_unlock`), and `tick`. `Checkoff_Shortcodes::lookup()` calls `Checkoff_Password::apply()` on every lookup (page, AJAX, REST alike), which calls `lock()` or `seal()`. **`request_checklist( $raw_id, $edit, $quota, $sealed_ok )` refuses a sealed list for everything** unless `$sealed_ok` — only unlock and lock pass it; `refuse_sealed()` is the same check for callers using `lookup()` directly (`Checkoff_Subscriptions::request()`: a private list's content is mailed only to whoever may see it).

**The tick ID is legacy.** Lists from before 2.0.0 may have handed out a "link to share" (`_cko_tick_id`); `find()` still opens it with `tick` access, it still only ticks, and a private list seals it too (with the password it opens, still tick-only; `unlock()` refuses a tick ID only on a non-private list). `create()` makes none, `get_tick_id()` no longer creates one lazily, `to_client()` sends no `tick_id`/`tick_url`; an administrator can end one (`remove_tick_id()`). A subscription made through it keeps `_cko_sub_tick_only`. Tests give lists a tick ID explicitly (`cko_legacy_tick()`).

**The public directory (`Checkoff_Directory`).** A protected list its owner marks (`set_listed()`, AJAX `cko_set_listed`, REST `PUT|DELETE …/listed`) appears in `[cko_lists]` and `GET /lists` — title, description, progress, link — most recently used first (`_cko_last_access`). Only protected: an open list there could be changed and deleted by anybody passing by, a private one may not be seen. **No approval, by the user's decision**; an administrator takes a list out in the IDs box (`cko_unlist`), the setting `checkoff_lists_enabled` (General, default on) empties the directory without touching the marks. `is_listed()` checks all three conditions, so a list made private or open leaves at once. The privacy text has a sentence while the directory is on.

**The password** (`Checkoff_Password`). WordPress's means, deliberately: **the hash** by `wp_hash_password()` / `wp_check_password()` in `_cko_password` — **not core's `post_password`, which is plain text** and visitors reuse passwords; **the unlock** like core's post-password cookie: one cookie per list (name from `wp_hash( post ID )`, so it names no list), 10 days, value `expires|wp_hash( post|expires|password hash, 'auth' )` — a new password ends every unlock — httponly, SameSite=Lax, secure on HTTPS; **API clients** send `X-CheckOff-Password` with each request instead (allowed in CORS). Wrong passwords: quota `unlock` per visitor plus `UNLOCK_PER_LIST` (50) per list from all visitors; once either is used up every unlock is refused for the hour, the right password included. Passwords are read unslashed and never sanitized (`from_request()`). There is no recovery without accounts: the access dialog says so, and an administrator can remove a forgotten password in the IDs box (the list becomes open); the list column shows a lock (protected) or a crossed eye (private) and a "Public" badge.

**One row per value.** Each entry's value is its own meta row (`_cko_v_<id>`, wrapped as `array( 'v' => … )` because WordPress stores a bare scalar as text and `true` would come back as `"1"`). A tick writes only its row — with one array, two people ticking at once each wrote back the array and the second erased the first. `store_values()` replaces the whole set (revalidation, reset, rollover) and leaves unchanged rows alone. Checklists from before 1.2.0 have `_cko_values` (one array); `migrate_values()` moves it into rows on first read.

**Live update.** `sync_state()` is the one place computing what an open page shows besides the structure (values, round, last round) plus an md5 fingerprint of it with the revision; `to_client()` and `ajax_poll()` both use it. The page polls `cko_poll` every `checkoff_sync_interval` seconds (default 20, 0 = off) while visible and not busy (unsaved edits, a save running, a note being typed, focus in a text field); an unchanged fingerprint is answered with the fingerprint alone, the structure is sent only for an older revision, and values of the page's own requests still in flight win over the answer. A poll only reads: it closes no round and does not count as a visit.

**Structure and values are separate on purpose.** A tick sends one leaf (`cko_set_value`); an edit sends the whole tree (`cko_save_structure`) with the revision it was based on, and a stale revision is answered with 409 so two tabs cannot overwrite each other. On every structure save the values are re-validated: values of removed leaves go, the rest are checked against the (possibly changed) block settings via `revalidate_value()`.

**Tree format.** A checklist has **no type**: the top level is a container `{id, kind:"checklist", title, description, items[], recurrence}` whose items are blocks only — the stacked building blocks, drawn as sections (heading, no frame). Block: `{id, kind:"block", type, title, description, settings, items[]}`; leaf: `{id, kind:"item", label}`. A block's items are leaves and — where `supports_children()` says so, which every built-in block does since 1.3.0 — nested blocks, drawn framed. Depth counts blocks from the top level (the container is level 0).

**Before 1.3.0 the top level was a block.** `upgrade_legacy()` (called by `sanitize_structure()` for anything not `kind:"checklist"`, so on every read) turns it into a container: a top-level simple list gives up its sub-lists as stacked blocks, with the entries between them gathered in order into untitled simple blocks; any other top-level type becomes the container's one block. Title, description and repetition move to the container, entry IDs stay (ticks, IDs and subscriptions are untouched). Gathering blocks get IDs derived from their first entry (`g<entry id>`, `b<root id>`), so an old tree upgrades to the same new tree on every read, whether or not it is saved in between. `Checkoff_Checklist::sanitize_structure()` validates on write **and on read**: unknown types become `simple`, unsupported children are dropped, duplicate/invalid IDs are replaced, depth (`checkoff_max_depth`) and size (`checkoff_max_nodes`) are enforced by dropping the excess rather than refusing the save.

### Building blocks

`includes/lib/blocks/`: abstract `Checkoff_Block_Type` plus one class per block (`simple`, `status`, `deadline`, `weekdays`, `color_scale`, `number_scale`, `measurement`, `choice`, `switch`, `counter`, `note`). A block class is the only place that knows its settings and allowed values (`default_settings`, `settings_schema`, `sanitize_settings`, `sanitize_value`, `revalidate_value`, `value_for_display`). `Checkoff_Block_Types` is the registry (filter `checkoff_block_types`), knows which blocks are switched on (`checkoff_block_<key>_enabled`, default on; `simple` always) and hands the definitions to the frontend, so the builder never offers what the server would refuse.

**Never rename a block key** — stored checklists refer to it.

**An entry may carry data of its own** in the structure, beside its label: `{id, kind:"item", label, data}`. Only the block type knows it (`sanitize_item_data()`, empty by default, so the key is absent for every other block and for unchanged old trees); it is set while editing, so a tick link cannot change it, and it is dropped when the block changes type (server and `changeType()` alike). So far only the due date: `deadline` stores `data.due` (Y-m-d). `describe_item( $item, $value, $settings, $now )` is the one wording of an entry's state with that data ("overdue since …", "due today", "due on …"); `open_items_of()` carries it as `note`, the mails print it, and `$now` is threaded through from `checklist_data()` like everything else in a mail.

The colour scale (1.11.0) has per-block `labels` (own names left to right, missing ones fall back to the site's) and `reverse` (green first); `Checkoff_Block_Color_Scale::steps_for( $settings )` is the one place that combines them with the site's scale, mirrored by `scaleFor()` in the browser. The value stays the position from the left.

The 1.5.0 blocks in short: `status` stores `'done'` or `'skip'` — a skipped entry returns `{done:0,total:0}` and so leaves the progress (the mail marks it ⊘); `deadline` extends `simple` (value `true`) with the due date and the setting `soon` (days before the date marked as due soon); `measurement` stores a number rounded to `decimals`, `min`/`max` are numbers or `''`, done = inside the range or, without one, entered (`in_range()`, mirrored by `inRange()` in the browser); `choice` stores the **index** of an answer in `settings.options` (1–12 answers, renaming one keeps the choices, removing one drops the choices beyond the end). Settings field types for the builder: `number`, `select`, `checkbox`, `days`, and since 1.5.0 `text`, `decimal` (accepts a decimal comma) and `options` (one answer per line).

**The switch (`switch`, 1.10.0) is a block that holds a value itself.** Its children are its variants (2–6 child blocks of any type, titles name the positions); its value — stored under the **block's own ID** like an entry's, the variant's index, nothing for the first — says which applies. Three hooks on `Checkoff_Block_Type` carry it, all default-off: `holds_value()`, `needs_child_level()` (at the depth limit it becomes `simple`), `prepare_items()` (loose entries move into the first variant, missing variants are added with IDs derived from the block's — `v<n><id>` — so a tree upgrades to the same tree on every read; beyond six are dropped) and `active_child()`. **`leaf_map( $structure, $values )`** maps value-holding blocks too (settings carry `_variants`, the child count, for validation) and, given values, follows only the variant in force; **`children_in_force( $block, $values )`** is the same choice for walkers — progress (`progress_of()`), open entries, mails and the history all go through one of the two, and the browser mirrors it (`activeVariant()`, `progress()`, `renderSwitch()`). Being a value, the position is set by ticking (tick link included), synced live, kept in the history and starts over each round. Editing shows all variants as nested blocks; ticking shows the switch and the active variant without its heading (`cko-block--variant-bare`).

Weekdays with the weekly reset store `{days, week:"2026-W45"}`; the week is always set by the server, `value_for_display()` hides last week's ticks without deleting them, and `revalidate_value()` keeps the stored week so re-saving the structure cannot move old ticks into the current week.

### Frontend

`renderBlock( block, depth )` draws the container (depth 0: `header.cko-head` with title, progress, round; in edit mode description and the repetition panel; then `.cko-sections` and the dashed "Add building block" button that opens the picker), a section (depth 1: no frame in tick mode, framed while editing; an untitled section shows no heading outside edit mode) or a nested sub-list (framed). In `locate()` the container is depth 0. A section's heading row carries, in edit mode, the drag handle, the type icon, the title field, the settings toggle (`settingsOpen[id]`, panel with type, settings and description) and the ⋯ menu (`openBlockMenu()`: add sub-list, duplicate, up, down, delete); in tick mode a collapse chevron.

**Two modes, never mixed.** "Tick off" shows the value controls only, "Edit" the structure only (label fields, handles, delete ×, a `.cko-newentry` field at the end of every block: Enter adds and stays there, Backspace on an empty field goes to the last entry). The switch is the segmented `.cko-mode` in `.cko-bar`; a locked (protected) view shows "Unlock to edit" instead, an earlier tick link `.cko-badge`, a list locked by a conflict neither. A list created empty opens in edit mode (`#edit`) with focus in its first entry field.

**The UI kit lives at the top of `frontend.js`**: `icon()` / `typeIcon()` (block key → Lucide name in `BLOCK_ICONS`), `iconButton()`, `button()`, `toast()` (body-level `.cko-toasts`, optional action), `openMenu( anchor, items, content )` (body-level `.cko-menu`, below the anchor, else above it, else — a long picker on a phone — as high as the viewport and scrolling inside; always kept inside the viewport; closes on outside click, Escape and resize, arrow keys move) and `confirmDialog()` (a `<dialog>`, returns a Promise). Menus and toasts sit outside `.cko-app`, so they carry the tokens themselves. `enhanceForms()` puts the icons into the server-rendered create, open and template forms.

**Deleting is undone, not confirmed.** `remove()` deletes at once and offers "Undo" in a toast for 6 s, and the structure save waits that long (`changed( true, 6000 )`) so an undone delete never reaches the server. Only resetting all ticks and deleting the whole checklist ask first (`confirmDialog()`).

**Sorting is drag and drop** (SortableJS on every `[data-sortable]`, handle `.cko-handle`, `touch-action: none` so it works by touch); `onEnd` calls `moveTo( parent, from, to )`, which changes the model and redraws — Sortable's own DOM move is overwritten by that render. Sections and sub-lists can also be moved with the ⋯ menu, entries with Alt+↑/↓ in their field.

**Third-party code is bundled, never loaded from a CDN** (a CDN request would hand every visitor's IP to a third party and need a line in the privacy text): `assets/vendor/sortablejs/` (SortableJS 1.15.6, MIT) and `assets/vendor/lucide/icons.js` (48 Lucide 0.460.0 icons, ISC, as `window.checkoffIcons` — add an icon by copying its SVG body from lucide-static of that version). Both are registered as dependencies of the frontend script. Licences in their folders and in `readme.txt`.

**No CSS framework, on purpose.** A global one (Bootstrap, Tailwind's preflight) restyles the theme's own buttons and headings. `frontend.css` is a small design system of its own: tokens as custom properties on the container classes (`--cko-accent`, `--cko-done`, `--cko-danger`, `--cko-radius`, `--cko-control`, …), surfaces mixed from `currentColor` so light and dark themes both work, `Canvas` / `CanvasText` for popovers. **Every component rule is scoped `:is(<containers>) .cko-…`**: themes style `button:hover`, `[type=button]:focus` and `input[type=text]` at (0,1,1)–(0,2,1), and single-class rules lost to them — under Hello Elementor a hovered button turned pink and every field got a dark border. A new rule gets the same prefix.

**Theming variables, and what they must keep.** Tokens are set on the container classes (first rule of `frontend.css`); a theme overrides them with a stronger selector. `--cko-accent` is the accent as a surface, `--cko-accent-contrast` text on it, **`--cko-accent-text` (1.11.0, default the accent) the accent as text or icon colour** — every `color:` that used the accent uses it now, so a light brand colour can be made readable without touching the surfaces. The surface variables `--cko-card-bg/-border/-padding` (`.cko-card`, `.cko-history`, `.cko-subscribe`) and `--cko-panel-bg/-border` (`.cko-panel`, blocks while editing) are **deliberately not defined**: each rule reads `var(--cko-card-padding, <its own old value>)`, so nothing changes until a theme sets one. Keep that pattern for new variables — the readme promises existing names and defaults never change. Field-width safety rules use `:where()` so they weigh nothing against a field's own width (`.cko-input--short`); a first version without it widened the unit field. Contrast and overflow were checked with a Playwright audit at 390/820/1280 px with a light (`#E8C547`) and a dark (`#30323D`) accent, and tick/edit mode were compared pixel by pixel with the 1.10 styles.

**The checklist page** (`Checkoff_Checklist_Page`, 1.11.0): the chosen page (`checkoff_checklist_page`), else the first published page with `[cko_app]`, a page template that adds it (filter `checkoff_checklist_page_templates`, the child theme's by default), or `[cko_checklist]` without a fixed `id` — in `post_content` or `_elementor_data`, by menu order then age. Cached in the transient `checkoff_detected_page` for a day, forgotten whenever a page is saved, trashed or deleted. `Checkoff_Checklist::page_url()` delegates to it, so the REST API, mails, the share menu and forms all follow. While nothing is chosen an admin notice (dashboard, plugins, CheckOff screens) says where links lead.

**Wording (2.0.0):** visitors see "link to the checklist" and "ID of the checklist", with a hint per level (`link_hint_<level>`); the levels are "Open / Protected / Private" ("Offen / Geschützt / Privat"; `level_open` is `_x( 'Open', 'access level' )` because "Open" the button is "Öffnen"), chosen in `accessDialog()`. **There is no "More actions" section any more (2.1.0):** whoever may edit gets icon buttons in `.cko-bar__end` beside the bell and the share button, in both modes — the lock (`.cko-lockdevice`, only with a password: lock on this device, a button of its own by the user's wish), the shield (`.cko-access`, label names the level; `openAccessMenu()` opens the access dialog directly, or — while `canList()`, i.e. protected with the directory on — a menu with it and show publicly / do not any more, showing asks first with `confirmDialog()`) and ⋯ (`openMoreMenu()`: clear all ticks, delete the checklist). The `.cko-more*` classes are gone. "Private" means the level, never an ID any more. Code and docs keep "public ID" and "tick ID"; an earlier tick link still calls its own ID "ID of the shared link". Icons per level in `LEVEL_ICONS` (`lock-open`, `lock`, `eye-off`).

`[cko_checklist]` embeds the checklist as JSON in `script.cko-data` (encoded with `JSON_HEX_*`, so a title cannot close the script element) and `assets/js/frontend.js` draws it. Text inputs update the model without redrawing (focus and caret stay); clicks redraw with focus restored by `data-focus` keys. The progress bar is computed in the browser (see *Repetition and rounds* for its server twin). The recently opened list is localStorage only (`cko_recent`). Assets are registered on `wp_enqueue_scripts` but enqueued only by the shortcodes (`Checkoff::enqueue_frontend_assets()`).

Pages carrying `?cko=` get `noindex, nofollow` (`Checkoff_Post_Types::noindex_checklist_views()`), because the address contains the secret.

### Security: quotas, visitors, headers, wp-admin

**Every hourly quota lives in `Checkoff_Quota`** and is taken in the one lookup both channels share (`request_checklist( $raw_id, $edit, $quota )`), or in the shared methods for creating (`create_checklist()`) and subscribing (`Checkoff_Subscriptions::request()`) — a quota checked in only one channel would be bypassed through the other. `take()` refuses with 429 `checkoff_quota_<action>` and `retry_after`, else counts; `refusal()` checks without counting.

| Action | Counts | Option (default, 0 = unlimited) |
|---|---|---|
| `create` | new checklists per visitor / **all visitors together** | `checkoff_create_per_hour` (10) / `checkoff_create_total_per_hour` (100) |
| `tick` | values written (set, clear, notes typed) | `checkoff_ticks_per_hour` (1200) |
| `edit` | structure saves, PATCH, reset, delete, history delete | `checkoff_edits_per_hour` (600) |
| `subscribe` | subscription requests | `checkoff_subscribe_per_hour` (5) |
| — | unknown IDs (then every lookup refused) | `checkoff_failed_lookups_per_hour` (20), in `lookup()` |
| `unlock` | wrong passwords (then every unlock refused); plus 50 per list from all visitors | `checkoff_unlock_per_hour` (10), in `Checkoff_Password::verify()` |

Reads (open, poll, history) take no quota. The page saves after a pause in typing (700 ms), which is why tick and edit quotas are generous: they stop scripts, not people. The AJAX handlers send `Retry-After` too (`send_retry_after()`).

**`Checkoff_Rate_Limit` only counts**, per visitor or for all visitors (`$all_visitors`), as a salted hash in a transient. The visitor (`visitor()`) is an IPv4 address or an **IPv6 /64** (one connection gets a whole /64; per address it would be no limit), from `checkoff_ip_source` — the connection by default, or X-Forwarded-For (first entry), X-Real-IP, CF-Connecting-IP for sites behind a proxy. A header is only trustworthy behind a proxy that sets it, which the setting's description says; an invalid value counts as `''` (all such requests share one count, erring towards limiting). Tests give every run its own IPv6 /64.

**Pages with `?cko=`** get `no-store` cache headers, `DONOTCACHEPAGE`/`DONOTCACHEOBJECT`/`DONOTCDN`, `Referrer-Policy: no-referrer` and `X-Robots-Tag` (`Checkoff_Post_Types::protect_checklist_views()`), besides the `noindex` robots meta: the address carries the secret.

**wp-admin is for administrators.** Both post types map every primitive capability to `Checkoff_Post_Types::admin_capability()` (`manage_options`, filter `checkoff_admin_capability`) and creating to `do_not_allow` (`admin_capabilities()`): with the post defaults every editor could read every visitor's IDs. Settings, the test mail and the log stay `manage_options`.

### REST API (`Checkoff_Rest_Api`)

Namespace `checkoff/v1`. Access by `checkoff_rest_access`: `public` (everybody, by the ID — the default), `users` (logged-in users only, 401 otherwise, via the routes' `permission()`) or `off` (no routes); a site from 1.6/1.7 still has `checkoff_rest_enabled`, which `access()` reads until the new setting is saved. Every answer of the namespace gets `Cache-Control: no-store`, `X-Robots-Tag: noindex`, `Referrer-Policy: no-referrer` and, on 429, `Retry-After` (`secure_response()` on `rest_post_dispatch`). **Everything the website does, by the same code — never a second implementation of a rule:**

| Route | Website twin |
|---|---|
| `GET /blocks`, `GET /templates` | the builder's block definitions, the template gallery |
| `POST /checklists` `{title, template \| structure}` | `cko_create`, through `Checkoff_Shortcodes::create_checklist()` (login setting, hourly limit) |
| `GET /checklists/{id}` | opening the page: `lookup()`, `touch()`, `to_client()` (+ `progress`) |
| `GET /checklists/{id}/state` | `cko_poll` — reads only, no visit, no rollover |
| `PUT …/structure`, `PATCH /checklists/{id}` | `cko_save_structure`, revision check → 409 |
| `PUT\|DELETE …/values/{entry}`, `DELETE …/values` | `cko_set_value`, `cko_reset_values` |
| `DELETE /checklists/{id}` | `cko_delete` |
| `POST\|DELETE …/unlock`, `PUT\|DELETE …/password`, `PUT …/access` | `cko_unlock`, `cko_lock`, `cko_set_password`, `cko_remove_password`, `cko_set_access` — through `Checkoff_Password::change()` |
| `PUT\|DELETE …/listed`, `GET /lists` | `cko_set_listed`, `[cko_lists]` — through `Checkoff_Directory` |
| `GET\|DELETE …/rounds` | `cko_history`, `cko_history_delete` — edit ID only, through `Checkoff_Shortcodes::history_data()` |
| `POST …/subscriptions` `{email, consent}` | `cko_subscribe`, through `Checkoff_Subscriptions::request()` |

No route checks a capability **on purpose**: the ID in the path is the authorisation, as `?cko=` is on the page (`permission()` only adds the site's choice of logged-in users only). The REST `checklist( $request, $edit, $quota )` and the page's `ajax_checklist()` both call **`Checkoff_Shortcodes::request_checklist()`** — the rate-limited `lookup()`, 403 `checkoff_tick_only` for writes beyond values, and the request's quota. Errors are `WP_Error`s with `status` — `lookup()`, `create_checklist()` and `request()` carry it themselves (`Checkoff_Shortcodes::error_status()` reads it for the AJAX answers), model errors get it in `model_error()` (409 conflict, 404 unknown entry). A structure is size-checked against `Checkoff_Shortcodes::MAX_PAYLOAD` like the page's (413). A rule added to an AJAX handler belongs in the shared method, or REST silently lacks it. Assertions in `tools/rest-test.php` (dispatched through `rest_do_request()`, a fresh fake IP per run so limits do not carry over).

### Templates (`Checkoff_Templates`)

A template is an ordinary checklist an administrator built on the website and marked in wp-admin (box "Template": `_cko_template` = '1', `_cko_template_desc`, `_cko_template_key`; for several at once the list's bulk edit offers "Template: no change / offer / do not offer" — `Checkoff_Post_Meta::render_bulk_edit()` in the ID column's slot, applied per post on `save_post` by `save_bulk_edit()` with its own nonce, keeping each description; quick edit stays removed). Visitors refer to it only by the key — never by post ID or one of its IDs, which would let them edit or tick the template itself. `create_from( $key, $title )` makes a new checklist with a copy of the structure (title of the copy or of the template, repetition restarting today) and nothing else: no values, no subscriptions; later changes to the template do not reach copies. Offered in the create form (`type` value `tpl:<key>`, beside "Empty checklist" — the form asks for no block type, since a checklist has none: an empty one starts as one untitled simple block and gets its building blocks while editing; without templates the form is name and button only; **per shortcode since 1.12.0** — `[cko_create templates="no"]` (and `[cko_app templates="no"]`, passed on) hides them, `template="…"` preselects, both together give a form without choice that always starts from that template; the former setting `checkoff_templates_in_form` has no field any more, but a stored `''` stays the default (`Checkoff_Templates::shown_by_default()`) so such sites do not change; yes/no attributes go through `Checkoff_Shortcodes::attribute_flag()`) and by `[cko_templates]`. A copy opens ready to use (`edit: false` in the create answer), an empty list in edit mode. The cleanup never deletes a template.

### Repetition and rounds

`Checkoff_Recurrence` holds the rule — `freq` (none|daily|weekly|monthly), `interval`, `start`, `end` (never|count|until), `count`, `until` — stored as `recurrence` on the **top-level block** of the structure, so it travels with structure saves and their revision; `sanitize_structure()` adds it to the root only. Invalid rules turn repetition off rather than guessing.

**Rounds are computed, never counted.** `current( $now )` derives the round in force from the rule and the clock (site time zone; a round runs from 00:00 of its first day to 00:00 of the next round's). Before the start the first round is in force; after the last it stays in force, `finished`. Monthly rounds keep the start's day of the month or take the month's last day (31 Jan → 28 Feb → 31 Mar).

**A new round starts empty, lazily.** Values carry the round they belong to (`_cko_values_round`); `get_values()` returns nothing while they are stale, and `rollover()` — called before every write, by `to_client()` and by the mail dispatch — keeps a summary of the closed round in `_cko_last_round` and empties the values. Reading alone never writes. Changing the rule moves the current values into the round in force under the new rule instead of dropping them.

**The history of rounds (`Checkoff_History`) is off by default** (`checkoff_history_enabled`; `checkoff_history_days`, default 90). With it on, `rollover()` calls `record()` before emptying the values, and every closed round **in which anything was entered** becomes one row `_cko_h_<number>` — values as shown **plus the structure at closing**, so a later rename, a reordered choice or a changed unit cannot falsify the past. Empty rounds are skipped because rounds nobody opened are never closed one by one; keeping the one that happened to be closed would show some empty days and not others. Reading (`rounds()`) skips expired rounds and words every entry through the block types of the snapshot (`describe_item()`); the daily cron `checkoff_history_prune` (`prune_all()`, scheduled only while on) deletes them, finding checklists through `_cko_h_oldest`. **Only the edit ID** sees it: `to_client()` adds `history {rounds, days}` for edit views only, the AJAX/REST handlers refuse a tick ID with 403. Switching the setting off deletes every kept round (`update_option_` hook → `delete_all()`), uninstall always deletes them (nothing would prune them any more), the owner can delete a checklist's history. The privacy text has a sentence for each state. The page shows it as `details.cko-history` below the card (loaded on first opening, `.cko-pastround` per round, a `sparkline()` per measured entry with at least two values). Assertions in `tools/checklist-test.php` (*history*) and `tools/rest-test.php`.

**Progress has two implementations, on purpose.** The server's is `Checkoff_Block_Type::progress()` per block (`Checkoff_Checklist::progress_of()` / `open_items_of()`), used for mails and summaries; the browser draws its bar with the same rule in `Checklist.prototype.progress`. Change both together. `describe_value()` on the block types is the one wording of a value (admin outline, mails).

### E-mail subscriptions (`Checkoff_Subscriptions`, `Checkoff_Mailer`, `Checkoff_Mail_Log`)

Built like the subscriptions of MixMyMeals, with one rule above everything: **store as little personal data as possible.**

- A subscription (`cko_subscription`, shown under the CheckOff menu, no edit screen) stores `_cko_sub_email`, `_cko_sub_checklist`, `_cko_sub_status` (pending|active), `_cko_sub_confirmed` (time of consent — the proof double opt-in needs) and `_cko_sub_sent` (send key → time). **No name, no IP, no user agent.** The post title is the masked address, so wp-admin never shows the full one. `tools/subscriptions-test.php` asserts the exact set of meta keys of a pending subscription — adding a field means changing that assertion knowingly, and the privacy text.
- **No token is stored.** Links carry `hash_hmac( sub ID | post_date_gmt | action, wp_salt )`; a deleted subscription's links stop working by themselves.
- **Unsubscribing deletes the post**, as do deleting the checklist, trashing the subscription, the privacy eraser and uninstalling the plugin. Unconfirmed subscriptions are deleted after `checkoff_subscription_pending_hours`. There is deliberately **no suppression list** — it would be a list of addresses kept after their owners left; double opt-in already stops third parties signing somebody up.
- **A GET never confirms or unsubscribes.** Mail scanners open links; the GET page shows a button whose POST acts. Mail clients' one-click unsubscribe (`List-Unsubscribe-Post`, RFC 8058) is a POST and works directly.
- The subscribe answer never tells whether an address was subscribed already. Limits: the `subscribe` quota per visitor (`Checkoff_Quota`), `checkoff_subscriptions_per_checklist`, and `Checkoff_Mailer::RATE_LIMIT_PER_HOUR` per address (filter `checkoff_mail_rate_limit_per_hour`).

**Sending rules** (`checkoff_mail_rules`, defaults in `default_rules()` until the option is saved): trigger + time + subject + template + `only_open`. Triggers: `on_confirm` (sent by `confirm()`), `each_day`, `round_start`, `round_last_day`, `round_end`, `series_end`. `occurrences()` gives the latest occurrence per rule; the 15-minute cron (`checkoff_mail_dispatch`) sends it once it is past, after the confirmation, and not more than `GRACE` (a day) late — a site that was down for a week sends no backlog. Sent occurrences are remembered under `trigger|time|template|occurrence`, so editing a subject does not resend. `only_open` settles a reminder without mailing when everything is done. `dispatch( $now )` takes the time as a parameter and threads it through rollover, progress and the mail contents — tests depend on that.

**Mails are templates**, as in MixMyMeals: `templates/emails/*.html` with `{{ placeholders }}` (escaped by suffix: `_html` → `wp_kses_post`, `_url` → `esc_url`, else `esc_html`), wrapped in `layout.html`; `confirm.html` is the double opt-in mail (subject setting `checkoff_confirm_subject`). Lookup: child theme → parent theme → plugin, each asking `<dir>/<locale>/` first — that is how the mails are translated (`templates/emails/de_DE/`). Template comments are stripped before sending. `mail_vars()` builds every placeholder for every mail; the test button fills them from `demo_data()`, a made-up checklist, so no visitor's list lands in an admin inbox.

**Send log** (`checkoff_mail_log`, opt-in): time, masked address, subject, status; dropped after `checkoff_mail_log_days`, emptied when switched off.

### Settings

Tabs General (incl. live update interval and public checklists), Building blocks, Limits, E-mail, Maintenance under CheckOff → Settings (`edit.php?post_type=cko_checklist&page=checkoff_settings`). Fields are declared in `Checkoff_Settings::settings_fields()` (filter `checkoff_settings_fields`) and rendered by type in `Checkoff_Admin_API::display_field()` (filter `checkoff_render_settings_field`). Every reader passes its default to `get_option()`, since `register_setting()` defaults only exist during admin requests.

### Cleanup and uninstall

`Checkoff_Cleanup` deletes checklists unused for `checkoff_cleanup_inactive_days` (off by default) via daily cron. `uninstall.php` always removes options (send log included), transients, cron events and **every subscription** (without the plugin nobody could unsubscribe), and deletes checklists only with `checkoff_delete_data_on_uninstall`.

The privacy text (`Checkoff_Privacy::policy_text()`) follows the settings: the subscription paragraphs appear only while subscriptions are on, the log sentence only while the log is on. Anything that changes what is stored about a person changes that text.
