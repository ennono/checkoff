=== CheckOff ===
Contributors: frickemedia
Tags: checklist, todo, habit tracker, rating, shortcode
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 8.0
Stable tag: 1.5.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Let visitors build their own multi-level checklists from building blocks and tick them off.

== Description ==

CheckOff lets the visitors of your site put together their own checklists —
as deep and as varied as they need — save them, and tick them off. No
account is needed: every checklist gets a random ID, and that ID is the only
way to find it again.

= Building blocks =

A checklist has no type of its own: it is a page of building blocks stacked
one after the other, each shown as a section with an optional heading — a
simple list, then a weekday list, then a rating, then notes. Every block can
also hold sub-lists of any type, shown framed and indented within it.

* **Simple checklist** — entries to tick off. Can contain sub-lists of any
  type, so lists can be nested.
* **Checklist with "not needed"** — tick off, or mark an entry as not needed
  this time; it then leaves the progress (packing and inspection lists).
* **Checklist with due dates** — every entry has a date; overdue and soon
  due entries are marked, and the mails name them.
* **Checklist with weekdays** — tick every entry per weekday; optionally
  starts empty every week (habits, cleaning rota).
* **Rating with colour scale** — rate entries on three or five colours
  (colours and names configurable).
* **Rating with numbers** — rate entries with a number, e.g. 1 to 5.
* **Measurement** — a number with a unit (weight, meter reading), optionally
  with a target range that decides when an entry is done.
* **Choice** — one of your own answers per entry, e.g. yes / no / maybe.
* **Counter** — count entries up to a target (glasses of water).
* **Notes** — text fields, not counted towards the progress.

Add-ons can register further blocks through the `checkoff_block_types` filter.

= Editing =

One button switches between ticking and editing. Titles and entries are
edited in place; Enter adds the next entry, Backspace on an empty one removes
it. Entries and sub-lists can be moved, duplicated and deleted, a sub-list's
building block and settings changed. Everything is saved automatically.
Two open tabs cannot overwrite each other: the second one is told to reload.

= Sharing =

Every checklist has two IDs: one that edits (and deletes) it, and one that
only ticks it — the link to hand to children, flatmates or a cleaning crew.
Somebody holding the tick link never learns the other ID. Open checklists
update themselves when somebody ticks on another device, and two people
ticking at the same moment never erase each other's ticks.

= Templates =

Build a checklist on the website, mark it as a template in wp-admin, and
visitors can start from it: in the create form or in a gallery
(`[cko_templates]`). They get their own copy — entries, settings and
repetition (starting today), no ticks — and later changes to the template
do not touch it.

= Repetition =

A checklist can repeat daily, weekly or monthly, every so many days, weeks
or months from a start date — forever, a number of times, or until a date.
Every round starts with all ticks cleared; of the previous round only a
summary is kept (how much was done, what stayed open).

= E-mail subscriptions =

Visitors can subscribe to a checklist with their e-mail address (double
opt-in). Which mails are sent and when is configured as sending rules — a
trigger (on confirmation, every day, when a round starts, on its last day,
after it ended, after the last round), a time, a subject and a template —
each with a test button. Templates are HTML files with placeholders inside a
shared layout, overridable from the theme (`<theme>/checkoff/emails/`) and
per language (`de_DE/`).

As little personal data as possible: only the address, the checklist and
the time of confirmation are stored — no name, no IP address. Links carry a
derived token, nothing is stored for them. Unsubscribing (also one click
from the mail client) deletes the subscription immediately; unconfirmed
ones are deleted after a few hours; wp-admin shows addresses masked only.
An optional send log keeps masked addresses for a limited number of days.

= Shortcodes =

* `[cko_app]` — everything on one page: the checklist when the address
  carries an ID (`?cko=…`), otherwise the forms to create and open one and
  the checklists recently opened on this device.
* `[cko_checklist]` / `[cko_checklist id="abcd-efgh-jkmn"]` — one checklist.
* `[cko_create title="" button=""]` — form to create a checklist.
* `[cko_open title="" button=""]` — form to open a checklist by its ID.
* `[cko_templates title="" button=""]` — the site's templates as cards.
* `[cko_create template="…"]` preselects a template in the create form.
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

== Third-party libraries ==

Bundled with the plugin and loaded from the site itself, never from a CDN:

* SortableJS 1.15.6 — MIT licence — https://github.com/SortableJS/Sortable
* Lucide icons 0.460.0 (37 icons) — ISC licence — https://lucide.dev

== Changelog ==

= 1.5.0 =
* New building block: checklist with "not needed" — entries that do not apply leave the progress.
* New building block: checklist with due dates — overdue and soon due entries are marked, also in the mails.
* New building block: measurement — a number with a unit, optionally with a target range.
* New building block: choice — one of your own answers per entry.

= 1.4.1 =
* The create form no longer asks for a block type: a new checklist starts empty, or from a template where the site offers some.

= 1.4.0 =
* New look: cards, icons, round checkboxes, a progress bar per section and a Tick off / Edit switch.
* Editing in place: type entries one after another, drag entries and building blocks to sort them, settings and actions in compact menus.
* Deleting can be undone for a few seconds; resetting and deleting the checklist ask first.
* Share menu with the link for ticking, the link for editing and the ID, each with a copy button.
* Unaffected by the theme's button and field styles.

= 1.3.0 =
* A checklist is a stack of building blocks of any type, shown as headed sections without frames.
* Every building block can hold sub-lists, not only the simple checklist.
* Existing checklists are converted automatically; ticks, IDs and subscriptions are kept.

= 1.2.0 =
* Link for ticking only, besides the ID that edits.
* Live update of open checklists from other devices (configurable interval).
* Every tick is stored on its own, so simultaneous ticks are never lost.
* Templates: checklists the site offers as a starting point, in the create form and as a gallery.

= 1.1.0 =
* Repeating checklists (daily, weekly, monthly; forever, a number of times or until a date).
* E-mail subscriptions with double opt-in and configurable sending rules, templates, sender and footer.
* Optional send log with masked addresses.

= 1.0.0 =
* First release.
