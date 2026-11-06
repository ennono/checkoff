=== CheckOff ===
Contributors: frickemedia
Tags: checklist, todo, habit tracker, rating, shortcode
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 8.0
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Let visitors build their own multi-level checklists from building blocks and tick them off.

== Description ==

CheckOff lets the visitors of your site put together their own checklists —
as deep and as varied as they need — save them, and tick them off. No
account is needed: every checklist gets a random ID, and that ID is the only
way to find it again.

= Building blocks =

* **Simple checklist** — entries to tick off. Can contain sub-lists of any
  type, so lists can be nested.
* **Checklist with weekdays** — tick every entry per weekday; optionally
  starts empty every week (habits, cleaning rota).
* **Rating with colour scale** — rate entries on three or five colours
  (colours and names configurable).
* **Rating with numbers** — rate entries with a number, e.g. 1 to 5.
* **Counter** — count entries up to a target (glasses of water).
* **Notes** — text fields, not counted towards the progress.

Add-ons can register further blocks through the `checkoff_block_types` filter.

= Editing =

One button switches between ticking and editing. Titles and entries are
edited in place; Enter adds the next entry, Backspace on an empty one removes
it. Entries and sub-lists can be moved, duplicated and deleted, a sub-list's
building block and settings changed. Everything is saved automatically.
Two open tabs cannot overwrite each other: the second one is told to reload.

= Shortcodes =

* `[cko_app]` — everything on one page: the checklist when the address
  carries an ID (`?cko=…`), otherwise the forms to create and open one and
  the checklists recently opened on this device.
* `[cko_checklist]` / `[cko_checklist id="abcd-efgh-jkmn"]` — one checklist.
* `[cko_create title="" button=""]` — form to create a checklist.
* `[cko_open title="" button=""]` — form to open a checklist by its ID.
* `[cko_recent title=""]` — checklists recently opened on this device.

= Privacy =

Checklists are tied to a random ID, not to a person. Rate limits use a
salted hash of the IP address, kept for one hour at most. The list of
recently opened checklists lives in the visitor's browser only. A suggested
privacy policy text is added under Settings → Privacy.

== Installation ==

1. Upload the plugin and activate it.
2. Create a page with the shortcode `[cko_app]`.
3. Choose that page under CheckOff → Settings → General → Checklist page.

== Changelog ==

= 1.0.0 =
* First release.
