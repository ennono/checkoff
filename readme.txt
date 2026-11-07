=== CheckOff ===
Contributors: frickemedia
Tags: checklist, todo, habit tracker, rating, shortcode
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 8.0
Stable tag: 1.12.0
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
  (colours and names configurable for the site; per block, own names for
  the steps and a reversed scale, e.g. green = free of pain).
* **Rating with numbers** — rate entries with a number, e.g. 1 to 5.
* **Measurement** — a number with a unit (weight, meter reading), optionally
  with a target range that decides when an entry is done.
* **Choice** — one of your own answers per entry, e.g. yes / no / maybe.
* **Switch** — either/or: two variants of entries (training at the studio
  or at home) and a switch for which one applies; only that one is shown
  and counts, the other keeps its ticks.
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

Every checklist has two IDs. Visitors see them as **your private ID** (or
private link), which edits and deletes the list, and the **link to share**,
which only ticks — the one to hand to children, flatmates or a cleaning
crew. Somebody holding the link to share never learns the private ID.

A password adds extra protection to the private ID (optional). Then the
private ID alone only opens and ticks the list; editing, clearing and
deleting need the password too. Unlocking is remembered on the device for
ten days and can be ended with "Lock on this device"; changing the password
ends it everywhere. Only a hash of the password is stored (WordPress's own,
as for user passwords). An administrator can remove a forgotten password in
wp-admin.

Open checklists update themselves when somebody ticks on another device,
and two people ticking at the same moment never erase each other's ticks.

= Templates =

Build a checklist on the website, mark it as a template in wp-admin, and
visitors can start from it: in the create form (unless the shortcode says
`templates="no"`) or in a gallery (`[cko_templates]`). They get their own copy — entries, settings and
repetition (starting today), no ticks — and later changes to the template
do not touch it.

= Repetition =

A checklist can repeat daily, weekly or monthly, every so many days, weeks
or months from a start date — forever, a number of times, or until a date.
Every round starts with all ticks cleared; of the previous round only a
summary is kept (how much was done, what stayed open).

Optionally (Settings → General → History of rounds, off by default) the
ticks and values of closed rounds are kept for a number of days (default
90): below the checklist, every round with its entries and measurements
as a line chart — only for somebody with the ID that edits, who can also
delete the history. Rounds in which nothing was entered are not kept.
Switching the setting off deletes every kept round.

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
  the checklists recently opened on this device. Takes `templates="no"`
  for its create form.
* `[cko_checklist]` / `[cko_checklist id="abcd-efgh-jkmn"]` — one checklist.
* `[cko_create title="" button="" templates="" template=""]` — form to
  create a checklist. It offers the site's templates; `templates="no"`
  hides them. `template="…"` preselects one — together with
  `templates="no"` the form offers no choice and always starts from that
  template (e.g. a "New moving checklist" button).
* `[cko_open title="" button=""]` — form to open a checklist by its ID.
* `[cko_templates title="" button=""]` — the site's templates as cards.
* `[cko_recent title=""]` — checklists recently opened on this device.

= REST API =

Everything the website does with checklists also works under
`/wp-json/checkoff/v1/` (Settings → General → REST API: everybody — the
default —, logged-in users only, or off). Access works exactly as on the website, by the same code: the ID
in the address is the key, no login and no nonce needed; unknown IDs count
against the caller's hourly limit; a tick ID only reads, ticks and
subscribes. Creating follows the setting "Guests may create checklists"
and the hourly limit; logged in means any REST authentication, e.g. an
application password. IDs may be sent with or without dashes, in any case.

* `GET /blocks` — the building blocks, which are offered, the size limits.
* `GET /templates` — the templates (key, title, description, counts).
* `POST /checklists` — `{title?, template?}` or `{title?, structure}`:
  creates a checklist, empty, as a copy of a template or with a whole
  structure in one call. 201 with the checklist, both IDs included.
* `GET /checklists/{id}` — the checklist: structure, values, revision,
  round, last round, progress, state, access, URL (and, with the edit ID,
  the tick ID and tick URL). Counts as a visit.
* `GET /checklists/{id}/state?state=…&revision=…` — live update: with an
  unchanged fingerprint only `{state, changed:false}`, otherwise values,
  progress and round, the structure only for an older revision. Read only.
* `PUT /checklists/{id}/structure` — `{structure, revision}`: saves the
  whole tree (format as GET returns it). 409 when `revision` is stale.
* `PATCH /checklists/{id}` — `{revision, title?, description?,
  recurrence?}`: the same save without sending the tree.
* `PUT /checklists/{id}/values/{entry}` — `{value}`; `null` clears.
  `DELETE` on the same address clears too. Answers with the stored value
  and the progress.
