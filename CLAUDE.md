# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

CheckOff is a WordPress plugin (v1.2.0, text domain `checkoff`) that lets visitors build their own multi-level checklists from building blocks and tick them off. Structure and implementation follow the sister plugin **MixMyMeals** (`ennono/mixmymeals`): singleton main class, a settings class with tabs, an admin API that renders the fields, one class per concern in `includes/lib/`, shortcodes and their AJAX handlers in one class, jQuery frontend with a debug and a `.min.js` flavour, catalogue in `lang/`.

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
| `_cko_tick_id` | the random ID that only ticks (created lazily for older lists) |
| `_cko_structure` | the block tree |
| `_cko_v_<leaf ID>` | one row per entry: `array( 'v' => value )` |
| `_cko_revision` | counted up on every structure save |
| `_cko_last_access` | unix time, written at most once an hour on views |
| `_cko_values_round` | the round (0-based) the stored values belong to |
| `_cko_last_round` | summary of the last closed round: number, done, total, open entries (capped) |

**The public ID is the only way in.** `Checkoff_Public_Id` generates it (alphabet without 0/o/1/l/i, length setting, default 12 ≈ 59 bits), normalises input (case, spaces, dashes) and formats it in groups of four. The post ID never reaches the frontend — `Checkoff_Checklist::to_client()` is what the browser gets. Failed lookups are rate-limited per visitor (`Checkoff_Rate_Limit`, salted IP hash in a transient); once the limit is reached every lookup is refused for the hour, correct ones included, or the refusal would tell a guesser when they hit.

**Two IDs, two access levels.** `find()` looks an ID up as public **or** tick ID (`unique_public_id()` keeps both kinds unique together) and returns the checklist with `get_access()` = `edit` or `tick`. The tick ID may view, tick, poll and subscribe; `ajax_checklist( true )` refuses it for structure saves, clearing all ticks and deleting (403). `to_client()` gives a tick view only its own ID — never `tick_id`/`tick_url`, which an edit view gets to hand out. A subscription made through the tick link carries `_cko_sub_tick_only`, so its mails link to the tick link (`checklist_of()` restores the access).

**One row per value.** Each entry's value is its own meta row (`_cko_v_<id>`, wrapped as `array( 'v' => … )` because WordPress stores a bare scalar as text and `true` would come back as `"1"`). A tick writes only its row — with one array, two people ticking at once each wrote back the array and the second erased the first. `store_values()` replaces the whole set (revalidation, reset, rollover) and leaves unchanged rows alone. Checklists from before 1.2.0 have `_cko_values` (one array); `migrate_values()` moves it into rows on first read.

**Live update.** `sync_state()` is the one place computing what an open page shows besides the structure (values, round, last round) plus an md5 fingerprint of it with the revision; `to_client()` and `ajax_poll()` both use it. The page polls `cko_poll` every `checkoff_sync_interval` seconds (default 20, 0 = off) while visible and not busy (unsaved edits, a save running, a note being typed, focus in a text field); an unchanged fingerprint is answered with the fingerprint alone, the structure is sent only for an older revision, and values of the page's own requests still in flight win over the answer. A poll only reads: it closes no round and does not count as a visit.

**Structure and values are separate on purpose.** A tick sends one leaf (`cko_set_value`); an edit sends the whole tree (`cko_save_structure`) with the revision it was based on, and a stale revision is answered with 409 so two tabs cannot overwrite each other. On every structure save the values are re-validated: values of removed leaves go, the rest are checked against the (possibly changed) block settings via `revalidate_value()`.

**Tree format.** Block: `{id, kind:"block", type, title, description, settings, items[]}`; leaf: `{id, kind:"item", label}`. Only block types with `supports_children()` may hold blocks among their items. `Checkoff_Checklist::sanitize_structure()` validates on write **and on read**: unknown types become `simple`, unsupported children are dropped, duplicate/invalid IDs are replaced, depth (`checkoff_max_depth`) and size (`checkoff_max_nodes`) are enforced by dropping the excess rather than refusing the save.

### Building blocks

