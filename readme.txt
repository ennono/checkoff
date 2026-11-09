=== CheckOff ===
Contributors: frickemedia
Tags: checklist, todo, habit tracker, rating, shortcode
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 8.0
Stable tag: 3.3.1
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
* **Counter** — count entries up to a target (glasses of water).
* **Notes** — text fields, not counted towards the progress.

Add-ons can register further blocks through the `checkoff_block_types` filter.

= Board =

Any checklist can be shown as a Kanban board instead of a list: the two
icons beside its title switch between list and board, for everybody, and
the choice is remembered on the device. While editing, View sets what
everybody sees first. Its sections become lanes, its entries cards in three columns —
open, in progress, done; the names can be changed. Where a card stands
follows from what is entered: nothing yet is open, something is in
progress (a counter at 2 of 3, two of five weekdays), done is done by the
building block's own rule. Drag a card to another column, or use its ⇄
button: to done ticks it — or opens it where a value has to be entered —,
to in progress marks it, to open clears it (with undo). Moving cards is
ticking, so everybody who may tick may do it; times, live update, working
offline, the history and repetition all work as in the list.

= Editing =

One button switches between ticking and editing. Titles and entries are
edited in place; Enter adds the next entry, Backspace on an empty one removes
it. Entries and sub-lists can be moved, duplicated and deleted, a sub-list's
building block and settings changed. Everything is saved automatically.
Two open tabs cannot overwrite each other: the second one is told to reload.

= Sharing =

Every checklist has one link (and its ID). No accounts: the address shows
the list, a password protects it. Under "Change access" the owner chooses
one of three levels:

* **Open** (default) — everybody with the link can view, tick and change
  it. For the shopping list shared with the family.
* **Protected** — everybody with the link can view and tick; changing,
  clearing and deleting need the password. For a list the team ticks and
  one person maintains.
* **Private** — without the password the link shows nothing but the
  password prompt, not even the title. For a health diary or personal goals.