* `DELETE /checklists/{id}/values` — clears every value.
* `DELETE /checklists/{id}` — deletes the checklist and its subscriptions.
* `POST /checklists/{id}/unlock` — `{password}`: unlocks a protected list
  for the browser (cookie); `DELETE` locks it again. API clients can send
  the header `X-CheckOff-Password` with each request instead.
* `PUT /checklists/{id}/password` — `{password}` sets or changes it,
  `DELETE` removes it; on a protected list only when unlocked.
* `GET /checklists/{id}/rounds` — the kept rounds, newest first, each with
  dates, progress and its entries (value, wording, done); `DELETE` deletes
  them. Edit ID only.
* `POST /checklists/{id}/subscriptions` — `{email, consent:true}`: sends
  the confirmation mail (202). Confirming stays in the mail.

Values per building block (anything else clears the entry, as on the page;
a switch's position is set under the switch block's own ID, as the index of
the variant, `null` for the first):
simple and due dates `true`; "not needed" `"done"` or `"skip"`; weekdays
`{"days":[1,3,5]}` (ISO weekdays, the week is set by the server); colour
scale the position from 0, counted from the left (block settings `steps`
3 or 5, `labels` — own names left to right, missing ones are the site's —
and `reverse`, green first; `GET /blocks` lists every block's settings); number rating and counter a whole number;
measurement a number (decimal comma accepted); choice the answer's index
from 0; notes text. A due date is set in the structure:
`{"id":…,"kind":"item","label":…,"data":{"due":"2026-12-01"}}`.

Errors are WordPress REST errors with `code`, `message` and the HTTP status:
400 invalid, 401 login required, 403 tick ID or not allowed, 404 unknown ID
or entry, 409 conflict, 413 too large, 429 quota used up (with a
`Retry-After` header). The website's quotas apply.

    curl -X POST https://example.com/wp-json/checkoff/v1/checklists \
      -H 'Content-Type: application/json' -d '{"title":"Shopping"}'
    curl -X PUT https://example.com/wp-json/checkoff/v1/checklists/abcd-efgh-jkmn/values/k3x9p2mq \
      -H 'Content-Type: application/json' -d '{"value":true}'

As on the website, the ID is part of the address, so it ends up wherever
addresses are logged; use HTTPS.

= Security =

* **Quotas** per visitor and hour, set under Settings → Limits: new
  checklists (plus a total for all visitors together), ticks, changes,
  subscription requests and unknown IDs. They apply to the website and the
  REST API alike; a refused request gets HTTP 429 with Retry-After.
* **Visitors** are told apart by IPv4 address or IPv6 /64 network. Behind a
  proxy or CDN the address can come from its header (X-Forwarded-For,
  X-Real-IP, CF-Connecting-IP) — only there, since elsewhere visitors could
  set the header themselves.
* **The ID is the only way in**: 59 bits by default, unknown IDs limited
  per hour (then every lookup is refused, so a hit is not even noticed), a
  separate ID that only ticks.
* **Pages showing a checklist** are not cached, not indexed and do not pass
  their address on as a referrer; REST answers likewise.
* **Passwords** (optional per checklist): stored as WordPress password
  hashes, never in plain text; wrong ones limited per visitor and per list.
* **REST API**: everybody (like the website), logged-in users only, or off.
* **wp-admin**: checklists and subscriptions are visible to administrators
  only (filter `checkoff_admin_capability`).

= Privacy =

Checklists are tied to a random ID, not to a person. Rate limits use a
salted hash of the IP address, kept for one hour at most. The list of
recently opened checklists lives in the visitor's browser only. A suggested
privacy policy text is added under Settings → Privacy.

== Installation ==

1. Upload the plugin and activate it.
2. Create a page with the shortcode `[cko_app]` (an Elementor shortcode
   widget will do).
3. Choose that page under CheckOff → Settings → General → Checklist page.
   Until you do, links lead to the first published page with `[cko_app]` or
   `[cko_checklist]`, and wp-admin shows a notice.

= For theme developers =

CheckOff draws everything with its own scoped styles and reads every colour,
radius and spacing from custom properties. Set them on the containers
(`.cko-app, .cko-create, .cko-open, .cko-recent, .cko-templates, .cko-menu,
.cko-toasts, .cko-dialog`) with a selector stronger than one class, e.g.
`body .cko-app { … }`. Menus, toasts and dialogs sit outside `.cko-app`, so
include them.

Colours (defaults in brackets):

* `--cko-accent` (`#2563eb`) — buttons, progress bars, selected answers,
  focus rings: the accent as a **surface**.
* `--cko-accent-contrast` (`#fff`) — text and icons **on** accent surfaces:
  primary buttons, a chosen number or answer.
* `--cko-accent-text` (the accent) — the accent as **text or icon colour** on
  light surfaces: the active switch, type icons, the chip of the round, the
  settings button while open, the repetition summary, the picker's and the
  forms' icons. With a light accent (e.g. `#E8C547`) set a darker shade here
  and a dark `--cko-accent-contrast`, or these become unreadable.
* `--cko-done` (`#16a34a`), `--cko-danger` (`#dc2626`), `--cko-warn`
  (`#b45309`) — done, deleting/overdue, due soon/out of range.
* `--cko-border`, `--cko-line`, `--cko-surface`, `--cko-hover`,
  `--cko-muted` — mixed from the text colour, so they suit light and dark
  themes.

Shapes: `--cko-radius` (12px; cards, sections), `--cko-radius-sm` (8px;
panels, fields), `--cko-control` (2.5rem; height of buttons and fields),
`--cko-gap`, `--cko-indent`, `--cko-shadow`.

Surfaces — not set by default, each box keeps its own look until you do;
then all boxes concerned share the value:

* `--cko-card-bg`, `--cko-card-border` (a border shorthand, e.g.
  `1px solid #ddd` or `none`) and `--cko-card-padding` (one length) — the
  checklist card, "More actions", the history and the subscription panel.
* `--cko-panel-bg`, `--cko-panel-border` — the settings and repetition
  panels and the building blocks while editing.

Example — a mustard brand colour:

    body .cko-app, body .cko-menu, body .cko-dialog,
    body .cko-create, body .cko-templates, body .cko-recent {
        --cko-accent: #E8C547;
        --cko-accent-contrast: #30323D;
        --cko-accent-text: #6B5300;
        --cko-card-bg: #fff;
        --cko-card-padding: 1.5rem;
    }

== Third-party libraries ==

Bundled with the plugin and loaded from the site itself, never from a CDN:

* SortableJS 1.15.6 — MIT licence — https://github.com/SortableJS/Sortable
* Lucide icons 0.460.0 (45 icons) — ISC licence — https://lucide.dev

== Changelog ==

= 1.12.0 =
* Whether the create form shows the templates is now set per shortcode — `[cko_create templates="no"]`, also `[cko_app templates="no"]` — instead of in the settings. A site that had switched the setting off keeps that as the default.
* `[cko_create templates="no" template="…"]` makes a form that always starts from one template.

= 1.11.0 =
* Fields in the block settings, entry labels and block titles no longer overflow or get squeezed on phones.
* New `--cko-accent-text` for the accent as text or icon colour, so light brand colours stay readable; `--cko-accent-contrast` is used wherever text sits on the accent.
* New surface variables `--cko-card-bg`, `--cko-card-border`, `--cko-card-padding`, `--cko-panel-bg`, `--cko-panel-border` — unset by default, nothing changes until a theme sets them.
* Template gallery: wider columns, buttons wrap instead of leaving the card; create form: long template titles wrap and hyphenate, one column where space is short.
* Without a chosen checklist page, links lead to the first published page with `[cko_app]` or `[cko_checklist]` (also in Elementor) instead of the front page; wp-admin shows a notice until a page is chosen.
* Colour scale per block: own names for the steps and a reversed scale.
* Clearer words for sharing: "link to share", "your private ID", the password as extra protection.
* The ID in "Recently opened" stays on one line.

= 1.10.1 =
* The block picker no longer overflows: it is wider, wraps its texts (also where the theme forbids wrapping in buttons) and, on small screens, stays on the screen and scrolls.

= 1.10.0 =
* New building block: switch — either/or between variants of entries (e.g. studio / at home); only the variant in force is shown and counts.

= 1.9.0 =
* Optional password per checklist: who knows it edits and deletes, everybody with the ID still opens and ticks. Stored as a WordPress password hash; unlocked per device by a cookie for ten days; wrong passwords limited per visitor and per list.
* REST: unlock (or the header X-CheckOff-Password), set and remove the password.
* wp-admin: lock icon in the list, an administrator can remove a forgotten password.
* "More actions" stays open while working in it.

= 1.8.0 =
* Quotas per visitor and hour for ticking, changing and subscribing, and for new checklists of all visitors together — on the website and the REST API alike, with Retry-After.
* Visitors behind a proxy or CDN can be told apart by its header; IPv6 counts per /64 network.
* Pages showing a checklist and REST answers are not cached, not indexed and pass no referrer.
* REST API: everybody, logged-in users only, or off.
* wp-admin: checklists and subscriptions for administrators only (editors could read visitors' IDs before).

= 1.7.0 =
* History of rounds (off by default): the ticks and values of closed rounds are kept for 90 days (configurable), visible only with the ID that edits, with line charts of measurements; deletable by the owner, deleted when switched off and on uninstall.
* REST: `GET`/`DELETE /checklists/{id}/rounds`.

= 1.6.0 =
* REST API (`/wp-json/checkoff/v1/`): create, read, edit, tick, reset, delete, live update and subscribe — everything the website does, with the same access rules and limits. Can be switched off.
* A checklist can be created with its whole structure in one call.

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