`includes/lib/blocks/`: abstract `Checkoff_Block_Type` plus one class per block (`simple`, `weekdays`, `color_scale`, `number_scale`, `counter`, `note`). A block class is the only place that knows its settings and allowed values (`default_settings`, `settings_schema`, `sanitize_settings`, `sanitize_value`, `revalidate_value`, `value_for_display`). `Checkoff_Block_Types` is the registry (filter `checkoff_block_types`), knows which blocks are switched on (`checkoff_block_<key>_enabled`, default on; `simple` always) and hands the definitions to the frontend, so the builder never offers what the server would refuse.

**Never rename a block key** — stored checklists refer to it.

Weekdays with the weekly reset store `{days, week:"2026-W45"}`; the week is always set by the server, `value_for_display()` hides last week's ticks without deleting them, and `revalidate_value()` keeps the stored week so re-saving the structure cannot move old ticks into the current week.

### Frontend

`[cko_checklist]` embeds the checklist as JSON in `script.cko-data` (encoded with `JSON_HEX_*`, so a title cannot close the script element) and `assets/js/frontend.js` draws it. Text inputs update the model without redrawing (focus and caret stay); clicks redraw with focus restored by `data-focus` keys. The progress bar is computed in the browser (see *Repetition and rounds* for its server twin). The recently opened list is localStorage only (`cko_recent`). Assets are registered on `wp_enqueue_scripts` but enqueued only by the shortcodes (`Checkoff::enqueue_frontend_assets()`).

Pages carrying `?cko=` get `noindex, nofollow` (`Checkoff_Post_Types::noindex_checklist_views()`), because the address contains the secret.

### Templates (`Checkoff_Templates`)

A template is an ordinary checklist an administrator built on the website and marked in wp-admin (box "Template": `_cko_template` = '1', `_cko_template_desc`, `_cko_template_key`). Visitors refer to it only by the key — never by post ID or one of its IDs, which would let them edit or tick the template itself. `create_from( $key, $title )` makes a new checklist with a copy of the structure (title of the copy or of the template, repetition restarting today) and nothing else: no values, no subscriptions; later changes to the template do not reach copies. Offered in the create form (`type` value `tpl:<key>`, setting `checkoff_templates_in_form`, `[cko_create template="…"]` preselects) and by `[cko_templates]`. A copy opens ready to use (`edit: false` in the create answer), an empty list in edit mode. The cleanup never deletes a template.

### Repetition and rounds

`Checkoff_Recurrence` holds the rule — `freq` (none|daily|weekly|monthly), `interval`, `start`, `end` (never|count|until), `count`, `until` — stored as `recurrence` on the **top-level block** of the structure, so it travels with structure saves and their revision; `sanitize_structure()` adds it to the root only. Invalid rules turn repetition off rather than guessing.

