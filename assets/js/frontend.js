/**
 * CheckOff frontend.
 *
 * Draws the checklists embedded by [cko_checklist] / [cko_app] from their
 * JSON, lets visitors tick them off and edit them, and sends every change to
 * the AJAX handlers in Checkoff_Shortcodes. The server validates everything;
 * the block definitions (labels, settings, defaults) come from PHP through
 * `checkoff.blocks`, so this file knows how to *draw* a block type, never
 * which values it allows.
 *
 * Two kinds of change, two requests:
 * - a value (tick, rating, count, note) is sent at once, for one entry;
 * - the structure (titles, entries, sub-lists, settings) is saved as a whole,
 *   debounced, carrying the revision it was based on — a stale revision is
 *   refused by the server, so two open tabs cannot overwrite each other.
 *
 * @package CheckOff
 */

/* global checkoff, jQuery */

jQuery(function ($) {

    'use strict';

    if (typeof checkoff === 'undefined') {
        return;
    }

    var cfg = checkoff;
    var t = cfg.i18n || {};
    var RECENT_KEY = 'cko_recent';
    var RECENT_MAX = 20;

    /* ==========================================================
       HELPERS
       ========================================================== */

    function ckoPost(action, data) {
        return $.ajax({
            url: cfg.ajax_url,
            method: 'POST',
            dataType: 'json',
            data: Object.assign({
                action: action,
                nonce: cfg.nonce
            }, data || {})
        });
    }

    /** The error message of a failed request, or the fallback. */
    function errorMessage(xhr, fallback) {
        var json = xhr && xhr.responseJSON;
        return (json && json.data && json.data.message) ? json.data.message : fallback;
    }

    /** Builds an element: el('button', {class: 'x', onclick: fn}, [children]). */
    function el(tag, attrs, children) {
        var node = document.createElement(tag);

        Object.keys(attrs || {}).forEach(function (key) {
            var value = attrs[key];

            if (value === null || value === undefined || value === false) {
                return;
            }

            if (key.indexOf('on') === 0 && typeof value === 'function') {
                node.addEventListener(key.slice(2), value);
            } else if (key === 'text') {
                node.textContent = value;
            } else if (key === 'value') {
                node.value = value;
            } else if (key === 'checked') {
                node.checked = !!value;
            } else if (value === true) {
                node.setAttribute(key, '');
            } else {
                node.setAttribute(key, String(value));
            }
        });

        (children || []).forEach(function (child) {
            if (child === null || child === undefined || child === false) {
                return;
            }
            node.appendChild(typeof child === 'string' ? document.createTextNode(child) : child);
        });

        return node;
    }

    function format(str) {
        var args = Array.prototype.slice.call(arguments, 1);
        return String(str).replace(/%(\d+)\$s/g, function (m, n) {
            return args[n - 1] !== undefined ? args[n - 1] : m;
        }).replace(/%s/g, function () {
            return args.length ? args.shift() : '';
        });
    }

    function newId() {
        var chars = 'abcdefghijklmnopqrstuvwxyz0123456789';
        var id = '';
        var buf = new Uint8Array(8);

        if (window.crypto && window.crypto.getRandomValues) {
            window.crypto.getRandomValues(buf);
        } else {
            for (var j = 0; j < 8; j++) {
                buf[j] = Math.floor(Math.random() * 256);
            }
        }

        for (var i = 0; i < 8; i++) {
            id += chars[buf[i] % chars.length];
        }
        return id;
    }

    function clone(obj) {
        return JSON.parse(JSON.stringify(obj));
    }

    function blockDef(type) {
        return cfg.blocks[type] || cfg.blocks.simple;
    }

    function isEnabled(type) {
        return (cfg.enabled || []).indexOf(type) !== -1;
    }

    function copyText(text) {
        if (navigator.clipboard && window.isSecureContext) {
            return navigator.clipboard.writeText(text);
        }

        return new Promise(function (resolve, reject) {
            var area = el('textarea', {value: text, style: 'position:fixed;opacity:0', 'aria-hidden': 'true'});
            document.body.appendChild(area);
            area.select();
            try {
                document.execCommand('copy') ? resolve() : reject();
            } catch (e) {
                reject(e);
            }
            document.body.removeChild(area);
        });
    }

    function storageGet(key, fallback) {
        try {
            var raw = window.localStorage.getItem(key);
            return raw ? JSON.parse(raw) : fallback;
        } catch (e) {
            return fallback;
        }
    }

    function storageSet(key, value) {
        try {
            window.localStorage.setItem(key, JSON.stringify(value));
        } catch (e) {
            // Private mode or storage full: the feature is a convenience only.
        }
    }

    /** Weekdays (ISO numbers) in the order the site's week starts. */
    function orderedDays() {
        var start = parseInt(cfg.week_start, 10);
        start = (start === 0) ? 7 : start; // WordPress counts Sunday as 0.
        var days = [];
        for (var i = 0; i < 7; i++) {
            days.push(((start - 1 + i) % 7) + 1);
        }
        return days;
    }

    /** Days from today (site time) to a Y-m-d date; negative when past. */
    function daysUntil(ymd) {
        var a = (cfg.today_ymd || '').split('-');
        var b = (ymd || '').split('-');
        if (a.length !== 3 || b.length !== 3) {
            return null;
        }
        return Math.round((Date.UTC(+b[0], b[1] - 1, +b[2]) - Date.UTC(+a[0], a[1] - 1, +a[2])) / 86400000);
    }

    /** A Y-m-d date short, in the site's language: "Do., 12.11.". */
    function shortDate(ymd) {
        var p = ymd.split('-');
        var date = new Date(Date.UTC(+p[0], p[1] - 1, +p[2]));
        var options = {weekday: 'short', day: 'numeric', month: 'numeric', timeZone: 'UTC'};
        if (p[0] !== (cfg.today_ymd || '').slice(0, 4)) {
            options.year = 'numeric';
        }
        try {
            return date.toLocaleDateString(cfg.locale || undefined, options);
        } catch (e) {
            return ymd;
        }
    }

    /**
     * The steps of a colour scale block, left to right: the site's scale,
     * reversed if the block says so, with the block's own names — the
     * twin of Checkoff_Block_Color_Scale::steps_for().
     */
    function scaleFor(settings) {
        var steps = clone(cfg.scale[settings.steps] || cfg.scale[5]);
        if (settings.reverse) {
            steps.reverse();
        }
        (settings.labels || []).forEach(function (label, i) {
            if (steps[i] && label) {
                steps[i].label = label;
            }
        });
        return steps;
    }

    /** A measurement in the site's language, with its decimal places. */
    function formatNumber(value, decimals) {
        try {
            return Number(value).toLocaleString(cfg.locale || undefined, {
                minimumFractionDigits: decimals,
                maximumFractionDigits: decimals,
                useGrouping: false
            });
        } catch (e) {
            return Number(value).toFixed(decimals);
        }
    }

    /** A typed number, decimal comma allowed; null when there is none. */
    function parseNumber(text) {
        text = String(text === undefined || text === null ? '' : text).trim().replace(',', '.');
        if (text === '' || isNaN(Number(text))) {
            return null;
        }
        return Number(text);
    }

    /** Whether a measurement counts as done: inside the target range, or entered at all. */
    function inRange(value, settings) {
        if (typeof value !== 'number') {
            return false;
        }
        if (settings.min !== '' && settings.min !== null && settings.min !== undefined && value < Number(settings.min)) {
            return false;
        }
        return settings.max === '' || settings.max === null || settings.max === undefined || value <= Number(settings.max);
    }

    /* ==========================================================
       UI KIT: icons, buttons, toasts, menus, dialogs
       ========================================================== */

    var ICONS = window.checkoffIcons || {};

    /** Lucide icon of each access level. */
    var LEVEL_ICONS = {
        open: 'lock-open',
        protected: 'lock',
        'private': 'eye-off'
    };

    /** Lucide icon of each built-in building block. */
    var BLOCK_ICONS = {
        simple: 'list-checks',
        status: 'ban',
        deadline: 'calendar-clock',
        weekdays: 'calendar-days',
        color_scale: 'palette',
        number_scale: 'hash',
        measurement: 'ruler',
        choice: 'circle-dot',
        'switch': 'toggle-right',
        counter: 'tally-5',
        note: 'sticky-note'
    };

    /** An inline SVG icon; decorative unless a label is given. */
    function icon(name, extraClass) {
        var span = document.createElement('span');
        span.className = 'cko-icon' + (extraClass ? ' ' + extraClass : '');
        span.setAttribute('aria-hidden', 'true');
        if (ICONS[name]) {
            span.innerHTML = '<svg viewBox="0 0 24 24" width="1em" height="1em" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" focusable="false">' + ICONS[name] + '</svg>';
        }
        return span;
    }

    /** The icon of a block type: Lucide for built-in blocks, the type's own symbol otherwise. */
    function typeIcon(type) {
        if (BLOCK_ICONS[type]) {
            return icon(BLOCK_ICONS[type], 'cko-icon--type');
        }
        return el('span', {class: 'cko-icon cko-icon--type', 'aria-hidden': 'true', text: blockDef(type).icon});
    }

    /** A square button with only an icon; the label is for screen readers and the tooltip. */
    function iconButton(name, label, onclick, attrs) {
        var button = el('button', Object.assign({
            type: 'button',
            class: 'cko-iconbtn',
            'aria-label': label,
            title: label,
            onclick: onclick
        }, attrs || {}));
        button.appendChild(icon(name));
        return button;
    }

    /** A button with icon and text. */
    function button(name, label, onclick, attrs) {
        var node = el('button', Object.assign({type: 'button', class: 'cko-btn', onclick: onclick}, attrs || {}));
        if (name) {
            node.appendChild(icon(name));
        }
        node.appendChild(el('span', {text: label}));
        return node;
    }

    /* ---------- toasts ---------- */

    var toastBox = null;

    /**
     * A short message at the bottom of the screen, optionally with one action
     * ("Undo"). Announced to screen readers.
     */
    function toast(text, options) {
        options = options || {};

        if (!toastBox) {
            toastBox = el('div', {class: 'cko-toasts', role: 'status', 'aria-live': 'polite'});
            document.body.appendChild(toastBox);
        }

        var node = el('div', {class: 'cko-toast' + (options.kind ? ' cko-toast--' + options.kind : '')}, [
            options.icon ? icon(options.icon) : null,
            el('span', {class: 'cko-toast__text', text: text})
        ]);

        var timer = null;

        function close() {
            clearTimeout(timer);
            node.classList.add('is-leaving');
            setTimeout(function () {
                if (node.parentNode) {
                    node.parentNode.removeChild(node);
                }
            }, 200);
        }

        if (options.action) {
            node.appendChild(el('button', {
                type: 'button',
                class: 'cko-toast__action',
                text: options.action.label,
                onclick: function () {
                    options.action.run();
                    close();
                }
            }));
        }

        toastBox.appendChild(node);
        timer = setTimeout(close, options.duration || 3500);

        return close;
    }

    /* ---------- menus ---------- */

    var openMenuClose = null;

    /**
     * A small menu under an anchor button. Items: {icon, label, run, danger,
     * disabled} or {separator: true}; content: a node instead of items.
     * Closes on choice, outside click, Escape and scroll; arrow keys move.
     */
    function openMenu(anchor, items, content) {
        if (openMenuClose) {
            openMenuClose();
        }

        var menu = el('div', {class: 'cko-menu', role: content ? 'dialog' : 'menu'});

        if (content) {
            menu.appendChild(content);
        } else {
            items.forEach(function (item) {
                if (item.separator) {
                    menu.appendChild(el('div', {class: 'cko-menu__sep', role: 'separator'}));
                    return;
                }
                var entry = el('button', {
                    type: 'button',
                    role: 'menuitem',
                    class: 'cko-menu__item' + (item.danger ? ' is-danger' : ''),
                    disabled: item.disabled,
                    onclick: function () {
                        close();
                        item.run();
                    }
                }, [item.icon ? icon(item.icon) : null, el('span', {text: item.label})]);
                menu.appendChild(entry);
            });
        }

        document.body.appendChild(menu);

        // Below the anchor, else above it; where it fits neither way (a long
        // picker on a phone), as high as the viewport and scrolling inside.
        // Always kept inside the viewport.
        var rect = anchor.getBoundingClientRect();
        var vw = document.documentElement.clientWidth || window.innerWidth;
        var vh = window.innerHeight;
        var width = menu.offsetWidth;
        var height = menu.offsetHeight;
        var left = rect.left + width > vw - 8 ? rect.right - width : rect.left;
        left = Math.max(8, Math.min(left, vw - width - 8));
        var top;
        if (height <= vh - rect.bottom - 14) {
            top = rect.bottom + 6;
        } else if (height <= rect.top - 14) {
            top = rect.top - height - 6;
        } else {
            menu.style.maxHeight = (vh - 16) + 'px';
            height = Math.min(height, vh - 16);
            top = Math.max(8, Math.min(rect.bottom + 6, vh - height - 8));
        }
        menu.style.left = (left + window.scrollX) + 'px';
        menu.style.top = (top + window.scrollY) + 'px';

        anchor.setAttribute('aria-expanded', 'true');

        function onDoc(e) {
            if (!menu.contains(e.target) && e.target !== anchor && !anchor.contains(e.target)) {
                close();
            }
        }

        function onKey(e) {
            var entries = Array.prototype.slice.call(menu.querySelectorAll('button:not([disabled]), input, a[href]'));
            var index = entries.indexOf(document.activeElement);
            if (e.key === 'Escape') {
                close();
                anchor.focus();
            } else if (!content && (e.key === 'ArrowDown' || e.key === 'ArrowUp') && entries.length) {
                e.preventDefault();
                index = e.key === 'ArrowDown' ? (index + 1) % entries.length : (index - 1 + entries.length) % entries.length;
                entries[index].focus();
            }
        }

        function close() {
            document.removeEventListener('mousedown', onDoc, true);
            document.removeEventListener('touchstart', onDoc, true);
            document.removeEventListener('keydown', onKey, true);
            window.removeEventListener('resize', close);
            anchor.setAttribute('aria-expanded', 'false');
            if (menu.parentNode) {
                menu.parentNode.removeChild(menu);
            }
            openMenuClose = null;
        }

        document.addEventListener('mousedown', onDoc, true);
        document.addEventListener('touchstart', onDoc, true);
        document.addEventListener('keydown', onKey, true);
        window.addEventListener('resize', close);
        openMenuClose = close;

        var first = menu.querySelector('button:not([disabled]), input');
        if (first) {
            first.focus();
        }

        return close;
    }

    /* ---------- confirmation dialog ---------- */

    /** A modal question; resolves true when confirmed. Falls back to window.confirm. */
    function confirmDialog(message, okLabel, danger) {
        if (typeof window.HTMLDialogElement !== 'function') {
            return Promise.resolve(window.confirm(message));
        }

        return new Promise(function (resolve) {
            var dialog = el('dialog', {class: 'cko-dialog'}, [
                el('p', {class: 'cko-dialog__text', text: message}),
                el('div', {class: 'cko-dialog__actions'}, [
                    el('button', {type: 'button', class: 'cko-btn', value: 'cancel', text: t.cancel}),
                    el('button', {type: 'button', class: 'cko-btn ' + (danger ? 'cko-btn--danger' : 'cko-btn--primary'), value: 'ok', text: okLabel})
                ])
            ]);

            dialog.addEventListener('click', function (e) {
                if (e.target.tagName === 'BUTTON') {
                    dialog.close(e.target.value);
                } else if (e.target === dialog) {
                    dialog.close('cancel'); // click on the backdrop
                }
            });
            dialog.addEventListener('close', function () {
                resolve(dialog.returnValue === 'ok');
                dialog.parentNode.removeChild(dialog);
            });

            document.body.appendChild(dialog);
            dialog.showModal();
            dialog.querySelector('.cko-btn--primary, .cko-btn--danger').focus();
        });
    }

    /**
     * Asks for a password in a modal form. `submit(password)` returns a
     * jQuery promise; the dialog stays open with the error until it
     * resolves, so a wrong password can be corrected in place.
     *
     * options: {title, text, label, submitLabel, isNew, submit}
     */
    function passwordDialog(options) {
        var field = el('input', {
            type: 'password',
            class: 'cko-input',
            required: true,
            minlength: options.isNew ? (cfg.password_min || 6) : null,
            maxlength: 200,
            autocomplete: options.isNew ? 'new-password' : 'current-password',
            'aria-label': options.label
        });
        var error = el('p', {class: 'cko-dialog__error', role: 'alert', hidden: true});
        var submitButton = el('button', {type: 'submit', class: 'cko-btn cko-btn--primary', text: options.submitLabel});
        var form = el('form', {class: 'cko-dialog__form', method: 'dialog'}, [
            options.title ? el('p', {class: 'cko-dialog__title'}, [icon('lock'), el('span', {text: options.title})]) : null,
            options.text ? el('p', {class: 'cko-dialog__text', text: options.text}) : null,
            el('label', {class: 'cko-field cko-dialog__field'}, [el('span', {text: options.label}), field]),
            error,
            el('div', {class: 'cko-dialog__actions'}, [
                el('button', {type: 'button', class: 'cko-btn', value: 'cancel', text: t.cancel}),
                submitButton
            ])
        ]);
        var dialog = el('dialog', {class: 'cko-dialog cko-dialog--password'}, [form]);

        function close() {
            if (dialog.open) {
                dialog.close();
            }
        }

        form.addEventListener('click', function (e) {
            if (e.target.value === 'cancel') {
                close();
            }
        });
        dialog.addEventListener('click', function (e) {
            if (e.target === dialog) {
                close();
            }
        });
        dialog.addEventListener('close', function () {
            if (dialog.parentNode) {
                dialog.parentNode.removeChild(dialog);
            }
        });
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            if (!field.value) {
                field.focus();
                return;
            }
            submitButton.disabled = true;
            error.hidden = true;
            options.submit(field.value).done(close).fail(function (message) {
                error.textContent = message;
                error.hidden = false;
                submitButton.disabled = false;
                field.select();
            });
        });

        document.body.appendChild(dialog);
        if (typeof dialog.showModal === 'function') {
            dialog.showModal();
        } else {
            dialog.setAttribute('open', '');
        }
        field.focus();
    }

    /* ==========================================================
       RECENTLY OPENED (localStorage only)
       ========================================================== */

    function recentList() {
        var list = storageGet(RECENT_KEY, []);
        return Array.isArray(list) ? list : [];
    }

    function recentRemember(id, title) {
        var list = recentList().filter(function (entry) {
            return entry && entry.id !== id;
        });
        list.unshift({id: id, title: title, t: Date.now()});
        storageSet(RECENT_KEY, list.slice(0, RECENT_MAX));
        renderRecent();
    }

    /** The server renders the forms; the icons come from here. */
    function enhanceForms() {
        Array.prototype.forEach.call(document.querySelectorAll('.cko-create__type'), function (card) {
            var input = card.querySelector('input');
            var holder = card.querySelector('.cko-create__type-icon');
            if (!input || !holder) {
                return;
            }
            holder.textContent = '';
            holder.appendChild(icon(input.value.indexOf('tpl:') === 0 ? 'layout-template' : 'circle-plus'));
            card.appendChild(icon('circle-check', 'cko-create__tick'));
        });
        Array.prototype.forEach.call(document.querySelectorAll('.cko-template__title'), function (title) {
            title.insertBefore(icon('layout-template'), title.firstChild);
        });
        Array.prototype.forEach.call(document.querySelectorAll('.cko-create__actions .cko-button, .cko-template__form .cko-button'), function (b) {
            b.insertBefore(icon('circle-plus'), b.firstChild);
        });
        Array.prototype.forEach.call(document.querySelectorAll('.cko-open__actions .cko-button'), function (b) {
            b.appendChild(icon('chevron-right'));
        });
    }

    function recentForget(id) {
        storageSet(RECENT_KEY, recentList().filter(function (entry) {
            return entry && entry.id !== id;
        }));
        renderRecent();
    }

    function renderRecent() {
        $('[data-cko-recent]').each(function () {
            var section = this;
            var list = section.querySelector('.cko-recent__list');
            var base = section.getAttribute('data-base-url') || window.location.href;
            var entries = recentList();

            list.textContent = '';

            if (!entries.length) {
                section.hidden = true;
                return;
            }

            entries.forEach(function (entry) {
                var url = new URL(base, window.location.href);
                url.searchParams.set(cfg.query_var, entry.id);

                list.appendChild(el('li', {class: 'cko-recent__item'}, [
                    el('a', {class: 'cko-recent__link', href: url.toString()}, [
                        icon('list-checks'),
                        el('span', {class: 'cko-recent__name', text: entry.title || entry.id}),
                        el('code', {class: 'cko-recent__id', text: entry.id})
                    ]),
                    iconButton('x', t.recent_remove, function () {
                        recentForget(entry.id);
                    }, {class: 'cko-iconbtn cko-recent__remove'})
                ]));
            });

            section.hidden = false;
        });
    }

    /* ==========================================================
       CREATE FORM
       ========================================================== */

    $(document).on('submit', '[data-cko-create]', function (e) {
        e.preventDefault();

        var $form = $(this);
        var $button = $form.find('button[type="submit"]');
        var $msg = $form.find('[data-cko-message]');

        $button.prop('disabled', true);
        $msg.removeClass('cko-message--error').text('');

        ckoPost('cko_create', {
            title: $form.find('[name="title"]').val() || '',
            type: $form.find('[name="type"]:checked').val() || $form.find('[name="type"]').val(),
            page_url: window.location.href
        }).done(function (res) {
            if (res && res.success) {
                $msg.text(t.created);
                // An empty list opens for building, a template's copy ready to use.
                window.location.href = res.data.url + (res.data.edit ? '#edit' : '');
                return;
            }
            $msg.addClass('cko-message--error').text(t.create_failed);
            $button.prop('disabled', false);
        }).fail(function (xhr) {
            $msg.addClass('cko-message--error').text(errorMessage(xhr, t.create_failed));
            $button.prop('disabled', false);
        });
    });

    /* ==========================================================
       CHECKLIST APP
       ========================================================== */

    function Checklist(root) {
        var dataNode = root.querySelector('.cko-data');
        var data = JSON.parse(dataNode.textContent);

        this.root = root;
        this.id = data.id;
        this.url = data.url;

        // A private list without its password: the prompt and nothing else.
        if (data.access === 'sealed') {
            this.renderSealed();
            return;
        }

        this.structure = data.structure;
        this.values = data.values || {};
        this.revision = data.revision;
        this.round = data.round || {repeats: false};
        this.last = data.last || null;
        this.subscribeOpen = false;
        this.structure.recurrence = this.structure.recurrence || {freq: 'none'};
        this.canEdit = data.access === 'edit';   // the tick link only ticks; a protected list needs its password
        this.passwordLocked = data.access === 'locked';
        this.isProtected = !!data.protected;
        this.level = data.level || (this.isProtected ? 'protected' : 'open');
        this.listed = !!data.listed;
        this.history = data.history || null;     // {rounds, days}: only for the edit ID, only while kept
        this.historyOpen = false;
        this.moreOpen = false;                   // "More actions" open across redraws
        this.historyData = null;                 // loaded rounds, on first opening
        this.historyLoading = false;
        this.historyRoundOpen = {};              // round number => details open
        this.state = data.state || '';           // fingerprint for the live update
        this.pending = {};                       // entry ID => value requests running
        this.settingsOpen = {};                  // block ID => settings panel open (edit mode)
        this.recurrenceOpen = false;
        this.statusState = {text: '', kind: ''};
        this.sortables = [];
        this.editing = this.canEdit && window.location.hash === '#edit';
        this.locked = false;          // after a conflict: no more edits
        this.dirty = false;           // structure changed, not yet sent
        this.inflight = false;        // a structure save is running
        this.saveTimer = null;
        this.noteTimers = {};
        this.collapsedKey = 'cko_collapsed_' + this.id;
        this.collapsed = storageGet(this.collapsedKey, {});

        if (this.editing && window.history && window.history.replaceState) {
            window.history.replaceState(null, '', window.location.pathname + window.location.search);
        }

        // A list just created opens ready to type its first entry.
        if (this.editing && this.structure.items.length) {
            this.focusAfterRender = 'new-' + this.structure.items[0].id;
        }

        recentRemember(this.id, this.structure.title);
        this.render();
        this.startSync();
    }

    /* ---------- finding nodes ---------- */

    /** Finds a node by ID: {node, parent, index, depth}. */
    Checklist.prototype.locate = function (id) {
        var found = null;

        function walk(block, depth) {
            if (block.id === id) {
                found = {node: block, parent: null, index: -1, depth: depth};
                return true;
            }
            return block.items.some(function (item, index) {
                if (item.id === id) {
                    found = {node: item, parent: block, index: index, depth: item.kind === 'block' ? depth + 1 : depth};
                    return true;
                }
                return item.kind === 'block' && walk(item, depth + 1);
            });
        }

        // The container is level 0; its blocks (the sections) are level 1.
        walk(this.structure, 0);
        return found;
    };

    Checklist.prototype.countNodes = function (block) {
        block = block || this.structure;
        var self = this;
        return 1 + block.items.reduce(function (sum, item) {
            return sum + (item.kind === 'block' ? self.countNodes(item) : 1);
        }, 0);
    };

    /** Deepest level below a block, the block itself counting as 1. */
    Checklist.prototype.height = function (block) {
        var self = this;
        return 1 + block.items.reduce(function (max, item) {
            return item.kind === 'block' ? Math.max(max, self.height(item)) : max;
        }, 0);
    };

    Checklist.prototype.canAdd = function (count) {
        return this.countNodes() + (count || 1) <= cfg.max_nodes;
    };

    /* ---------- switch: variants ---------- */

    /** The variants of a switch block: its child blocks. */
    function variantsOf(block) {
        return block.items.filter(function (item) {
            return item.kind === 'block';
        });
    }

    /** Index of the variant in force; the first when nothing is chosen. */
    Checklist.prototype.activeVariant = function (block) {
        var n = variantsOf(block).length;
        var v = this.values[block.id];
        return (typeof v === 'number' && v > 0 && v < n) ? v : 0;
    };

    /* ---------- progress ---------- */

    /** {done, total} of a block including its sub-lists. */
    Checklist.prototype.progress = function (block) {
        var self = this;
        var def = blockDef(block.type);
        var result = {done: 0, total: 0};

        // Of a switch, only the variant in force counts.
        if (block.type === 'switch') {
            var active = variantsOf(block)[this.activeVariant(block)];
            return active ? this.progress(active) : result;
        }

        block.items.forEach(function (item) {
            if (item.kind === 'block') {
                var sub = self.progress(item);
                result.done += sub.done;
                result.total += sub.total;
                return;
            }

            if (!def.progress) {
                return;
            }

            var value = self.values[item.id];

            switch (block.type) {
                case 'weekdays':
                    result.total += block.settings.days.length;
                    result.done += (value && value.days) ? value.days.length : 0;
                    break;
                case 'counter':
                    var target = parseInt(block.settings.target, 10) || 0;
                    result.total += 1;
                    result.done += (value && value >= Math.max(1, target)) ? 1 : 0;
                    break;
                case 'status':
                    // "Not needed" leaves the count: neither done nor open.
                    if (value !== 'skip') {
                        result.total += 1;
                        result.done += value === 'done' ? 1 : 0;
                    }
                    break;
                case 'measurement':
                    result.total += 1;
                    result.done += inRange(value, block.settings) ? 1 : 0;
                    break;
                default:
                    result.total += 1;
                    result.done += (value !== undefined && value !== null && value !== false) ? 1 : 0;
            }
        });

        return result;
    };

    /* ---------- saving ---------- */

    /** Shows the saving state in the bar; errors also as a toast. */
    Checklist.prototype.status = function (text, kind) {
        this.statusState = {text: text || '', kind: kind || ''};

        var node = this.root.querySelector('.cko-savestate');
        if (node) {
            node.parentNode.replaceChild(this.renderSaveState(), node);
        }

        if (kind === 'error' && text) {
            toast(text, {kind: 'error', icon: 'triangle-alert', duration: 6000});
        }
    };

    Checklist.prototype.renderSaveState = function () {
        var st = this.statusState;
        var name = {busy: 'loader-circle', ok: 'circle-check', error: 'cloud-alert'}[st.kind];

        // .cko-status(--busy) is what the unload warning looks for.
        return el('span', {
            class: 'cko-savestate cko-status' + (st.kind ? ' cko-savestate--' + st.kind + ' cko-status--' + st.kind : ''),
            role: 'status',
            'aria-live': 'polite'
        }, [
            name ? icon(name) : null,
            el('span', {class: 'cko-savestate__text', text: st.text})
        ]);
    };

    /** Marks the structure as changed and schedules a save. */
    Checklist.prototype.changed = function (rerender, delay) {
        var self = this;

        this.dirty = true;
        this.status(t.saving, 'busy');

        if (rerender) {
            this.render();
        } else {
            this.updateProgress();
        }

        clearTimeout(this.saveTimer);
        this.saveTimer = setTimeout(function () {
            self.saveTimer = null;
            self.saveStructure();
        }, delay || 700);
    };

    Checklist.prototype.saveStructure = function () {
        var self = this;

        if (this.inflight || !this.dirty || this.locked) {
            return;
        }

        this.inflight = true;
        this.dirty = false;

        ckoPost('cko_save_structure', {
            id: this.id,
            revision: this.revision,
            structure: JSON.stringify(this.structure)
        }).done(function (res) {
            if (!res || !res.success) {
                self.dirty = true;
                self.status(t.save_failed, 'error');
                return;
            }

            self.revision = res.data.revision;

            var roundChanged = JSON.stringify(res.data.round || {}) !== JSON.stringify(self.round);
            self.round = res.data.round || self.round;

            // Adopt what the server stored (it may have dropped or renamed
            // something), unless the visitor kept editing meanwhile. Most
            // saves come back unchanged, and then nothing is redrawn.
            if (!self.dirty && JSON.stringify(res.data.structure) !== JSON.stringify(self.structure)) {
                self.structure = res.data.structure;
                self.values = res.data.values || {};
                self.render();
            } else if (roundChanged) {
                self.render();
            }

            self.status(t.saved, 'ok');
        }).fail(function (xhr) {
            if (xhr.status === 409) {
                self.conflict();
                return;
            }
            self.dirty = true;
            self.status(errorMessage(xhr, t.save_failed), 'error');
        }).always(function () {
            self.inflight = false;
            if (self.dirty && !self.locked) {
                self.saveStructure();
            }
        });
    };

    Checklist.prototype.conflict = function () {
        this.locked = true;
        this.editing = false;
        this.render();
        this.status('', '');

        var banner = el('div', {class: 'cko-message cko-message--error cko-conflict', role: 'alert'}, [
            el('span', {text: t.conflict + ' '}),
            el('button', {
                type: 'button',
                class: 'cko-btn',
                text: t.reload,
                onclick: function () {
                    window.location.reload();
                }
            })
        ]);
        this.root.insertBefore(banner, this.root.firstChild);
    };

    /** Sets the value of one entry, optimistically, and sends it. */
    Checklist.prototype.setValue = function (itemId, value, rerender) {
        var self = this;
        var before = this.progress(this.structure);

        if (value === null || value === undefined || value === '' || value === false) {
            delete this.values[itemId];
        } else {
            this.values[itemId] = value;
        }

        if (rerender) {
            this.render();
        } else {
            this.updateProgress();
        }

        var after = this.progress(this.structure);
        if (after.total > 0 && after.done >= after.total && before.done < before.total) {
            toast(t.all_done, {icon: 'circle-check', kind: 'success'});
        }

        this.status(t.saving, 'busy');
        this.pending[itemId] = (this.pending[itemId] || 0) + 1;

        ckoPost('cko_set_value', {
            id: this.id,
            item: itemId,
            value: JSON.stringify(value === undefined ? null : value)
        }).done(function (res) {
            if (res && res.success) {
                self.status(t.saved, 'ok');
            } else {
                self.status(t.save_failed, 'error');
            }
        }).fail(function (xhr) {
            self.status(errorMessage(xhr, t.save_failed), 'error');
        }).always(function () {
            self.pending[itemId] -= 1;
            if (self.pending[itemId] <= 0) {
                delete self.pending[itemId];
            }
        });
    };

    /* ---------- live update ---------- */

    /**
     * Asks the server every few seconds whether the checklist changed on
     * another device, while the page is visible. The answer carries data
     * only when the fingerprint differs.
     */
    Checklist.prototype.startSync = function () {
        var self = this;
        var seconds = parseInt(cfg.sync_interval, 10) || 0;

        if (seconds <= 0) {
            return;
        }

        setInterval(function () {
            self.poll();
        }, seconds * 1000);

        // Back on the tab: catch up at once instead of after the interval.
        document.addEventListener('visibilitychange', function () {
            if (!document.hidden) {
                self.poll();
            }
        });
    };

    /** Whether the visitor is in the middle of something a redraw would disturb. */
    Checklist.prototype.isBusy = function () {
        var active = document.activeElement;
        var typing = active && this.root.contains(active) &&
            (active.tagName === 'TEXTAREA' || (active.tagName === 'INPUT' && active.type !== 'checkbox' && active.type !== 'radio'));

        return this.dirty || this.inflight || this.saveTimer !== null || this.locked || typing ||
            Object.keys(this.noteTimers).some(function (id) {
                return this.noteTimers[id] !== null;
            }, this);
    };

    Checklist.prototype.poll = function () {
        var self = this;

        if (document.hidden || this.polling || this.isBusy()) {
            return;
        }

        this.polling = true;

        ckoPost('cko_poll', {
            id: this.id,
            state: this.state,
            revision: this.revision
        }).done(function (res) {
            if (!res || !res.success || !res.data.changed || self.isBusy()) {
                return;
            }

            var data = res.data;
            var before = JSON.stringify([self.structure, self.values, self.round, self.last]);

            if (data.structure) {
                data.structure.recurrence = data.structure.recurrence || {freq: 'none'};
                self.structure = data.structure;
            }
            self.revision = data.revision;

            // A tick of this page that is still on its way wins over what the
            // server knew a moment ago.
            var values = data.values || {};
            Object.keys(self.pending).forEach(function (id) {
                if (Object.prototype.hasOwnProperty.call(self.values, id)) {
                    values[id] = self.values[id];
                } else {
                    delete values[id];
                }
            });
            self.values = values;
            self.round = data.round || self.round;
            self.last = data.last || null;
            self.state = data.state;

            if (JSON.stringify([self.structure, self.values, self.round, self.last]) !== before) {
                self.render();
                self.status(t.updated, 'ok');
            }
        }).always(function () {
            self.polling = false;
        });
    };

    /** Notes are typed: send them once the visitor pauses. */
    Checklist.prototype.setNoteDebounced = function (itemId, text) {
        var self = this;

        if (text.trim() === '') {
            delete this.values[itemId];
        } else {
            this.values[itemId] = text;
        }

        clearTimeout(this.noteTimers[itemId]);
        this.status(t.saving, 'busy');
        this.noteTimers[itemId] = setTimeout(function () {
            self.noteTimers[itemId] = null;
            self.setValue(itemId, text.trim() === '' ? null : text, false);
        }, 600);
    };

    /* ---------- editing operations ---------- */

    Checklist.prototype.addItem = function (block, afterIndex) {
        if (!this.canAdd()) {
            window.alert(t.limit_nodes);
            return null;
        }

        var item = {id: newId(), kind: 'item', label: ''};
        var index = (afterIndex === undefined) ? block.items.length : afterIndex + 1;

        block.items.splice(index, 0, item);
        this.focusAfterRender = 'label-' + item.id;
        this.changed(true);
        return item;
    };

    Checklist.prototype.addBlock = function (parent, type) {
        if (!this.canAdd()) {
            window.alert(t.limit_nodes);
            return;
        }

        var where = this.locate(parent.id);
        if (where.depth >= cfg.max_depth) {
            window.alert(t.limit_depth);
            return;
        }

        var def = blockDef(type);
        var block = {
            id: newId(),
            kind: 'block',
            type: def.key,
            title: '',
            description: '',
            settings: clone(def.defaults || {}),
            items: []
        };

        // A switch is its variants: it starts with two empty ones.
        if (def.key === 'switch') {
            [0, 1].forEach(function () {
                block.items.push({id: newId(), kind: 'block', type: 'simple', title: '', description: '', settings: {}, items: []});
            });
        }

        parent.items.push(block);
        this.collapsed[block.id] = false;
        this.focusAfterRender = 'title-' + block.id;
        this.changed(true);
    };

    /**
     * Removes an entry or a block at once; a toast offers to undo it, and
     * the save waits until that offer has run out.
     */
    Checklist.prototype.remove = function (id) {
        var self = this;
        var where = this.locate(id);
        if (!where || !where.parent) {
            return;
        }

        var before = clone(this.structure);
        var isBlock = where.node.kind === 'block';

        where.parent.items.splice(where.index, 1);

        // Focus the previous entry, so a list can be cleared with the keyboard.
        var prev = where.parent.items[where.index - 1];
        this.focusAfterRender = prev
            ? (prev.kind === 'block' ? 'title-' + prev.id : 'label-' + prev.id)
            : (where.parent.kind === 'checklist' ? 'addsection' : 'new-' + where.parent.id);

        this.changed(true, 6000);

        toast(isBlock ? t.deleted_section : t.deleted_entry, {
            icon: 'trash-2',
            duration: 6000,
            action: {
                label: t.undo,
                run: function () {
                    self.structure = before;
                    self.changed(true);
                }
            }
        });
    };

    /** Moves an entry within its parent (drag and drop). */
    Checklist.prototype.moveTo = function (parent, from, to) {
        if (from === to || from < 0 || to < 0) {
            return;
        }
        parent.items.splice(to, 0, parent.items.splice(from, 1)[0]);
        this.changed(true);
    };

    Checklist.prototype.move = function (id, delta) {
        var where = this.locate(id);
        if (!where || !where.parent) {
            return;
        }

        var target = where.index + delta;
        if (target < 0 || target >= where.parent.items.length) {
            return;
        }

        var items = where.parent.items;
        items.splice(target, 0, items.splice(where.index, 1)[0]);
        this.focusAfterRender = where.node.kind === 'block' ? 'menu-' + id : 'label-' + id;
        this.changed(true);
    };

    Checklist.prototype.duplicate = function (id) {
        var where = this.locate(id);
        if (!where || !where.parent) {
            return;
        }

        var copy = clone(where.node);
        var size = copy.kind === 'block' ? this.countNodes(copy) : 1;

        if (!this.canAdd(size)) {
            window.alert(t.limit_nodes);
            return;
        }

        (function renew(node) {
            node.id = newId();
            (node.items || []).forEach(renew);
        })(copy);

        where.parent.items.splice(where.index + 1, 0, copy);
        this.focusAfterRender = copy.kind === 'block' ? 'title-' + copy.id : 'label-' + copy.id;
        this.changed(true);
    };

    Checklist.prototype.changeType = function (block, type) {
        var def = blockDef(type);
        var hasChildren = block.items.some(function (item) {
            return item.kind === 'block';
        });

        if (hasChildren && !def.children) {
            return;
        }

        block.type = def.key;
        block.settings = clone(def.defaults || {});

        // As the server does: a switch holds only variants — its entries
        // move into the first, missing variants are added.
        if (def.key === 'switch') {
            var loose = block.items.filter(function (item) {
                return item.kind === 'item';
            });
            var variants = variantsOf(block);
            if (loose.length) {
                if (variants.length) {
                    variants[0].items = variants[0].items.concat(loose);
                } else {
                    variants.push({id: newId(), kind: 'block', type: 'simple', title: '', description: '', settings: {}, items: loose});
                }
            }
            while (variants.length < 2) {
                variants.push({id: newId(), kind: 'block', type: 'simple', title: '', description: '', settings: {}, items: []});
            }
            block.items = variants.slice(0, 6);
        }
        // Entry data (a due date) belongs to its block type; the server drops it too.
        block.items.forEach(function (item) {
            if (item.kind === 'item' && def.key !== 'deadline') {
                delete item.data;
            }
        });
        this.changed(true);
    };

    Checklist.prototype.resetValues = function () {
        var self = this;

        confirmDialog(t.confirm_reset, t.reset_values, true).then(function (ok) {
            if (!ok) {
                return;
            }

            self.values = {};
            self.render();
            self.status(t.saving, 'busy');

            ckoPost('cko_reset_values', {id: self.id}).done(function () {
                self.status(t.saved, 'ok');
            }).fail(function (xhr) {
                self.status(errorMessage(xhr, t.save_failed), 'error');
            });
        });
    };

    Checklist.prototype.deleteList = function () {
        var self = this;

        confirmDialog(t.confirm_delete_list, t.delete_list, true).then(function (ok) {
            if (!ok) {
                return;
            }

            ckoPost('cko_delete', {id: self.id}).done(function () {
                recentForget(self.id);
                self.root.textContent = '';
                self.root.appendChild(el('p', {class: 'cko-empty', role: 'status'}, [icon('circle-check'), el('span', {text: t.deleted})]));
            }).fail(function (xhr) {
                self.status(errorMessage(xhr, t.save_failed), 'error');
            });
        });
    };

    /* ---------- rendering ---------- */

    Checklist.prototype.render = function () {
        var self = this;

        // Removing a focused field fires its change/blur handlers, which may
        // ask for another render while this one is still taking the old
        // nodes down. Finish this one, then draw again.
        if (this.rendering) {
            this.renderAgain = true;
            return;
        }
        this.rendering = true;
        this.renderAgain = false;

        try {
            this.renderNow();
        } finally {
            this.rendering = false;
        }

        if (this.renderAgain) {
            this.render();
        }
    };

    Checklist.prototype.renderNow = function () {
        var self = this;
        var active = document.activeElement;
        var focusKey = this.focusAfterRender ||
            (active && this.root.contains(active) ? active.getAttribute('data-focus') : null);
        var selection = null;

        if (!this.focusAfterRender && active && typeof active.selectionStart === 'number') {
            try {
                selection = [active.selectionStart, active.selectionEnd];
            } catch (e) {
                selection = null; // number inputs have no selection
            }
        }

        this.focusAfterRender = null;

        // Keep a conflict banner, replace everything else.
        Array.prototype.slice.call(this.root.children).forEach(function (child) {
            if (!child.classList.contains('cko-data') && !child.classList.contains('cko-conflict') && child.parentNode === self.root) {
                self.root.removeChild(child);
            }
        });

        this.root.classList.toggle('is-editing', this.editing);
        this.root.appendChild(this.renderToolbar());

        if (this.subscribeOpen) {
            this.root.appendChild(this.renderSubscribe());
        }

        this.root.appendChild(el('div', {class: 'cko-card'}, [this.renderBlock(this.structure, 0)]));

        if (this.history && (this.history.rounds > 0 || this.historyData)) {
            this.root.appendChild(this.renderHistory());
        }

        if (this.editing) {
            this.root.appendChild(this.renderFooter());
        }

        this.initSortable();

        if (focusKey) {
            var target = this.root.querySelector('[data-focus="' + focusKey + '"]');
            if (target) {
                target.focus();
                if (selection && target.setSelectionRange) {
                    try {
                        target.setSelectionRange(selection[0], selection[1]);
                    } catch (e) {
                        // not a text field
                    }
                }
            }
        }
    };

    /** Drag and drop (SortableJS, works with touch) for entries and sections. */
    Checklist.prototype.initSortable = function () {
        var self = this;

        this.sortables.forEach(function (sortable) {
            sortable.destroy();
        });
        this.sortables = [];

        if (!this.editing || typeof window.Sortable !== 'function') {
            return;
        }

        Array.prototype.forEach.call(this.root.querySelectorAll('[data-sortable]'), function (list) {
            var where = self.locate(list.getAttribute('data-sortable'));
            if (!where) {
                return;
            }
            self.sortables.push(window.Sortable.create(list, {
                handle: '.cko-handle',
                draggable: '[data-id]',
                animation: 150,
                ghostClass: 'is-ghost',
                chosenClass: 'is-chosen',
                onEnd: function (evt) {
                    self.moveTo(where.node, evt.oldDraggableIndex, evt.newDraggableIndex);
                }
            }));
        });
    };

    Checklist.prototype.renderToolbar = function () {
        var self = this;
        var start = null;

        function modeButton(editing, name, label) {
            var on = self.editing === editing;
            var node = el('button', {
                type: 'button',
                class: 'cko-mode__btn' + (on ? ' is-on' : ''),
                'aria-pressed': on ? 'true' : 'false',
                'data-focus': 'mode-' + (editing ? 'edit' : 'tick'),
                onclick: function () {
                    if (self.editing === editing) {
                        return;
                    }
                    self.editing = editing;
                    self.focusAfterRender = 'mode-' + (editing ? 'edit' : 'tick');
                    self.render();
                    if (!editing) {
                        clearTimeout(self.saveTimer);
                        self.saveTimer = null;
                        self.saveStructure();
                    }
                }
            }, [icon(name), el('span', {text: label})]);
            return node;
        }

        if (this.canEdit && !this.locked) {
            start = el('div', {class: 'cko-mode', role: 'group', 'aria-label': t.mode}, [
                modeButton(false, 'check', t.mode_tick),
                modeButton(true, 'pencil', t.mode_edit)
            ]);
        } else if (this.passwordLocked && !this.locked) {
            start = button('lock', t.unlock_to_edit, function () {
                self.unlockDialog();
            }, {class: 'cko-btn cko-unlock', 'data-focus': 'unlock'});
        } else if (!this.canEdit) {
            start = el('span', {class: 'cko-badge', title: t.tick_only_hint}, [icon('check'), el('span', {text: t.tick_only})]);
        }

        var subscribe = null;
        if (cfg.subscribe && cfg.subscribe.enabled) {
            subscribe = iconButton('bell', t.subscribe, function () {
                self.subscribeOpen = !self.subscribeOpen;
                self.focusAfterRender = self.subscribeOpen ? 'subscribe-email' : 'subscribe';
                self.render();
            }, {
                class: 'cko-iconbtn' + (this.subscribeOpen ? ' is-on' : ''),
                'aria-expanded': this.subscribeOpen ? 'true' : 'false',
                'data-focus': 'subscribe'
            });
        }

        return el('div', {class: 'cko-bar'}, [
            start,
            el('div', {class: 'cko-bar__end'}, [
                this.renderSaveState(),
                subscribe,
                iconButton('share-2', t.share, function () {
                    openMenu(this, null, self.renderSharePanel());
                }, {'aria-haspopup': 'dialog', 'aria-expanded': 'false', 'data-focus': 'share'})
            ])
        ]);
    };

    /** The share popover: tick link, edit link, ID — each with a copy button. */
    Checklist.prototype.renderSharePanel = function () {
        function row(name, label, value, hint) {
            return el('div', {class: 'cko-share__row'}, [
                el('div', {class: 'cko-share__label'}, [icon(name), el('span', {text: label})]),
                el('div', {class: 'cko-share__field'}, [
                    el('input', {
                        type: 'text',
                        class: 'cko-input cko-share__value',
                        value: value,
                        readonly: true,
                        'aria-label': label,
                        onfocus: function () {
                            this.select();
                        }
                    }),
                    iconButton('copy', t.copy, function () {
                        copyText(value).then(function () {
                            toast(t.copied, {icon: 'check'});
                        }, function () {
                            toast(t.copy_failed, {kind: 'error', icon: 'triangle-alert'});
                        });
                    })
                ]),
                hint ? el('p', {class: 'cko-share__hint', text: hint}) : null
            ]);
        }

        // An earlier link to share (before 2.0.0) stays what it was.
        var isShared = !this.canEdit && !this.passwordLocked;

        return el('div', {class: 'cko-share'}, [
            el('p', {class: 'cko-share__title', text: t.share}),
            isShared
                ? row('link', t.link_tick, this.url, t.tick_only_hint)
                : row('link', t.link_list, this.url, t['link_hint_' + this.level]),
            isShared
                ? row('key-round', t.shared_id, this.id, t.shared_id_hint)
                : row('key-round', t.list_id, this.id, t.id_hint)
        ]);
    };

    /** The round in force and how the last one went, for repeating lists. */
    Checklist.prototype.renderRound = function () {
        var r = this.round;

        if (!r || !r.repeats) {
            return null;
        }

        var main = r.finished ? t.series_finished : (r.total ? format(t.round_of, r.number, r.total) : format(t.round, r.number));
        var when = r.finished ? '' : (r.before_start ? format(t.starts_on, r.start) : format(t.round_dates, r.start, r.end));

        return el('div', {class: 'cko-round'}, [
            el('span', {class: 'cko-chip cko-chip--accent'}, [icon('repeat'), el('span', {text: main})]),
            when ? el('span', {class: 'cko-chip'}, [icon('calendar-days'), el('span', {text: when})]) : null,
            el('span', {class: 'cko-round__rule', text: r.description}),
            this.last ? el('span', {
                class: 'cko-round__last',
                text: format(t.last_round, format(t.round, this.last.number), this.last.done, this.last.total)
            }) : null
        ]);
    };

    /** The subscription form (double opt-in: the server sends a mail). */
    Checklist.prototype.renderSubscribe = function () {
        var self = this;
        var uid = 'cko-sub-' + this.id.replace(/[^a-z0-9]/g, '');
        var message = el('p', {class: 'cko-message', role: 'status', 'aria-live': 'polite'});
        var consent = el('span', {class: 'cko-subscribe__consent-text'});

        // Sanitized on the server (links and emphasis only).
        consent.innerHTML = cfg.subscribe.consent;

        var form = el('form', {
            class: 'cko-subscribe',
            onsubmit: function (e) {
                e.preventDefault();
                var button = form.querySelector('button[type="submit"]');
                button.disabled = true;
                message.className = 'cko-message';
                message.textContent = '';

                ckoPost('cko_subscribe', {
                    id: self.id,
                    email: form.querySelector('input[type="email"]').value,
                    consent: form.querySelector('input[type="checkbox"]').checked ? 1 : ''
                }).done(function (res) {
                    message.textContent = res && res.data && res.data.message ? res.data.message : t.subscribe_failed;
                    if (res && res.success) {
                        form.querySelector('input[type="email"]').value = '';
                        form.querySelector('input[type="checkbox"]').checked = false;
                    }
                }).fail(function (xhr) {
                    message.className = 'cko-message cko-message--error';
                    message.textContent = errorMessage(xhr, t.subscribe_failed);
                }).always(function () {
                    button.disabled = false;
                });
            }
        }, [
            el('div', {class: 'cko-subscribe__head'}, [
                icon('bell'),
                el('p', {class: 'cko-subscribe__title', text: t.subscribe_title}),
                iconButton('x', t.close, function () {
                    self.subscribeOpen = false;
                    self.focusAfterRender = 'subscribe';
                    self.render();
                })
            ]),
            el('p', {class: 'cko-field'}, [
                el('label', {for: uid, text: t.email}),
                el('input', {type: 'email', id: uid, required: true, autocomplete: 'email', 'data-focus': 'subscribe-email'})
            ]),
            el('p', {class: 'cko-subscribe__consent'}, [
                el('label', {}, [el('input', {type: 'checkbox', required: true}), ' ', consent])
            ]),
            el('p', {}, [el('button', {type: 'submit', class: 'cko-btn cko-btn--primary', text: t.subscribe_submit})]),
            message
        ]);

        return form;
    };

    /** The repetition fields of the top-level block. */
    Checklist.prototype.renderRecurrence = function () {
        var self = this;
        var rule = this.structure.recurrence;
        var repeats = rule.freq && rule.freq !== 'none';
        var key = this.structure.id;

        function update(changes) {
            Object.keys(changes).forEach(function (k) {
                rule[k] = changes[k];
            });
            self.changed(true);
        }

        function field(label, control, extraClass) {
            return el('label', {class: 'cko-setting ' + (extraClass || '')}, [
                el('span', {class: 'cko-setting__label', text: label}),
                control
            ]);
        }

        function select(name, value, options, onchange) {
            var node = el('select', {
                class: 'cko-input',
                'data-focus': 'rec-' + name + '-' + key,
                onchange: function () {
                    self.focusAfterRender = 'rec-' + name + '-' + key;
                    onchange(this.value);
                }
            });
            options.forEach(function (o) {
                var option = el('option', {value: o[0], text: o[1]});
                option.selected = String(value) === o[0];
                node.appendChild(option);
            });
            return node;
        }

        function input(name, type, value, attrs, onchange) {
            return el('input', Object.assign({
                type: type,
                class: 'cko-input' + (type === 'number' ? ' cko-input--number' : ''),
                value: value === undefined || value === null ? '' : value,
                'data-focus': 'rec-' + name + '-' + key,
                onchange: function () {
                    self.focusAfterRender = 'rec-' + name + '-' + key;
                    onchange(this.value);
                }
            }, attrs || {}));
        }

        var fields = [
            field(t.repeat, select('freq', rule.freq || 'none', [
                ['none', t.repeat_none],
                ['daily', t.repeat_daily],
                ['weekly', t.repeat_weekly],
                ['monthly', t.repeat_monthly]
            ], function (v) {
                update({freq: v, interval: rule.interval || 1, start: rule.start || cfg.today_ymd, end: rule.end || 'never'});
            }))
        ];

        if (repeats) {
            var unit = {daily: t.unit_days, weekly: t.unit_weeks, monthly: t.unit_months}[rule.freq];

            fields.push(el('label', {class: 'cko-setting'}, [
                el('span', {class: 'cko-setting__label', text: t.repeat_every}),
                el('span', {class: 'cko-setting__inline'}, [
                    input('interval', 'number', rule.interval || 1, {min: 1, max: 365, step: 1}, function (v) {
                        update({interval: Math.max(1, parseInt(v, 10) || 1)});
                    }),
                    ' ' + unit
                ])
            ]));

            fields.push(field(t.repeat_start, input('start', 'date', rule.start || cfg.today_ymd, {}, function (v) {
                update({start: v});
            })));

            fields.push(field(t.repeat_end, select('end', rule.end || 'never', [
                ['never', t.end_never],
                ['count', t.end_count],
                ['until', t.end_until]
            ], function (v) {
                update({end: v, count: rule.count || 10, until: rule.until || ''});
            })));

            if (rule.end === 'count') {
                fields.push(field(t.rounds, input('count', 'number', rule.count || 10, {min: 1, max: 1000, step: 1}, function (v) {
                    update({count: Math.max(1, parseInt(v, 10) || 1)});
                })));
            } else if (rule.end === 'until') {
                fields.push(field(t.end_until, input('until', 'date', rule.until || '', {min: rule.start || cfg.today_ymd}, function (v) {
                    update({until: v});
                })));
            }
        }

        return el('div', {class: 'cko-block__settings cko-recurrence'}, fields.concat([
            repeats ? el('span', {class: 'cko-setting__desc cko-recurrence__hint', text: t.repeat_hint}) : null
        ]));
    };

    Checklist.prototype.renderFooter = function () {
        var self = this;

        var details = el('details', {class: 'cko-more', open: this.moreOpen}, [
            el('summary', {}, [icon('ellipsis'), el('span', {text: t.more_actions})]),
            el('div', {class: 'cko-more__body'}, [
                el('p', {class: 'cko-more__hint', text: t.id_hint}),
                el('div', {class: 'cko-more__password'}, [
                    el('p', {class: 'cko-more__hint cko-more__level'}, [
                        icon(LEVEL_ICONS[this.level]),
                        ' ',
                        el('strong', {text: t['level_' + this.level] + ': '}),
                        t['level_' + this.level + '_desc']
                    ]),
                    el('div', {class: 'cko-more__actions'}, [
                        button('shield', t.access_change, function () {
                            self.accessDialog();
                        }, {'data-focus': 'access-change'}),
                        this.isProtected ? button('lock', t.password_lock, function () {
                            self.lockAgain();
                        }, {'data-focus': 'password-lock'}) : null
                    ]),
                    cfg.lists_enabled && this.level === 'protected' ? el('label', {class: 'cko-more__listed'}, [
                        el('input', {
                            type: 'checkbox',
                            checked: this.listed,
                            'data-focus': 'listed',
                            onchange: function () {
                                self.setListed(this.checked);
                            }
                        }),
                        el('span', {}, [el('strong', {text: t.listed}), el('span', {class: 'cko-setting__desc', text: t.listed_hint})])
                    ]) : null
                ]),
                el('div', {class: 'cko-more__actions'}, [
                    button('rotate-ccw', t.reset_values, function () {
                        self.resetValues();
                    }),
                    button('trash-2', t.delete_list, function () {
                        self.deleteList();
                    }, {class: 'cko-btn cko-btn--danger'})
                ])
            ])
        ]);

        // Stays open across redraws, or the buttons just used would vanish.
        details.addEventListener('toggle', function () {
            self.moreOpen = details.open;
        });

        return details;
    };

    /* ---------- password protection ---------- */

    /** Unlocks a protected list; the page is reloaded with edit access. */
    Checklist.prototype.unlockDialog = function () {
        var self = this;

        passwordDialog({
            title: t.unlock_title,
            text: t.unlock_text,
            label: t.password,
            submitLabel: t.unlock,
            submit: function (password) {
                var done = $.Deferred();
                ckoPost('cko_unlock', {id: self.id, password: password}).done(function (res) {
                    if (res && res.success) {
                        done.resolve();
                        window.location.hash = 'edit';
                        window.location.reload();
                    } else {
                        done.reject(t.password_wrong);
                    }
                }).fail(function (xhr) {
                    done.reject(errorMessage(xhr, t.password_wrong));
                });
                return done.promise();
            }
        });
    };

    /**
     * Chooses the level — open, protected, private — and the password for
     * it: required when an open list gets one, optional (a new one) after.
     */
    Checklist.prototype.accessDialog = function () {
        var self = this;
        var hadPassword = this.isProtected;
        var chosen = this.level;
        var radios = ['open', 'protected', 'private'].map(function (level) {
            return el('label', {class: 'cko-level'}, [
                el('input', {
                    type: 'radio',
                    name: 'cko-level',
                    value: level,
                    checked: level === chosen,
                    onchange: function () {
                        chosen = level;
                        update();
                    }
                }),
                el('span', {class: 'cko-level__text'}, [
                    el('span', {class: 'cko-level__name'}, [icon(LEVEL_ICONS[level]), el('span', {text: t['level_' + level]})]),
                    el('span', {class: 'cko-level__desc', text: t['level_' + level + '_desc']})
                ])
            ]);
        });
        var field = el('input', {
            type: 'password',
            class: 'cko-input',
            minlength: cfg.password_min || 6,
            maxlength: 200,
            autocomplete: 'new-password',
            'aria-label': hadPassword ? t.password_new : t.password
        });
        var passwordBox = el('div', {class: 'cko-dialog__password'}, [
            el('label', {class: 'cko-field cko-dialog__field'}, [el('span', {text: hadPassword ? t.password_new : t.password}), field]),
            hadPassword ? el('p', {class: 'cko-setting__desc', text: t.password_keep}) : null,
            el('p', {class: 'cko-setting__desc', text: t.password_forget})
        ]);
        var error = el('p', {class: 'cko-dialog__error', role: 'alert', hidden: true});
        var submitButton = el('button', {type: 'submit', class: 'cko-btn cko-btn--primary', text: t.save});
        var form = el('form', {class: 'cko-dialog__form', method: 'dialog'}, [
            el('p', {class: 'cko-dialog__title'}, [icon('shield'), el('span', {text: t.access_title})]),
            el('fieldset', {class: 'cko-levels'}, [el('legend', {class: 'screen-reader-text', text: t.access})].concat(radios)),
            passwordBox,
            error,
            el('div', {class: 'cko-dialog__actions'}, [
                el('button', {type: 'button', class: 'cko-btn', value: 'cancel', text: t.cancel}),
                submitButton
            ])
        ]);
        var dialog = el('dialog', {class: 'cko-dialog cko-dialog--access'}, [form]);

        function update() {
            passwordBox.hidden = chosen === 'open';
            field.required = chosen !== 'open' && !hadPassword;
        }

        function close() {
            if (dialog.open) {
                dialog.close();
            }
        }

        form.addEventListener('click', function (e) {
            if (e.target.value === 'cancel') {
                close();
            }
        });
        dialog.addEventListener('click', function (e) {
            if (e.target === dialog) {
                close();
            }
        });
        dialog.addEventListener('close', function () {
            if (dialog.parentNode) {
                dialog.parentNode.removeChild(dialog);
            }
        });
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            if (chosen !== 'open' && !hadPassword && !field.value) {
                field.focus();
                return;
            }
            submitButton.disabled = true;
            error.hidden = true;
            ckoPost('cko_set_access', {id: self.id, level: chosen, password: chosen === 'open' ? '' : field.value}).done(function (res) {
                if (res && res.success) {
                    self.level = res.data.level;
                    self.isProtected = !!res.data.protected;
                    self.listed = !!res.data.listed;
                    close();
                    self.focusAfterRender = 'access-change';
                    self.render();
                    toast(t.access_saved, {icon: LEVEL_ICONS[self.level]});
                } else {
                    error.textContent = t.save_failed;
                    error.hidden = false;
                    submitButton.disabled = false;
                }
            }).fail(function (xhr) {
                error.textContent = errorMessage(xhr, t.save_failed);
                error.hidden = false;
                submitButton.disabled = false;
            });
        });

        update();
        document.body.appendChild(dialog);
        if (typeof dialog.showModal === 'function') {
            dialog.showModal();
        } else {
            dialog.setAttribute('open', '');
        }
        dialog.querySelector('input[type="radio"]:checked').focus();
    };

    /** Shows the list in the public directory, or no longer. */
    Checklist.prototype.setListed = function (listed) {
        var self = this;

        ckoPost('cko_set_listed', {id: this.id, listed: listed ? 1 : ''}).done(function (res) {
            if (res && res.success) {
                self.listed = !!res.data.listed;
                toast(self.listed ? t.listed_on : t.listed_off, {icon: self.listed ? 'globe' : 'eye-off'});
            }
        }).fail(function (xhr) {
            toast(errorMessage(xhr, t.save_failed), {kind: 'error', icon: 'cloud-alert'});
        }).always(function () {
            self.focusAfterRender = 'listed';
            self.render();
        });
    };

    /** A private list opened without its password: only the prompt. */
    Checklist.prototype.renderSealed = function () {
        var self = this;
        var field = el('input', {
            type: 'password',
            class: 'cko-input',
            required: true,
            maxlength: 200,
            autocomplete: 'current-password',
            'aria-label': t.password
        });
        var error = el('p', {class: 'cko-message cko-message--error', role: 'alert', hidden: true});
        var submitButton = el('button', {type: 'submit', class: 'cko-btn cko-btn--primary'}, [icon('lock-open'), el('span', {text: t.open})]);
        var form = el('form', {
            class: 'cko-sealed__form',
            onsubmit: function (e) {
                e.preventDefault();
                submitButton.disabled = true;
                error.hidden = true;
                ckoPost('cko_unlock', {id: self.id, password: field.value}).done(function (res) {
                    if (res && res.success) {
                        window.location.reload();
                    } else {
                        error.textContent = t.password_wrong;
                        error.hidden = false;
                        submitButton.disabled = false;
                    }
                }).fail(function (xhr) {
                    error.textContent = errorMessage(xhr, t.password_wrong);
                    error.hidden = false;
                    submitButton.disabled = false;
                    field.select();
                });
            }
        }, [
            el('label', {class: 'cko-field'}, [el('span', {text: t.password}), field]),
            submitButton,
            error
        ]);

        var title = this.root.querySelector('.cko-checklist__title');
        if (title) {
            title.parentNode.removeChild(title);
        }
        this.root.classList.add('cko-app--sealed');
        this.root.appendChild(el('div', {class: 'cko-card cko-sealed'}, [
            el('p', {class: 'cko-sealed__title'}, [icon('lock'), el('span', {text: t.sealed_title})]),
            el('p', {class: 'cko-sealed__text', text: t.sealed_text}),
            form
        ]));
    };

    /** Locks the list again on this device: the page reloads locked. */
    Checklist.prototype.lockAgain = function () {
        ckoPost('cko_lock', {id: this.id}).always(function () {
            if (window.history && window.history.replaceState) {
                window.history.replaceState(null, '', window.location.pathname + window.location.search);
            }
            window.location.reload();
        });
    };

    /* ---------- history of closed rounds (edit ID only) ---------- */

    /** Loads the kept rounds; the server checks the edit ID again. */
    Checklist.prototype.loadHistory = function () {
        var self = this;

        if (this.historyLoading) {
            return;
        }

        this.historyLoading = true;
        this.render();

        ckoPost('cko_history', {id: this.id}).done(function (res) {
            if (res && res.success) {
                self.historyData = res.data;
                self.history.rounds = res.data.rounds.length;
            }
        }).fail(function (xhr) {
            toast(errorMessage(xhr, t.history_failed), {kind: 'error', icon: 'cloud-alert'});
        }).always(function () {
            self.historyLoading = false;
            self.render();
        });
    };

    Checklist.prototype.deleteHistory = function () {
        var self = this;

        confirmDialog(t.history_delete_confirm, t.history_delete, true).then(function (ok) {
            if (!ok) {
                return;
            }
            ckoPost('cko_history_delete', {id: self.id}).done(function (res) {
                if (res && res.success) {
                    self.historyData = res.data;
                    self.history.rounds = 0;
                    self.render();
                    toast(t.history_deleted, {icon: 'trash-2'});
                }
            }).fail(function (xhr) {
                toast(errorMessage(xhr, t.save_failed), {kind: 'error', icon: 'cloud-alert'});
            });
        });
    };

    /** A small line chart of one measured entry across the kept rounds. */
    function sparkline(points, decimals, unit) {
        var w = 240;
        var h = 48;
        var pad = 4;
        var values = points.map(function (p) {
            return p.v;
        });
        var min = Math.min.apply(null, values);
        var max = Math.max.apply(null, values);
        var span = max - min || 1;
        var coords = points.map(function (p, i) {
            var x = pad + (points.length === 1 ? 0 : i * (w - 2 * pad) / (points.length - 1));
            var y = h - pad - (p.v - min) / span * (h - 2 * pad);
            return [Math.round(x * 10) / 10, Math.round(y * 10) / 10];
        });
        var svg = '<svg viewBox="0 0 ' + w + ' ' + h + '" width="100%" height="' + h + '" preserveAspectRatio="none" aria-hidden="true" focusable="false">' +
            '<polyline fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" vector-effect="non-scaling-stroke" points="' +
            coords.map(function (c) {
                return c.join(',');
            }).join(' ') + '"/>' +
            coords.map(function (c) {
                return '<circle cx="' + c[0] + '" cy="' + c[1] + '" r="2.5" fill="currentColor"/>';
            }).join('') + '</svg>';
        var box = el('span', {class: 'cko-chart__plot'});
        box.innerHTML = svg;
        var u = unit ? ' ' + unit : '';
        return el('span', {class: 'cko-chart__body'}, [
            box,
            el('span', {class: 'cko-chart__range', text: formatNumber(min, decimals) + '–' + formatNumber(max, decimals) + u})
        ]);
    }

    Checklist.prototype.renderHistory = function () {
        var self = this;
        var data = this.historyData;
        var count = data ? data.rounds.length : this.history.rounds;
        var body = [];

        if (this.historyLoading && !data) {
            body.push(el('p', {class: 'cko-history__hint', text: t.history_loading}));
        } else if (data) {
            body.push(el('p', {class: 'cko-history__hint', text: format(t.history_hint, data.days)}));

            if (!data.rounds.length) {
                body.push(el('p', {class: 'cko-history__empty', text: t.history_empty}));
            } else {
                body.push(this.renderHistoryCharts(data.rounds));
                body.push(el('ol', {class: 'cko-history__rounds'}, data.rounds.map(function (round) {
                    return el('li', {}, [self.renderPastRound(round)]);
                })));
                body.push(el('div', {class: 'cko-history__actions'}, [
                    button('trash-2', t.history_delete, function () {
                        self.deleteHistory();
                    }, {class: 'cko-btn cko-btn--danger', 'data-focus': 'history-delete'})
                ]));
            }
        }

        var details = el('details', {class: 'cko-history', open: this.historyOpen}, [
            el('summary', {'data-focus': 'history'}, [
                icon('history'),
                el('span', {class: 'cko-history__title', text: t.history}),
                el('span', {class: 'cko-chip cko-chip--muted', text: format(count === 1 ? t.history_round_one : t.history_rounds, count)})
            ]),
            el('div', {class: 'cko-history__body'}, body)
        ]);

        details.addEventListener('toggle', function () {
            self.historyOpen = details.open;
            if (details.open && !self.historyData) {
                self.focusAfterRender = 'history';
                self.loadHistory();
            }
        });

        return details;
    };

    /** Line charts of measurements, oldest round on the left. */
    Checklist.prototype.renderHistoryCharts = function (rounds) {
        var series = {};
        var order = [];

        rounds.slice().reverse().forEach(function (round) {
            round.sections.forEach(function (section) {
                if (section.type !== 'measurement') {
                    return;
                }
                section.entries.forEach(function (entry) {
                    if (typeof entry.value !== 'number') {
                        return;
                    }
                    if (!series[entry.id]) {
                        series[entry.id] = {label: entry.label, section: section.title, unit: section.unit, points: [], decimals: 0};
                        order.push(entry.id);
                    }
                    var s = series[entry.id];
                    s.label = entry.label;
                    s.points.push({n: round.number, v: entry.value});
                    var frac = String(entry.value).split('.')[1];
                    s.decimals = Math.max(s.decimals, frac ? Math.min(2, frac.length) : 0);
                });
            });
        });

        var charts = order.filter(function (id) {
            return series[id].points.length >= 2;
        }).map(function (id) {
            var s = series[id];
            var last = s.points[s.points.length - 1].v;
            return el('figure', {class: 'cko-chart'}, [
                el('figcaption', {class: 'cko-chart__caption'}, [
                    el('span', {class: 'cko-chart__label', text: (s.section ? s.section + ' › ' : '') + (s.label || '—')}),
                    el('span', {class: 'cko-chart__last', text: formatNumber(last, s.decimals) + (s.unit ? ' ' + s.unit : '')})
                ]),
                sparkline(s.points, s.decimals, s.unit)
            ]);
        });

        return charts.length ? el('div', {class: 'cko-history__charts'}, charts) : null;
    };

    /** One closed round: dates and progress, its entries when opened. */
    Checklist.prototype.renderPastRound = function (round) {
        var self = this;
        var dates = round.start ? shortDate(round.start) + (round.end && round.end !== round.start ? ' – ' + shortDate(round.end) : '') : '';
        var complete = round.total > 0 && round.done >= round.total;

        var details = el('details', {class: 'cko-pastround' + (complete ? ' is-complete' : ''), open: !!this.historyRoundOpen[round.number]}, [
            el('summary', {}, [
                el('span', {class: 'cko-pastround__name', text: format(t.round_number, round.number)}),
                el('span', {class: 'cko-pastround__dates', text: dates}),
                el('span', {class: 'cko-progress' + (complete ? ' is-complete' : '')}, [
                    el('span', {class: 'cko-progress__bar', 'aria-hidden': 'true'}, [
                        el('span', {class: 'cko-progress__fill', style: 'width:' + round.percent + '%'})
                    ]),
                    el('span', {class: 'cko-progress__text', text: format(t.progress, round.done, round.total)})
                ])
            ]),
            el('div', {class: 'cko-pastround__body'}, round.sections.map(function (section) {
                return el('div', {class: 'cko-pastround__section'}, [
                    section.title ? el('p', {class: 'cko-pastround__title', text: section.title}) : null,
                    el('ul', {class: 'cko-pastround__entries'}, section.entries.map(function (entry) {
                        var state = entry.done ? 'is-done' : (entry.skipped ? 'is-skipped' : 'is-open');
                        var text = entry.text && entry.text !== '✓' ? entry.text : '';
                        return el('li', {class: 'cko-pastround__entry ' + state}, [
                            icon(entry.done ? 'check' : (entry.skipped ? 'ban' : 'minus')),
                            el('span', {class: 'cko-pastround__label', text: entry.label || '—'}),
                            text ? el('span', {class: 'cko-pastround__value', text: text}) : null
                        ]);
                    }))
                ]);
            }))
        ]);

        details.addEventListener('toggle', function () {
            self.historyRoundOpen[round.number] = details.open;
        });

        return details;
    };

    Checklist.prototype.renderProgress = function (block) {
        var p = this.progress(block);

        if (!p.total) {
            return el('span', {class: 'cko-progress cko-progress--none', 'data-progress': block.id});
        }

        var pct = Math.round(p.done / p.total * 100);
        var complete = p.done >= p.total;

        return el('span', {
            class: 'cko-progress' + (complete ? ' is-complete' : ''),
            'data-progress': block.id,
            role: 'img',
            'aria-label': format(t.progress, p.done, p.total)
        }, [
            el('span', {class: 'cko-progress__bar', 'aria-hidden': 'true'}, [
                el('span', {class: 'cko-progress__fill', style: 'width:' + pct + '%'})
            ]),
            el('span', {class: 'cko-progress__text', 'aria-hidden': 'true'}, [
                complete ? icon('check') : null,
                el('span', {text: format(t.progress, p.done, p.total)})
            ])
        ]);
    };

    /** Refreshes every progress display without redrawing the list. */
    Checklist.prototype.updateProgress = function () {
        var self = this;

        (function walk(block) {
            var node = self.root.querySelector('[data-progress="' + block.id + '"]');
            if (node) {
                node.parentNode.replaceChild(self.renderProgress(block), node);
            }
            block.items.forEach(function (item) {
                if (item.kind === 'block') {
                    walk(item);
                }
            });
        })(this.structure);
    };

    /**
     * Draws the checklist (depth 0), a section (a block on the top level,
     * depth 1: heading, no frame) or a nested sub-list (framed).
     */
    Checklist.prototype.renderBlock = function (block, depth, options) {
        var self = this;
        options = options || {};
        var isRoot = block.kind === 'checklist';
        var isSection = !isRoot && depth === 1;
        var def = blockDef(block.type);
        var editing = this.editing;
        var collapsed = !editing && !isRoot && !options.bare && !!this.collapsed[block.id];
        var titleText = block.title || (isRoot || isSection ? '' : def.label);
        var header = null;

        function descField() {
            return el('textarea', {
                class: 'cko-input cko-block__desc-input',
                rows: 1,
                maxlength: 1000,
                placeholder: t.description_placeholder,
                'aria-label': t.description_placeholder,
                'data-focus': 'desc-' + block.id,
                value: block.description,
                oninput: function () {
                    block.description = this.value;
                    self.changed(false);
                }
            });
        }

        if (isRoot) {
            header = el('header', {class: 'cko-head'}, [
                editing
                    ? el('input', {
                        type: 'text',
                        class: 'cko-input cko-head__title-input',
                        value: block.title,
                        maxlength: 200,
                        placeholder: t.title_placeholder,
                        'aria-label': t.title_placeholder,
                        'data-focus': 'title-' + block.id,
                        oninput: function () {
                            block.title = this.value;
                            self.changed(false);
                        }
                    })
                    : el('h2', {class: 'cko-head__title cko-block__title', text: block.title}),
                this.renderRound(),
                this.renderProgress(block)
            ]);
        } else if (editing || (titleText !== '' && !options.variant)) {
            // An untitled section shows no heading outside editing: it
            // simply continues the list above it. A switch's variant in
            // force shows none either: the switch names it.
            header = el('div', {class: 'cko-block__header'}, [
                editing ? el('span', {class: 'cko-handle', title: t.drag, 'aria-hidden': 'true'}, [icon('grip-vertical')]) : null,
                el('span', {class: 'cko-block__type', title: def.label}, [typeIcon(block.type)]),
                editing
                    ? el('input', {
                        type: 'text',
                        class: 'cko-input cko-block__title-input',
                        value: block.title,
                        maxlength: 200,
                        placeholder: options.variant ? format(t.variant_n, options.variant) : (isSection ? t.section_placeholder : def.label),
                        'aria-label': options.variant ? format(t.variant_n, options.variant) : (isSection ? t.section_placeholder : def.label),
                        'data-focus': 'title-' + block.id,
                        oninput: function () {
                            block.title = this.value;
                            self.changed(false);
                        },
                        onkeydown: function (e) {
                            // Enter in a heading goes on to typing the entries.
                            if (e.key === 'Enter') {
                                e.preventDefault();
                                var next = self.root.querySelector('[data-focus="new-' + block.id + '"]');
                                if (next) {
                                    next.focus();
                                }
                            }
                        }
                    })
                    : el('h' + Math.min(6, depth + 2), {class: 'cko-block__title', text: titleText}),
                this.renderProgress(block),
                editing ? iconButton('sliders-horizontal', t.settings, function () {
                    self.settingsOpen[block.id] = !self.settingsOpen[block.id];
                    self.focusAfterRender = 'settings-' + block.id;
                    self.render();
                }, {
                    class: 'cko-iconbtn' + (this.settingsOpen[block.id] ? ' is-on' : ''),
                    'aria-expanded': this.settingsOpen[block.id] ? 'true' : 'false',
                    'data-focus': 'settings-' + block.id
                }) : null,
                editing ? iconButton('ellipsis', t.block_actions, function () {
                    self.openBlockMenu(this, block, depth);
                }, {'aria-haspopup': 'menu', 'aria-expanded': 'false', 'data-focus': 'menu-' + block.id}) : null,
                editing ? null : iconButton(collapsed ? 'chevron-right' : 'chevron-down', collapsed ? t.expand : t.collapse, function () {
                    self.collapsed[block.id] = !collapsed;
                    storageSet(self.collapsedKey, self.collapsed);
                    self.focusAfterRender = 'toggle-' + block.id;
                    self.render();
                }, {
                    class: 'cko-iconbtn cko-block__toggle',
                    'aria-expanded': collapsed ? 'false' : 'true',
                    'aria-controls': 'cko-b-' + block.id,
                    'data-focus': 'toggle-' + block.id
                })
            ]);
        }

        var body = el('div', {class: 'cko-block__body', id: 'cko-b-' + block.id, hidden: collapsed});

        if (editing && isRoot) {
            body.appendChild(descField());
            var rule = block.recurrence || {};
            body.appendChild(el('details', {
                class: 'cko-panel cko-recurrence-panel',
                open: this.recurrenceOpen,
                ontoggle: function () {
                    self.recurrenceOpen = this.open;
                }
            }, [
                el('summary', {}, [
                    icon('repeat'),
                    el('span', {text: t.repeat}),
                    el('strong', {text: rule.freq && rule.freq !== 'none' ? (this.round.description || '') : t.repeat_none})
                ]),
                this.renderRecurrence()
            ]));
        } else if (editing && this.settingsOpen[block.id]) {
            body.appendChild(el('div', {class: 'cko-panel cko-panel--settings'}, [
                this.renderBlockSettings(block, false),
                descField()
            ]));
        }

        if (!editing && block.description) {
            body.appendChild(el('p', {class: 'cko-block__desc', text: block.description}));
        }

        if (block.type === 'switch' && !editing) {
            body.appendChild(this.renderSwitch(block));
        } else if (block.type === 'switch' && editing) {
            body.appendChild(el('p', {class: 'cko-block__note'}, [icon('toggle-right'), el('span', {text: t.switch_edit_note})]));
        }

        if (block.type === 'weekdays' && block.settings.reset === 'weekly' && !editing) {
            body.appendChild(el('p', {class: 'cko-block__note'}, [icon('repeat'), el('span', {text: t.week_reset_note})]));
        }

        if (isRoot) {
            var sections = el('div', {class: 'cko-sections', 'data-sortable': block.id});

            block.items.forEach(function (item) {
                sections.appendChild(el('div', {class: 'cko-sections__entry', 'data-id': item.id}, [
                    self.renderBlock(item, 1)
                ]));
            });

            if (block.items.length) {
                body.appendChild(sections);
            } else {
                body.appendChild(el('p', {class: 'cko-empty'}, [
                    icon('layout-template'),
                    el('span', {text: editing ? t.empty_checklist_edit : t.empty_checklist})
                ]));
            }

            if (editing) {
                body.appendChild(el('div', {class: 'cko-addsection'}, [
                    button('circle-plus', t.add_section, function () {
                        self.openPicker(this, block);
                    }, {class: 'cko-btn cko-btn--dashed', 'aria-haspopup': 'menu', 'data-focus': 'addsection'})
                ]));
            }
        } else if (block.type === 'switch' && !editing) {
            // Ticking: only the variant in force, without its own heading.
            var current = variantsOf(block)[this.activeVariant(block)];
            if (current) {
                body.appendChild(this.renderBlock(current, depth + 1, {variant: this.activeVariant(block) + 1, bare: true}));
            }
        } else {
            var list = el('ul', {class: 'cko-items', 'data-sortable': block.id});
            var variantNumber = 0;

            block.items.forEach(function (item, index) {
                if (item.kind === 'block') {
                    variantNumber++;
                    list.appendChild(el('li', {class: 'cko-items__entry cko-items__entry--block', 'data-id': item.id}, [
                        self.renderBlock(item, depth + 1, block.type === 'switch' ? {variant: variantNumber} : {})
                    ]));
                } else {
                    list.appendChild(self.renderItem(block, item, index));
                }
            });

            if (block.items.length) {
                body.appendChild(list);
            } else if (!editing) {
                body.appendChild(el('p', {class: 'cko-block__empty', text: t.empty_block}));
            }

            // A switch holds variants, not entries.
            if (editing && block.type !== 'switch') {
                body.appendChild(this.renderNewEntry(block));
            }
        }

        var classes = 'cko-block cko-block--' + (isRoot ? 'checklist' : block.type) + ' cko-block--depth-' + depth;
        classes += isRoot ? ' cko-block--root' : (isSection ? ' cko-block--section' : ' cko-block--nested');
        if (options.variant) {
            classes += ' cko-block--variant' + (options.bare ? ' cko-block--variant-bare' : '');
        }
        if (isSection && !titleText) {
            classes += ' cko-block--untitled';
        }
        if (!isRoot && this.progress(block).total > 0 && this.progress(block).done >= this.progress(block).total) {
            classes += ' is-complete';
        }

        return el('section', {class: classes, 'data-block': block.id}, [header, body]);
    };

    /** The switch of a switch block: one button per variant, named by its title. */
    Checklist.prototype.renderSwitch = function (block) {
        var self = this;
        var active = this.activeVariant(block);

        return el('div', {class: 'cko-switch', role: 'group', 'aria-label': block.title || blockDef('switch').label}, variantsOf(block).map(function (variant, i) {
            var on = i === active;
            return el('button', {
                type: 'button',
                class: 'cko-switch__option' + (on ? ' is-on' : ''),
                'aria-pressed': on ? 'true' : 'false',
                'data-focus': 'switch-' + i + '-' + block.id,
                text: variant.title || format(t.variant_n, i + 1),
                onclick: function () {
                    if (on) {
                        return;
                    }
                    self.focusAfterRender = 'switch-' + i + '-' + block.id;
                    self.setValue(block.id, i === 0 ? null : i, true);
                }
            });
        }));
    };

    /** The always-ready input at the end of a block: type, Enter, next. */
    Checklist.prototype.renderNewEntry = function (block) {
        var self = this;

        return el('div', {class: 'cko-newentry'}, [
            icon('plus'),
            el('input', {
                type: 'text',
                class: 'cko-input cko-newentry__input',
                placeholder: t.new_entry,
                'aria-label': t.new_entry,
                maxlength: 300,
                'data-focus': 'new-' + block.id,
                onkeydown: function (e) {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        var label = this.value.trim();
                        if (!label) {
                            return;
                        }
                        if (!self.canAdd()) {
                            toast(t.limit_nodes, {kind: 'error', icon: 'triangle-alert'});
                            return;
                        }
                        block.items.push({id: newId(), kind: 'item', label: label});
                        self.focusAfterRender = 'new-' + block.id;
                        self.changed(true);
                    } else if (e.key === 'Backspace' && this.value === '') {
                        // Back into the last entry, to correct it.
                        var last = block.items.filter(function (item) {
                            return item.kind === 'item';
                        }).pop();
                        var node = last ? self.root.querySelector('[data-focus="label-' + last.id + '"]') : null;
                        if (node) {
                            e.preventDefault();
                            node.focus();
                        }
                    }
                }
            })
        ]);
    };

    /** The ⋯ menu of a section or sub-list. */
    Checklist.prototype.openBlockMenu = function (anchor, block, depth) {
        var self = this;
        var where = this.locate(block.id);
        var last = where.parent.items.length - 1;

        openMenu(anchor, [
            {
                icon: 'list-plus',
                label: block.type === 'switch' ? t.add_variant : t.add_block,
                disabled: depth >= cfg.max_depth || (block.type === 'switch' && variantsOf(block).length >= 6),
                run: function () {
                    self.openPicker(anchor, block);
                }
            },
            {icon: 'copy', label: t.duplicate, run: function () { self.duplicate(block.id); }},
            {icon: 'arrow-up', label: t.move_up, disabled: where.index === 0, run: function () { self.move(block.id, -1); }},
            {icon: 'arrow-down', label: t.move_down, disabled: where.index === last, run: function () { self.move(block.id, 1); }},
            {separator: true},
            {icon: 'trash-2', label: t['delete'], danger: true, run: function () { self.remove(block.id); }}
        ]);
    };

    /** The choice of building blocks, to add to the checklist or a block. */
    Checklist.prototype.openPicker = function (anchor, parent) {
        var self = this;
        var close = null;

        var grid = el('div', {class: 'cko-picker'}, (cfg.enabled || []).map(function (key) {
            var candidate = blockDef(key);
            return el('button', {
                type: 'button',
                class: 'cko-picker__option',
                onclick: function () {
                    if (close) {
                        close();
                    }
                    self.addBlock(parent, key);
                }
            }, [
                el('span', {class: 'cko-picker__icon'}, [typeIcon(key)]),
                el('span', {class: 'cko-picker__label', text: candidate.label}),
                el('span', {class: 'cko-picker__desc', text: candidate.description})
            ]);
        }));

        close = openMenu(anchor, null, el('div', {class: 'cko-picker-wrap'}, [
            el('p', {class: 'cko-picker__title', text: parent.kind === 'checklist' ? t.add_section : t.add_block}),
            grid
        ]));
    };

    Checklist.prototype.renderBlockSettings = function (block, isRoot) {
        var self = this;
        var def = blockDef(block.type);
        var hasChildren = block.items.some(function (item) {
            return item.kind === 'block';
        });

        var fields = [];

        // The type can be changed, among the offered types that can hold what
        // the block holds now.
        var typeSelect = el('select', {
            class: 'cko-input',
            'data-focus': 'type-' + block.id,
            onchange: function () {
                self.focusAfterRender = 'type-' + block.id;
                self.changeType(block, this.value);
            }
        });

        Object.keys(cfg.blocks).forEach(function (key) {
            var candidate = cfg.blocks[key];
            if ((!isEnabled(key) && key !== block.type) || (hasChildren && !candidate.children)) {
                return;
            }
            var option = el('option', {value: key, text: candidate.icon + ' ' + candidate.label});
            option.selected = key === block.type;
            typeSelect.appendChild(option);
        });

        fields.push(el('label', {class: 'cko-setting'}, [
            el('span', {class: 'cko-setting__label', text: t.block_type}),
            typeSelect
        ]));

        (def.schema || []).forEach(function (field) {
            var value = block.settings[field.key];
            var control;

            function update(newValue, rerender) {
                block.settings[field.key] = newValue;
                self.focusAfterRender = rerender ? 'set-' + field.key + '-' + block.id : null;
                self.changed(!!rerender);
            }

            switch (field.type) {
                case 'number':
                    control = el('input', {
                        type: 'number',
                        class: 'cko-input cko-input--number',
                        value: value,
                        min: field.min,
                        max: field.max,
                        step: 1,
                        'data-focus': 'set-' + field.key + '-' + block.id,
                        onchange: function () {
                            update(parseInt(this.value, 10) || 0, true);
                        }
                    });
                    break;

                case 'select':
                    control = el('select', {
                        class: 'cko-input',
                        'data-focus': 'set-' + field.key + '-' + block.id,
                        onchange: function () {
                            var v = this.value;
                            update(/^-?\d+$/.test(v) && typeof value === 'number' ? parseInt(v, 10) : v, true);
                        }
                    });
                    Object.keys(field.options).forEach(function (key) {
                        var option = el('option', {value: key, text: field.options[key]});
                        option.selected = String(value) === key;
                        control.appendChild(option);
                    });
                    break;

                case 'text':
                    control = el('input', {
                        type: 'text',
                        class: 'cko-input cko-input--short',
                        value: value || '',
                        maxlength: field.maxlength || 100,
                        placeholder: field.placeholder || '',
                        'data-focus': 'set-' + field.key + '-' + block.id,
                        oninput: function () {
                            update(this.value, false);
                        }
                    });
                    break;

                case 'decimal':
                    control = el('input', {
                        type: 'text',
                        inputmode: 'decimal',
                        class: 'cko-input cko-input--number',
                        value: value === '' || value === null || value === undefined ? '' : formatNumber(value, parseInt(block.settings.decimals, 10) || 0),
                        'data-focus': 'set-' + field.key + '-' + block.id,
                        onchange: function () {
                            var number = parseNumber(this.value);
                            update(number === null ? '' : number, true);
                        }
                    });
                    break;

                case 'options':
                    control = el('textarea', {
                        class: 'cko-input cko-input--options',
                        rows: Math.max(3, (value || []).length + 1),
                        value: (value || []).join('\n'),
                        'data-focus': 'set-' + field.key + '-' + block.id,
                        onchange: function () {
                            var lines = this.value.split(/\r?\n/).map(function (line) {
                                return line.trim().slice(0, field.maxlength || 40);
                            }).filter(function (line) {
                                return line !== '';
                            }).slice(0, field.max || 12);
                            if (!lines.length) {
                                lines = clone(blockDef(block.type).defaults[field.key] || []);
                            }
                            update(lines, true);
                        }
                    });
                    break;

                case 'checkbox':
                    control = el('input', {
                        type: 'checkbox',
                        checked: !!value,
                        'data-focus': 'set-' + field.key + '-' + block.id,
                        onchange: function () {
                            update(this.checked, true);
                        }
                    });
                    break;

                case 'days':
                    control = el('span', {class: 'cko-setting__days'}, orderedDays().map(function (day) {
                        var on = (value || []).indexOf(day) !== -1;
                        return el('label', {class: 'cko-setting__day'}, [
                            el('input', {
                                type: 'checkbox',
                                checked: on,
                                'data-focus': 'set-days-' + day + '-' + block.id,
                                onchange: function () {
                                    var days = (block.settings.days || []).filter(function (d) {
                                        return d !== day;
                                    });
                                    if (this.checked) {
                                        days.push(day);
                                    }
                                    if (!days.length) {
                                        this.checked = true; // at least one day
                                        return;
                                    }
                                    days.sort(function (a, b) {
                                        return a - b;
                                    });
                                    block.settings.days = days;
                                    self.focusAfterRender = 'set-days-' + day + '-' + block.id;
                                    self.changed(true);
                                }
                            }),
                            ' ',
                            cfg.weekdays_abbr[day]
                        ]);
                    }));
                    break;
            }

            if (!control) {
                return;
            }

            fields.push(el(field.type === 'days' ? 'div' : 'label', {class: 'cko-setting cko-setting--' + field.type}, [
                el('span', {class: 'cko-setting__label', text: field.label}),
                control,
                field.description ? el('span', {class: 'cko-setting__desc', text: field.description}) : null
            ]));
        });

        return el('div', {class: 'cko-block__settings'}, fields);
    };

    Checklist.prototype.renderItem = function (block, item, index) {
        var self = this;

        if (this.editing) {
            return el('li', {class: 'cko-items__entry cko-item cko-item--edit', 'data-id': item.id, 'data-item': item.id}, [
                el('span', {class: 'cko-handle', title: t.drag, 'aria-hidden': 'true'}, [icon('grip-vertical')]),
                el('input', {
                    type: 'text',
                    class: 'cko-input cko-item__label-input',
                    value: item.label,
                    maxlength: 300,
                    placeholder: t.item_placeholder,
                    'aria-label': t.item_label,
                    'data-focus': 'label-' + item.id,
                    oninput: function () {
                        item.label = this.value;
                        self.changed(false);
                    },
                    onkeydown: function (e) {
                        // Enter adds the next entry, Backspace on an empty one
                        // removes it, Alt+arrows move it: a list can be typed
                        // and sorted without the mouse.
                        if (e.key === 'Enter') {
                            e.preventDefault();
                            self.addItem(block, block.items.indexOf(item));
                        } else if (e.key === 'Backspace' && this.value === '') {
                            e.preventDefault();
                            self.remove(item.id);
                        } else if (e.altKey && (e.key === 'ArrowUp' || e.key === 'ArrowDown')) {
                            e.preventDefault();
                            self.move(item.id, e.key === 'ArrowUp' ? -1 : 1);
                        }
                    }
                }),
                block.type === 'deadline' ? el('input', {
                    type: 'date',
                    class: 'cko-input cko-item__due-input',
                    value: (item.data && item.data.due) || '',
                    'aria-label': t.due_date + (item.label ? ': ' + item.label : ''),
                    title: t.due_date,
                    'data-focus': 'due-' + item.id,
                    onchange: function () {
                        if (this.value) {
                            item.data = {due: this.value};
                        } else {
                            delete item.data;
                        }
                        self.changed(false);
                    }
                }) : null,
                iconButton('x', t['delete'], function () {
                    self.remove(item.id);
                }, {class: 'cko-iconbtn cko-item__delete', 'data-focus': 'del-' + item.id})
            ]);
        }

        var label = el('span', {class: 'cko-item__label', text: item.label || '—'});

        return el('li', {
            class: 'cko-items__entry cko-item cko-item--' + block.type + (this.isItemDone(block, item) ? ' is-done' : '') +
                (block.type === 'status' && this.values[item.id] === 'skip' ? ' is-skipped' : ''),
            'data-id': item.id,
            'data-item': item.id
        }, [this.renderControl(block, item, this.values[item.id], label)]);
    };

    Checklist.prototype.isItemDone = function (block, item) {
        var value = this.values[item.id];

        switch (block.type) {
            case 'simple':
                return !!value;
            case 'weekdays':
                return !!(value && value.days && value.days.length >= block.settings.days.length);
            case 'counter':
                return !!(value && value >= Math.max(1, parseInt(block.settings.target, 10) || 0));
            case 'note':
                return false;
            case 'status':
                return value === 'done';
            case 'measurement':
                return inRange(value, block.settings);
            default:
                return value !== undefined && value !== null;
        }
    };

    /** The control of one entry, by block type, with its label. */
    Checklist.prototype.renderControl = function (block, item, value, label) {
        var self = this;
        var s = block.settings;
        var labelId = 'cko-l-' + item.id;

        if (label.tagName !== 'INPUT') {
            label.id = labelId;
        }

        switch (block.type) {

            case 'simple':
                var box = el('input', {
                    type: 'checkbox',
                    class: 'cko-check',
                    checked: !!value,
                    'aria-labelledby': this.editing ? null : labelId,
                    'aria-label': this.editing ? (item.label || t.item_label) : null,
                    'data-focus': 'check-' + item.id,
                    onchange: function () {
                        self.focusAfterRender = 'check-' + item.id;
                        self.setValue(item.id, this.checked ? true : null, true);
                    }
                });
                return el('label', {class: 'cko-item__main'}, [
                    el('span', {class: 'cko-checkbox'}, [box, icon('check')]),
                    label
                ]);

            case 'weekdays':
                var checked = (value && value.days) ? value.days : [];
                return el('div', {class: 'cko-item__main cko-item__main--stacked'}, [
                    label,
                    el('span', {class: 'cko-days', role: 'group', 'aria-labelledby': this.editing ? null : labelId},
                        orderedDays().filter(function (day) {
                            return s.days.indexOf(day) !== -1;
                        }).map(function (day) {
                            var on = checked.indexOf(day) !== -1;
                            return el('button', {
                                type: 'button',
                                class: 'cko-day' + (on ? ' is-on' : '') + (day === cfg.today ? ' is-today' : ''),
                                'aria-pressed': on ? 'true' : 'false',
                                'aria-label': cfg.weekdays[day] + (day === cfg.today ? ' (' + t.today + ')' : ''),
                                title: cfg.weekdays[day],
                                'data-focus': 'day-' + day + '-' + item.id,
                                text: cfg.weekdays_abbr[day],
                                onclick: function () {
                                    var days = checked.filter(function (d) {
                                        return d !== day;
                                    });
                                    if (!on) {
                                        days.push(day);
                                    }
                                    self.focusAfterRender = 'day-' + day + '-' + item.id;
                                    self.setValue(item.id, days.length ? {days: days, week: cfg.current_week} : null, true);
                                }
                            });
                        })
                    )
                ]);

            case 'color_scale':
                var steps = scaleFor(s);
                var current = (value === undefined || value === null) ? null : parseInt(value, 10);
                return el('div', {class: 'cko-item__main cko-item__main--stacked'}, [
                    label,
                    el('span', {class: 'cko-scale cko-scale--colors', role: 'group', 'aria-labelledby': this.editing ? null : labelId}, [
                        steps.map(function (step, i) {
                            var on = current === i;
                            return el('button', {
                                type: 'button',
                                class: 'cko-swatch' + (on ? ' is-on' : ''),
                                style: '--cko-swatch:' + step.color,
                                'aria-pressed': on ? 'true' : 'false',
                                'aria-label': step.label,
                                title: step.label,
                                'data-focus': 'swatch-' + i + '-' + item.id,
                                onclick: function () {
                                    self.focusAfterRender = 'swatch-' + i + '-' + item.id;
                                    self.setValue(item.id, on ? null : i, true);
                                }
                            }, [icon('check')]);
                        }),
                        el('span', {
                            class: 'cko-scale__current',
                            text: current !== null && steps[current] ? steps[current].label : t.not_rated
                        })
                    ].reduce(function (all, part) {
                        return all.concat(part);
                    }, []))
                ]);

            case 'number_scale':
                var min = parseInt(s.min, 10);
                var max = parseInt(s.max, 10);
                var num = (value === undefined || value === null) ? null : parseInt(value, 10);
                var scale;

                if (max - min <= 10) {
                    var buttons = [];
                    for (var n = min; n <= max; n++) {
                        (function (n) {
                            var on = num === n;
                            buttons.push(el('button', {
                                type: 'button',
                                class: 'cko-number' + (on ? ' is-on' : ''),
                                'aria-pressed': on ? 'true' : 'false',
                                'data-focus': 'num-' + n + '-' + item.id,
                                text: String(n),
                                onclick: function () {
                                    self.focusAfterRender = 'num-' + n + '-' + item.id;
                                    self.setValue(item.id, on ? null : n, true);
                                }
                            }));
                        })(n);
                    }
                    scale = el('span', {class: 'cko-scale cko-scale--numbers', role: 'group', 'aria-labelledby': this.editing ? null : labelId}, buttons);
                } else {
                    scale = el('span', {class: 'cko-scale cko-scale--numbers'}, [
                        el('input', {
                            type: 'number',
                            class: 'cko-input cko-input--number',
                            min: min,
                            max: max,
                            step: 1,
                            value: num === null ? '' : num,
                            'aria-labelledby': this.editing ? null : labelId,
                            'data-focus': 'numin-' + item.id,
                            onchange: function () {
                                var v = this.value === '' ? null : Math.max(min, Math.min(max, parseInt(this.value, 10)));
                                self.setValue(item.id, isNaN(v) ? null : v, false);
                            }
                        }),
                        el('span', {class: 'cko-scale__range', text: min + '–' + max})
                    ]);
                }

                return el('div', {class: 'cko-item__main cko-item__main--stacked'}, [label, scale]);

            case 'status':
                var skipped = value === 'skip';
                var statusBox = el('input', {
                    type: 'checkbox',
                    class: 'cko-check',
                    checked: value === 'done',
                    'aria-labelledby': labelId,
                    'data-focus': 'check-' + item.id,
                    onchange: function () {
                        self.focusAfterRender = 'check-' + item.id;
                        self.setValue(item.id, this.checked ? 'done' : null, true);
                    }
                });
                return el('div', {class: 'cko-item__main cko-item__main--status'}, [
                    el('label', {class: 'cko-item__tick'}, [
                        el('span', {class: 'cko-checkbox'}, [statusBox, icon('check')]),
                        label
                    ]),
                    skipped ? el('span', {class: 'cko-chip cko-chip--muted cko-status__note', text: t.not_needed}) : null,
                    iconButton('ban', t.mark_not_needed, function () {
                        self.focusAfterRender = 'skip-' + item.id;
                        self.setValue(item.id, skipped ? null : 'skip', true);
                    }, {
                        class: 'cko-iconbtn cko-status__skip' + (skipped ? ' is-on' : ''),
                        'aria-pressed': skipped ? 'true' : 'false',
                        'data-focus': 'skip-' + item.id
                    })
                ]);

            case 'deadline':
                var due = (item.data && item.data.due) || '';
                var dueBox = el('input', {
                    type: 'checkbox',
                    class: 'cko-check',
                    checked: !!value,
                    'aria-labelledby': labelId,
                    'aria-describedby': due ? 'cko-d-' + item.id : null,
                    'data-focus': 'check-' + item.id,
                    onchange: function () {
                        self.focusAfterRender = 'check-' + item.id;
                        self.setValue(item.id, this.checked ? true : null, true);
                    }
                });
                var chip = null;
                if (due) {
                    var left = daysUntil(due);
                    var soon = parseInt(s.soon, 10) || 0;
                    var state = '';
                    var text = format(t.due_until, shortDate(due));
                    if (!value && left !== null) {
                        if (left < 0) {
                            state = ' is-overdue';
                            text = format(t.due_overdue, shortDate(due));
                        } else if (left === 0) {
                            state = ' is-soon';
                            text = t.due_today;
                        } else if (left === 1 && soon > 0) {
                            state = ' is-soon';
                            text = t.due_tomorrow;
                        } else if (left <= soon) {
                            state = ' is-soon';
                        }
                    }
                    chip = el('span', {class: 'cko-due' + state, id: 'cko-d-' + item.id, title: t.due_date}, [icon('calendar-clock'), el('span', {text: text})]);
                }
                return el('label', {class: 'cko-item__main cko-item__main--deadline'}, [
                    el('span', {class: 'cko-checkbox'}, [dueBox, icon('check')]),
                    label,
                    chip
                ]);

            case 'measurement':
                var decimals = parseInt(s.decimals, 10) || 0;
                var hasMin = s.min !== '' && s.min !== null && s.min !== undefined;
                var hasMax = s.max !== '' && s.max !== null && s.max !== undefined;
                var unit = s.unit ? ' ' + s.unit : '';
                var range = '';
                if (hasMin && hasMax) {
                    range = formatNumber(s.min, decimals) + '–' + formatNumber(s.max, decimals) + unit;
                } else if (hasMin) {
                    range = '≥ ' + formatNumber(s.min, decimals) + unit;
                } else if (hasMax) {
                    range = '≤ ' + formatNumber(s.max, decimals) + unit;
                }
                var measured = typeof value === 'number';
                var rangeState = (measured && range) ? (inRange(value, s) ? ' is-in-range' : ' is-out-of-range') : '';
                return el('div', {class: 'cko-item__main cko-item__main--stacked'}, [
                    label,
                    el('span', {class: 'cko-measure' + rangeState}, [
                        el('input', {
                            type: 'text',
                            inputmode: decimals ? 'decimal' : 'numeric',
                            class: 'cko-input cko-input--number cko-measure__input',
                            value: measured ? formatNumber(value, decimals) : '',
                            placeholder: '–',
                            'aria-labelledby': labelId,
                            'aria-describedby': range ? 'cko-r-' + item.id : null,
                            'data-focus': 'measure-' + item.id,
                            onkeydown: function (e) {
                                if (e.key === 'Enter') {
                                    e.preventDefault();
                                    this.blur();
                                }
                            },
                            onchange: function () {
                                var number = parseNumber(this.value);
                                if (number !== null) {
                                    number = Math.round(number * Math.pow(10, decimals)) / Math.pow(10, decimals);
                                }
                                self.setValue(item.id, number, true);
                            }
                        }),
                        s.unit ? el('span', {class: 'cko-measure__unit', text: s.unit}) : null,
                        range ? el('span', {
                            class: 'cko-measure__target',
                            id: 'cko-r-' + item.id,
                            title: rangeState === ' is-out-of-range' ? t.out_of_range : (rangeState ? t.in_range : null),
                            text: format(t.target, range)
                        }) : null
                    ])
                ]);

            case 'choice':
                var chosen = (value === undefined || value === null) ? null : parseInt(value, 10);
                return el('div', {class: 'cko-item__main cko-item__main--stacked'}, [
                    label,
                    el('span', {class: 'cko-choice', role: 'group', 'aria-labelledby': labelId}, (s.options || []).map(function (option, i) {
                        var on = chosen === i;
                        return el('button', {
                            type: 'button',
                            class: 'cko-choice__option' + (on ? ' is-on' : ''),
                            'aria-pressed': on ? 'true' : 'false',
                            'data-focus': 'choice-' + i + '-' + item.id,
                            text: option,
                            onclick: function () {
                                self.focusAfterRender = 'choice-' + i + '-' + item.id;
                                self.setValue(item.id, on ? null : i, true);
                            }
                        });
                    }))
                ]);

            case 'counter':
                var count = parseInt(value, 10) || 0;
                var target = parseInt(s.target, 10) || 0;
                return el('div', {class: 'cko-item__main cko-item__main--stacked'}, [
                    label,
                    el('span', {class: 'cko-counter' + (target > 0 && count >= target ? ' is-reached' : '')}, [
                        iconButton('minus', t.decrease, function () {
                            self.focusAfterRender = 'minus-' + item.id;
                            self.setValue(item.id, count - 1 > 0 ? count - 1 : null, true);
                        }, {class: 'cko-iconbtn cko-counter__minus', disabled: count <= 0, 'data-focus': 'minus-' + item.id}),
                        el('output', {class: 'cko-counter__value', 'aria-live': 'polite'}, [
                            el('span', {text: String(count)}),
                            target > 0 ? el('span', {class: 'cko-counter__target', text: ' / ' + target}) : null
                        ]),
                        iconButton('plus', t.increase, function () {
                            self.focusAfterRender = 'plus-' + item.id;
                            self.setValue(item.id, count + 1, true);
                        }, {class: 'cko-iconbtn cko-counter__plus', 'data-focus': 'plus-' + item.id})
                    ])
                ]);

            case 'note':
                var field = el(s.multiline ? 'textarea' : 'input', {
                    class: 'cko-input cko-note',
                    rows: s.multiline ? 3 : null,
                    type: s.multiline ? null : 'text',
                    maxlength: 2000,
                    value: value || '',
                    'aria-labelledby': this.editing ? null : labelId,
                    'aria-label': this.editing ? (item.label || t.item_label) : null,
                    'data-focus': 'note-' + item.id,
                    oninput: function () {
                        if (this.tagName === 'TEXTAREA') {
                            this.style.height = 'auto';
                            this.style.height = this.scrollHeight + 'px';
                        }
                        self.setNoteDebounced(item.id, this.value);
                    }
                });
                return el('div', {class: 'cko-item__main cko-item__main--stacked'}, [label, field]);

            default:
                return el('div', {class: 'cko-item__main'}, [label]);
        }
    };

    /* ==========================================================
       BOOT
       ========================================================== */

    $('[data-cko-checklist]').each(function () {
        try {
            new Checklist(this);
        } catch (e) {
            if (window.console) {
                window.console.error('CheckOff:', e);
            }
        }
    });

    enhanceForms();
    renderRecent();

    // Leaving with an unsent structure change: try once more, and warn.
    window.addEventListener('beforeunload', function (e) {
        var pending = $('[data-cko-checklist]').filter(function () {
            return this.classList.contains('is-editing');
        }).length;
        if (pending && document.querySelector('.cko-status--busy')) {
            e.preventDefault();
            e.returnValue = '';
        }
    });
});