Unlocking is remembered on the device for ten days and can be ended with
"Lock on this device"; a new password ends it everywhere. Only a hash of
the password is stored (WordPress's own, as for user passwords). There is
no way to recover a forgotten password — an administrator can remove it in
wp-admin, which makes the list open.

**Public checklists.** The owner of a protected list can tick "Show
publicly": it then appears in the directory `[cko_lists]` with title,
description and progress, and everybody can open, view and tick it —
changing it still needs the password. Open lists cannot be shown (anybody
passing by could delete them), private ones of course not. Nothing is
approved beforehand; an administrator can take a list out in wp-admin, and
Settings → General → Public checklists switches the directory off.

Lists from before 2.0.0 may have handed out a "link to share" that only
ticks; it keeps working, but no new ones are made — "Protected" does the
same job with one link.

Open checklists update themselves when somebody ticks on another device,
and two people ticking at the same moment never erase each other's ticks.
Without a connection ticking goes on: the ticks wait in the browser and are
sent, with the time they were made, as soon as the connection is back.

= Templates =

Build a checklist on the website, mark it as a template in wp-admin (several at once: bulk action "Edit" in the list), and
visitors can start from it: in the create form (unless the shortcode says
`templates="no"`) or in a gallery (`[cko_templates]`). They get their own copy — entries, settings and
repetition (starting today), no ticks — and later changes to the template
do not touch it.

= Repetition =

A checklist can repeat daily, weekly or monthly, every so many days, weeks
or months from a start date — forever, a number of times, or until a date.
Every round starts with all ticks cleared; the previous round stays, for
the line "last time" (how much was done, what stayed open).

Every tick and every value is stored with the date and time it was set.
Optionally (Settings → General → History of rounds, off by default) the
closed rounds are kept for a number of days (default 90): beside the round
of a repeating checklist, ‹ and › page back and forth round by round, and
each past round is shown as the checklist itself, as it was ticked, with
the time at every entry — read only, with "Back to the current round".
The current round shows the times too. Only somebody who may edit sees
times and history, and can delete the history (⋯). Rounds in which nothing
was entered are not kept. Switching the setting off deletes every kept
round. Administrators see a list's kept rounds in a box on its screen.

Rounds ahead can be ticked in advance: › beside the round pages forward to
the next rounds (as many as Settings → General → Tick ahead allows,
default 8, never beyond the end of the series), and what is ticked there —
"not needed" for next week's holiday, a task done early — is waiting when
that round begins. Everybody who may tick may do it; the round ahead is
ticked against the list as it is.

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
* `[cko_lists title="" limit="20" empty=""]` — the public checklists, most
  recently used first.

= REST API =

Everything the website does with checklists also works under
`/wp-json/checkoff/v1/` (Settings → General → REST API: everybody — the
default —, logged-in users only, or off). Access works exactly as on the website, by the same code: the ID
in the address is the key, no login and no nonce needed; unknown IDs count
against the caller's hourly limit. What the ID allows depends on the
list's level: open — everything; protected — reading, ticking and
subscribing, the rest needs the password (403 `checkoff_locked`); private —
nothing but unlocking without the password (403 `checkoff_private`). Send
the password with each request in the header `X-CheckOff-Password`. Creating follows the setting "Guests may create checklists"
and the hourly limit; logged in means any REST authentication, e.g. an
application password. IDs may be sent with or without dashes, in any case.

* `GET /blocks` — the building blocks, which are offered, the size limits.
* `GET /templates` — the templates (key, title, description, counts).
* `POST /checklists` — `{title?, template?}` or `{title?, structure}`:
  creates a checklist, empty, as a copy of a template or with a whole
  structure in one call. 201 with the checklist.
* `GET /lists?limit=20&offset=0` — the public directory: `{enabled,
  lists:[{id, title, description, url, progress, repeats}]}`.
* `GET /checklists/{id}` — the checklist: structure, values, revision,
  round, last round, progress, state, access, level, URL (and, with edit
  access while the history is on, `times` and the history `index`; with edit
  access, whether it is shown publicly). Counts as a visit.
* `GET /checklists/{id}/state?state=…&revision=…` — live update: with an
  unchanged fingerprint only `{state, changed:false}`, otherwise values,
  progress and round, the structure only for an older revision. Read only.
* `PUT /checklists/{id}/structure` — `{structure, revision}`: saves the
  whole tree (format as GET returns it). 409 when `revision` is stale.
* `PATCH /checklists/{id}` — `{revision, title?, description?,
  recurrence?}`: the same save without sending the tree.
* `PUT /checklists/{id}/values/{entry}` — `{value?, stage?, at?, round?}`:
  `value` sets the value, `null` clears it; `stage` `"doing"` marks the
  entry as in progress on the board, `null` removes the mark; `at` (unix
  time, up to a week back) is when it was ticked — a tick from before the
  current round is refused with 409 `checkoff_round_over`; `round` (a number
  from `upcoming`) ticks a round ahead, 409 `checkoff_round_not_open` when it
  is out of reach. `DELETE` on the same address (with `?round=`) clears value
  and mark. Answers with the stored value, the
  stage and the progress.
* `DELETE /checklists/{id}/values` — clears every value.
* `DELETE /checklists/{id}` — deletes the checklist and its subscriptions.
* `POST /checklists/{id}/unlock` — `{password}`: unlocks a protected list
  for the browser (cookie); `DELETE` locks it again. API clients can send
  the header `X-CheckOff-Password` with each request instead.
* `PUT /checklists/{id}/access` — `{level: open|protected|private,
  password?}`: sets the level; an open list needs a password to become
  protected or private, afterwards an empty password keeps the current one.
  Open removes the password. With edit access only.
* `PUT /checklists/{id}/password` — `{password}` sets or changes it (an
  open list becomes protected), `DELETE` removes it (the list becomes open).
* `PUT /checklists/{id}/listed` — shows a protected list publicly,
  `DELETE` takes it out. With edit access only.
* `GET /checklists/{id}/rounds` — the kept rounds: `index` (number and
  dates, oldest first, to page through) and `rounds` (newest first, each
  with dates, progress and its entries); `DELETE` deletes them. With edit
  access only.
* `GET /checklists/{id}/rounds/{number}` — one kept round as it was:
  structure, `values`, `times` (entry → unix time) and progress. 404 when
  it is not kept.
* `GET /checklists/{id}/upcoming/{number}` — one round ahead: dates,
  `values`, `stages` and progress of what is ticked in it already; 404 when
  it is out of reach. `GET /checklists/{id}` lists the rounds ahead as
  `upcoming`. Whoever may tick.
* `POST /checklists/{id}/subscriptions` — `{email, consent:true}`: sends
  the confirmation mail (202). Confirming stays in the mail.

Values per building block (anything else clears the entry, as on the page):
simple and due dates `true`; "not needed" `"done"` or `"skip"`; weekdays
`{"days":[1,3,5]}` (ISO weekdays, the week is set by the server); colour
scale the position from 0, counted from the left (block settings `steps`
3 or 5, `labels` — own names left to right, missing ones are the site's —
and `reverse`, green first; `GET /blocks` lists every block's settings); number rating and counter a whole number;
measurement a number (decimal comma accepted); choice the answer's index
from 0; notes text. A due date is set in the structure:
`{"id":…,"kind":"item","label":…,"data":{"due":"2026-12-01"}}`.

Errors are WordPress REST errors with `code`, `message` and the HTTP status:
400 invalid, 401 login required, 403 password needed, 404 unknown ID
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
  per hour (then every lookup is refused, so a hit is not even noticed).
* **Pages showing a checklist** are not cached, not indexed and do not pass
  their address on as a referrer; REST answers likewise.
* **Passwords** (optional per checklist, for "protected" and "private"):
  stored as WordPress password hashes, never in plain text; wrong ones
  limited per visitor and per list.
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
  checklist card, the subscription panel and the cards of the
  public directory.
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
* Lucide icons 0.460.0 (53 icons) — ISC licence — https://lucide.dev

== Changelog ==

= 3.3.1 =
* Fixed: after ticking, the page announced "Updated from another device" although nothing had changed elsewhere.
* Fixed: a page left open into the next round now pages correctly to the rounds before and ahead without being reloaded.
* Fixed: a note typed just before paging to another round is saved in the round it was typed in.
* While rounds ahead are ticked, the repetition settings say that changing the repetition deletes those ticks.
* Texts no longer speak of a "summary" of the last round, which no longer exists.

= 3.3.0 =
* Tick ahead: › beside the round of a repeating checklist pages forward to the next rounds, and they can be ticked in advance — on the list and on the board, also without a connection. How many rounds ahead is a setting (Settings → General → Tick ahead, default 8, 0 = off).
* REST: `round` on `PUT|DELETE …/values/{entry}`, `GET …/upcoming/{number}`, `upcoming` in the checklist.
* Deleting the history keeps what is ticked ahead.

= 3.2.0 =
* The building block "Switch" is removed: every building block now works the same way — entries with values. A switch in an existing list becomes an ordinary building block whose former variants are sub-lists; which variant was chosen is forgotten.

= 3.1.0 =
* Every building block works the same way: in the list, on the board, while editing and in the history the page treats all of them alike, and everything that differs between them belongs to the block itself. A new building block needs nothing outside its own code.
* A done entry is shown crossed out for every building block, not only for ticks (a counter at its target, a rating, a measurement in range).
* For developers: `Checkoff_Block_Type::client_data()` hands a block's own data (the colour scale, the current week) to the browser; it is no longer part of the general configuration.

= 3.0.0 =
* A clean data model: the structure of a list is stored in a table of its own, one row per saved revision (`{prefix}checkoff_versions`); every value in another, one row per entry and round (`{prefix}checkoff_values`) with the time it was set and the board's "in progress" mark.
* Rounds are computed, not stored: a new round simply has no values yet, nothing is closed or copied. Past rounds are the values of earlier rounds, drawn with the structure saved last before they ended. There are no round posts any more; CheckOff → Rounds is gone, a list's kept rounds are shown in a box on its screen.
* The access level is stored with the list.
* The earlier links to share that only ticked (tick IDs) are gone.
* REST: `PUT …/values/{entry}` takes `stage` and `at`; the answer carries `stage`. `GET /checklists/{id}` and the live update carry `stages`.
* Updating removes the values, the history and the tick IDs of earlier versions; each list keeps its structure, its title, its password and its subscriptions.

= 2.5.1 =
* List or board is switched beside the checklist's title, by everybody and for their own device; the setting while editing is the default everybody sees first.

= 2.5.0 =
* Board view: a checklist can be shown as a Kanban board — sections as lanes, entries as cards in the columns open, in progress and done. Where a card stands follows from what is entered; dragging or "Move to …" sets it. Works on phones (columns scroll sideways), live, offline and in the history.
* REST: an entry's "in progress" mark is the value `<entry>_w`; `view` and `stages` in the structure.

= 2.4.0 =
* Ticking without a connection: a tick that cannot be sent waits in the browser with the time it was made and is sent as soon as there is a connection again — also after closing and reopening the page. A tick from a round that has ended in the meantime is refused and reported.
* "Recently opened" remembers private checklists without their title, and has a button to clear the list.
* On narrow phones the Tick off / Edit switch shows its icons only, so the bar stays one row.
* REST: `PUT …/values/{entry}` takes `at`, the time a tick sent late was made.

= 2.3.0 =
* Kept rounds are posts of their own (`cko_round`), children of their checklist: listed under CheckOff → Rounds with checklist, period and progress, each viewable read-only, and linked from the checklist's screen. Rounds kept before are moved over automatically.
* Deleting a checklist deletes its rounds; a round moved to the trash is deleted for good.

= 2.2.0 =
* Every tick and value is stored with the date and time it was set; whoever may edit sees the time at each entry (while the history of rounds is on).
* The history of rounds is no longer a list of summaries below the checklist: ‹ and › beside the round page back and forth, and every past round is shown as the checklist itself, as it was ticked, with its times — read only.
* REST: `GET /checklists/{id}/rounds/{number}`; `times` in the checklist and the live update.

= 2.1.1 =
* "Lock on this device" is a button of its own (a lock beside the bell); access is changed with the shield beside it.

= 2.1.0 =
* The "More actions" section below the checklist is gone. Its functions sit at the top beside the bell: a lock showing the access level (change access, lock on this device, show publicly) and ⋯ (clear all ticks, delete the checklist).

= 2.0.0 =
* New access concept without accounts: every checklist has one link and one of three levels — open (everybody with the link edits, as before), protected (everybody with the link views and ticks, changing needs the password) or private (without the password the link shows nothing). Chosen under "Change access".
* Public checklists: a protected list can be shown in the new directory `[cko_lists]` (REST `GET /lists`); everybody can open and tick it there. Setting under General, administrators can take a list out.
* The separate "link to share" is no longer handed out — "protected" does the same with one link. Links to share handed out before keep working.
* REST: `PUT /checklists/{id}/access`, `PUT|DELETE /checklists/{id}/listed`, `GET /lists`; answers carry `level` (and `listed` with edit access) instead of `tick_id`/`tick_url`.
* wp-admin: the IDs box shows the level, can end an earlier link to share and take a list out of the directory; the list shows a "Public" badge.


= 1.13.0 =
* Checklists can be marked as templates, or unmarked, several at once: select them in the list, choose "Edit" under bulk actions and set "Template". Each keeps its own description.

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

== Upgrade Notice ==

= 3.2.0 =
The building block "Switch" is gone. Existing switches become ordinary lists with their variants as sub-lists, all of which count.

= 3.0.0 =
New data model. Ticks, values, kept rounds and tick IDs of earlier versions are removed; every list keeps its structure, title, password and subscriptions.

= 2.0.0 =
Existing lists keep working: without a password they are open, with one protected. Earlier links to share still tick. REST clients reading `tick_id` or `tick_url` must stop doing so.