**Rounds are computed, never counted.** `current( $now )` derives the round in force from the rule and the clock (site time zone; a round runs from 00:00 of its first day to 00:00 of the next round's). Before the start the first round is in force; after the last it stays in force, `finished`. Monthly rounds keep the start's day of the month or take the month's last day (31 Jan → 28 Feb → 31 Mar).

**A new round starts empty, lazily.** Values carry the round they belong to (`_cko_values_round`); `get_values()` returns nothing while they are stale, and `rollover()` — called before every write, by `to_client()` and by the mail dispatch — keeps a summary of the closed round in `_cko_last_round` and empties the values. Reading alone never writes. Changing the rule moves the current values into the round in force under the new rule instead of dropping them.

**Progress has two implementations, on purpose.** The server's is `Checkoff_Block_Type::progress()` per block (`Checkoff_Checklist::progress_of()` / `open_items_of()`), used for mails and summaries; the browser draws its bar with the same rule in `Checklist.prototype.progress`. Change both together. `describe_value()` on the block types is the one wording of a value (admin outline, mails).

### E-mail subscriptions (`Checkoff_Subscriptions`, `Checkoff_Mailer`, `Checkoff_Mail_Log`)

Built like the subscriptions of MixMyMeals, with one rule above everything: **store as little personal data as possible.**

- A subscription (`cko_subscription`, shown under the CheckOff menu, no edit screen) stores `_cko_sub_email`, `_cko_sub_checklist`, `_cko_sub_status` (pending|active), `_cko_sub_confirmed` (time of consent — the proof double opt-in needs) and `_cko_sub_sent` (send key → time). **No name, no IP, no user agent.** The post title is the masked address, so wp-admin never shows the full one. `tools/subscriptions-test.php` asserts the exact set of meta keys of a pending subscription — adding a field means changing that assertion knowingly, and the privacy text.
- **No token is stored.** Links carry `hash_hmac( sub ID | post_date_gmt | action, wp_salt )`; a deleted subscription's links stop working by themselves.
- **Unsubscribing deletes the post**, as do deleting the checklist, trashing the subscription, the privacy eraser and uninstalling the plugin. Unconfirmed subscriptions are deleted after `checkoff_subscription_pending_hours`. There is deliberately **no suppression list** — it would be a list of addresses kept after their owners left; double opt-in already stops third parties signing somebody up.
- **A GET never confirms or unsubscribes.** Mail scanners open links; the GET page shows a button whose POST acts. Mail clients' one-click unsubscribe (`List-Unsubscribe-Post`, RFC 8058) is a POST and works directly.
- The subscribe answer never tells whether an address was subscribed already. Limits: `SUBSCRIBE_PER_HOUR` per visitor (salted IP hash, `Checkoff_Rate_Limit`), `checkoff_subscriptions_per_checklist`, and `Checkoff_Mailer::RATE_LIMIT_PER_HOUR` per address (filter `checkoff_mail_rate_limit_per_hour`).

**Sending rules** (`checkoff_mail_rules`, defaults in `default_rules()` until the option is saved): trigger + time + subject + template + `only_open`. Triggers: `on_confirm` (sent by `confirm()`), `each_day`, `round_start`, `round_last_day`, `round_end`, `series_end`. `occurrences()` gives the latest occurrence per rule; the 15-minute cron (`checkoff_mail_dispatch`) sends it once it is past, after the confirmation, and not more than `GRACE` (a day) late — a site that was down for a week sends no backlog. Sent occurrences are remembered under `trigger|time|template|occurrence`, so editing a subject does not resend. `only_open` settles a reminder without mailing when everything is done. `dispatch( $now )` takes the time as a parameter and threads it through rollover, progress and the mail contents — tests depend on that.

**Mails are templates**, as in MixMyMeals: `templates/emails/*.html` with `{{ placeholders }}` (escaped by suffix: `_html` → `wp_kses_post`, `_url` → `esc_url`, else `esc_html`), wrapped in `layout.html`; `confirm.html` is the double opt-in mail (subject setting `checkoff_confirm_subject`). Lookup: child theme → parent theme → plugin, each asking `<dir>/<locale>/` first — that is how the mails are translated (`templates/emails/de_DE/`). Template comments are stripped before sending. `mail_vars()` builds every placeholder for every mail; the test button fills them from `demo_data()`, a made-up checklist, so no visitor's list lands in an admin inbox.

**Send log** (`checkoff_mail_log`, opt-in): time, masked address, subject, status; dropped after `checkoff_mail_log_days`, emptied when switched off.

### Settings

Tabs General (incl. templates in the form, live update interval), Building blocks, Limits, E-mail, Maintenance under CheckOff → Settings (`edit.php?post_type=cko_checklist&page=checkoff_settings`). Fields are declared in `Checkoff_Settings::settings_fields()` (filter `checkoff_settings_fields`) and rendered by type in `Checkoff_Admin_API::display_field()` (filter `checkoff_render_settings_field`). Every reader passes its default to `get_option()`, since `register_setting()` defaults only exist during admin requests.

### Cleanup and uninstall

`Checkoff_Cleanup` deletes checklists unused for `checkoff_cleanup_inactive_days` (off by default) via daily cron. `uninstall.php` always removes options (send log included), transients, cron events and **every subscription** (without the plugin nobody could unsubscribe), and deletes checklists only with `checkoff_delete_data_on_uninstall`.

The privacy text (`Checkoff_Privacy::policy_text()`) follows the settings: the subscription paragraphs appear only while subscriptions are on, the log sentence only while the log is on. Anything that changes what is stored about a person changes that text.
