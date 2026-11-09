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

    /** JSON with object keys sorted: equal data gives equal text whatever order its keys came in. */
    function stableJSON(value) {
        if (Array.isArray(value)) {
            return '[' + value.map(stableJSON).join(',') + ']';
        }
        if (value && typeof value === 'object') {
            return '{' + Object.keys(value).sort().filter(function (key) {
                return value[key] !== undefined;
            }).map(function (key) {
                return JSON.stringify(key) + ':' + stableJSON(value[key]);
            }).join(',') + '}';
        }
        return JSON.stringify(value === undefined ? null : value);
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

    /** The icon of a block type: its module's Lucide icon, else the type's own symbol. */
    function typeIcon(type) {
        if (typeOf(type).icon) {
            return icon(typeOf(type).icon, 'cko-icon--type');
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

    /**
     * Remembers a list opened on this device. A private list is remembered
     * without its title: the device may be shared, and the title is part
     * of what the password guards.
     */
    function recentRemember(id, title, isPrivate) {
        var list = recentList().filter(function (entry) {
            return entry && entry.id !== id;
        });
        list.unshift(isPrivate ? {id: id, title: '', private: true, t: Date.now()} : {id: id, title: title, t: Date.now()});
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
                        icon(entry.private ? 'eye-off' : 'list-checks'),
                        el('span', {class: 'cko-recent__name', text: entry.private ? t.sealed_title : (entry.title || entry.id)}),
                        el('code', {class: 'cko-recent__id', text: entry.id})
                    ]),
                    iconButton('x', t.recent_remove, function () {
                        recentForget(entry.id);
                    }, {class: 'cko-iconbtn cko-recent__remove'})
                ]));
            });

            // Forgetting them all at once, e.g. on a shared device.
            var clear = section.querySelector('.cko-recent__clear');
            if (!clear) {
                clear = button('trash-2', t.recent_clear, function () {
                    confirmDialog(t.recent_clear_confirm, t.recent_clear, true).then(function (ok) {
                        if (ok) {
                            storageSet(RECENT_KEY, []);
                            renderRecent();
                        }
                    });
                }, {class: 'cko-btn cko-recent__clear'});
                list.parentNode.insertBefore(clear, list.nextSibling);
            }

            section.hidden = false;
        });
    }

    /* ==========================================================
       CREATE FORM
       ========================================================== */

    /** The first day of a repeating template's copy: asked only while such a template is chosen. */
    function syncStartField(form) {
        var field = form.querySelector('[data-cko-start]');
        if (!field) {
            return;
        }
        var chosen = form.querySelector('[name="type"]:checked') || form.querySelector('input[type="hidden"][name="type"]');
        field.hidden = !(chosen && chosen.getAttribute('data-repeats'));
    }

    Array.prototype.forEach.call(document.querySelectorAll('[data-cko-create]'), syncStartField);
    $(document).on('change', '[data-cko-create] [name="type"]', function () {
        syncStartField(this.form);
    });

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
            start: $form.find('[data-cko-start]:not([hidden]) [name="start"]').val() || '',
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
        this.stages = data.stages || {};         // board: entry ID => 'doing'
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
        this.history = data.history || null;     // {rounds, index, days}: only with edit access, only while kept
        this.times = data.times || null;         // entry ID => unix time it was set; same rule
        this.past = null;                        // a kept round being looked at: {number, start, end, structure, values, times}
        this.upcoming = data.upcoming || [];     // the rounds ahead that may be ticked in advance: [{number, start, end}]
        this.future = null;                      // a round ahead being ticked: {number, start, end, values, stages, times}
        this.cardOpen = {};                      // board: entry ID => card opened to enter a value
        this.viewOpen = false;                   // edit mode: the view panel open
        this.live = null;                        // the current state while another round is shown
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
        this.viewKey = 'cko_view_' + this.id;
        this.viewChoice = storageGet(this.viewKey, null); // list or board on this device; null: the list's own
        this.collapsed = storageGet(this.collapsedKey, {});

        if (this.editing && window.history && window.history.replaceState) {
            window.history.replaceState(null, '', window.location.pathname + window.location.search);
        }

        // A list just created opens ready to type its first entry.
        if (this.editing && this.structure.items.length) {
            this.focusAfterRender = 'new-' + this.structure.items[0].id;
        }

        recentRemember(this.id, this.structure.title, this.level === 'private');
        this.render();
        this.startSync();
        this.startQueue();
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

    /* ---------- progress ---------- */

    /** {done, total} of one entry, by its block's own rule. */
    Checklist.prototype.entryProgress = function (block, item) {
        return typeOf(block.type).progress(this.values[item.id], block.settings || {}, blockDef(block.type));
    };

    /** Whether an entry is done: it holds a value, and that value fulfils its block's rule. */
    Checklist.prototype.isItemDone = function (block, item) {
        var part = this.entryProgress(block, item);
        return hasValue(this.values[item.id]) && part.total > 0 && part.done >= part.total;
    };

    /** {done, total} of a block including its sub-lists. */
    Checklist.prototype.progress = function (block) {
        var self = this;
        var result = {done: 0, total: 0};

        block.items.forEach(function (item) {
            var part = item.kind === 'block' ? self.progress(item) : self.entryProgress(block, item);
            result.done += part.done;
            result.total += part.total;
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
        var name = {busy: 'loader-circle', ok: 'circle-check', error: 'cloud-alert', offline: 'wifi-off'}[st.kind];

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
                self.stages = res.data.stages || {};
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

        // A past round is only looked at.
        if (this.past) {
            return;
        }

        var before = this.progress(this.structure);

        if (value === null || value === undefined || value === '' || value === false) {
            delete this.values[itemId];
            if (this.times) {
                delete this.times[itemId];
            }
        } else {
            this.values[itemId] = value;
            if (this.times) {
                this.times[itemId] = Math.floor(Date.now() / 1000);
            }
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
        this.sendChange(itemId, 'value', value === undefined ? null : value);
    };

    /** Sets the board's stage of one entry ('doing' or null), optimistically, and sends it. */
    Checklist.prototype.setStage = function (itemId, stage, rerender) {
        if (this.past) {
            return;
        }

        if (stage === 'doing') {
            this.stages[itemId] = 'doing';
        } else {
            delete this.stages[itemId];
        }
        if (this.times) {
            this.times[itemId] = Math.floor(Date.now() / 1000);
        }

        if (rerender) {
            this.render();
        }

        this.status(t.saving, 'busy');
        this.sendChange(itemId, 'stage', stage === 'doing' ? 'doing' : '');
    };

    /*
     * ---------- ticking without a connection ----------
     *
     * A tick that cannot reach the server (no network) waits in the
     * browser (localStorage, per list) with the time it was made, and is
     * sent once there is a connection again — when the browser says it is
     * online, on the next poll, or when the page is opened again. The
     * server keeps that time and refuses a tick whose round has ended since
     * (409). A newer tick of the same entry replaces a waiting one.
     */

    Checklist.prototype.queueKey = function () {
        return 'cko_queue_' + this.id.replace(/[^a-z0-9]/gi, '').toLowerCase();
    };

    Checklist.prototype.queue = function () {
        var queue = storageGet(this.queueKey(), {});
        return queue && typeof queue === 'object' ? queue : {};
    };

    Checklist.prototype.saveQueue = function (queue) {
        if (Object.keys(queue).length) {
            storageSet(this.queueKey(), queue);
        } else {
            try {
                window.localStorage.removeItem(this.queueKey());
            } catch (e) {
                // Nothing stored then either.
            }
        }
    };

    Checklist.prototype.queueSize = function () {
        return Object.keys(this.queue()).length;
    };

    /** The request data of a change: a value, or a stage. */
    function changeData(kind, payload) {
        return kind === 'stage' ? {stage: payload || ''} : {value: JSON.stringify(payload === undefined ? null : payload)};
    }

    /** The queue's key of a change: one waiting value and one waiting stage per entry and round. */
    function queueKey(itemId, kind, round) {
        return (round ? round + ':' : '') + (kind === 'stage' ? itemId + '|stage' : itemId);
    }

    /** Sends one change; without a connection it waits in the queue. */
    Checklist.prototype.sendChange = function (itemId, kind, payload, at) {
        var self = this;
        var queue = this.queue();
        var round = this.future ? this.future.number : 0; // a round ahead, ticked in advance
        var key = queueKey(itemId, kind, round);

        // This change supersedes one still waiting for the same entry.
        if (!at && queue[key]) {
            delete queue[key];
            this.saveQueue(queue);
        }

        this.pending[itemId] = (this.pending[itemId] || 0) + 1;

        return ckoPost('cko_set_value', Object.assign({
            id: this.id,
            item: itemId,
            at: at || '',
            round: round || ''
        }, changeData(kind, payload))).done(function (res) {
            if (res && res.success) {
                self.status(self.queueSize() ? format(t.offline_waiting, self.queueSize()) : t.saved, self.queueSize() ? 'offline' : 'ok');
            } else {
                self.status(t.save_failed, 'error');
            }
        }).fail(function (xhr) {
            if (!xhr || xhr.status === 0) {
                // No connection: keep it, with the time it was made.
                var waiting = self.queue();
                waiting[key] = {item: itemId, kind: kind, value: payload, at: at || Math.floor(Date.now() / 1000), round: round};
                self.saveQueue(waiting);
                self.status(format(t.offline_waiting, Object.keys(waiting).length), 'offline');
                return;
            }
            self.status(errorMessage(xhr, t.save_failed), 'error');
        }).always(function () {
            self.pending[itemId] -= 1;
            if (self.pending[itemId] <= 0) {
                delete self.pending[itemId];
            }
        });
    };

    /** On opening: shows what still waits, sends it, and listens for the connection. */
    Checklist.prototype.startQueue = function () {
        var self = this;
        var queue = this.queue();

        Object.keys(queue).forEach(function (key) {
            var entry = queue[key];
            var itemId = entry.item || key;
            if (entry.round) {
                return; // a round ahead: shown when it is paged to
            }
            if (entry.kind === 'stage') {
                if (entry.value === 'doing') {
                    self.stages[itemId] = 'doing';
                } else {
                    delete self.stages[itemId];
                }
            } else if (entry.value === null || entry.value === undefined) {
                delete self.values[itemId];
            } else {
                self.values[itemId] = entry.value;
            }
            if (self.times) {
                self.times[itemId] = entry.at;
            }
        });

        if (Object.keys(queue).length) {
            this.status(format(t.offline_waiting, Object.keys(queue).length), 'offline');
            this.render();
            this.flushQueue();
        }

        window.addEventListener('online', function () {
            self.flushQueue();
        });
    };

    /** Sends the waiting changes one after the other; stops at the first missing connection. */
    Checklist.prototype.flushQueue = function () {
        var self = this;
        var queue = this.queue();
        var keys = Object.keys(queue);

        if (this.flushing || !keys.length) {
            return;
        }
        this.flushing = true;

        var key = keys[0];
        var entry = queue[key];

        function next(rest) {
            self.flushing = false;
            if (Object.keys(rest).length) {
                self.flushQueue();
            } else {
                self.poll();
            }
        }

        ckoPost('cko_set_value', Object.assign({
            id: this.id,
            item: entry.item || key,
            at: entry.round ? '' : entry.at,
            round: entry.round || ''
        }, changeData(entry.kind, entry.value))).done(function (res) {
            var rest = self.queue();
            delete rest[key];
            self.saveQueue(rest);
            if (!res || !res.success) {
                toast(t.save_failed, {kind: 'error', icon: 'cloud-alert'});
            } else if (!Object.keys(rest).length) {
                self.status(t.offline_sent, 'ok');
            }
            next(rest);
        }).fail(function (xhr) {
            if (!xhr || xhr.status === 0) {
                self.flushing = false;
                return; // Still no connection: try again later.
            }
            // Refused (its round has ended, the entry is gone …): drop it, say so, go on.
            var rest = self.queue();
            delete rest[key];
            self.saveQueue(rest);
            toast(errorMessage(xhr, t.save_failed), {kind: 'error', icon: 'cloud-alert', duration: 8000});
            if (!Object.keys(rest).length) {
                self.status('', '');
            }
            next(rest);
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

        return this.dirty || this.inflight || this.saveTimer !== null || this.locked || !!this.past || !!this.future || this.queueSize() > 0 || typing ||
            Object.keys(this.noteTimers).some(function (id) {
                return this.noteTimers[id] !== null;
            }, this);
    };

    Checklist.prototype.poll = function () {
        var self = this;

        // Ticks waiting for a connection go first; the poll follows once they are through.
        if (this.queueSize() > 0) {
            this.flushQueue();
            return;
        }

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
            // Compared without key order: the server's answer lists the same
            // values in another order than this page's own ticks added them.
            var before = stableJSON([self.structure, self.values, self.stages, self.round, self.last || null, self.upcoming, self.history]);

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
            if (data.stages) {
                var stages = data.stages;
                Object.keys(self.pending).forEach(function (id) {
                    if (self.stages[id]) {
                        stages[id] = self.stages[id];
                    } else {
                        delete stages[id];
                    }
                });
                self.stages = stages;
            }
            if (data.times && self.times) {
                Object.keys(self.pending).forEach(function (id) {
                    if (Object.prototype.hasOwnProperty.call(self.times, id)) {
                        data.times[id] = self.times[id];
                    }
                });
                self.times = data.times;
            }
            self.round = data.round || self.round;
            self.last = data.last || null;
            self.upcoming = data.upcoming || [];
            if (data.history) {
                self.history = data.history;
            }
            self.state = data.state;

            if (stableJSON([self.structure, self.values, self.stages, self.round, self.last || null, self.upcoming, self.history]) !== before) {
                self.render();
                self.status(t.updated, 'ok');
            }
        }).always(function () {
            self.polling = false;
        });
    };

    /** Sends the notes still waiting for a pause in typing at once — before the round shown changes. */
    Checklist.prototype.flushNotes = function () {
        var self = this;

        Object.keys(this.noteTimers).forEach(function (itemId) {
            if (self.noteTimers[itemId] !== null) {
                clearTimeout(self.noteTimers[itemId]);
                self.noteTimers[itemId] = null;
                var text = self.values[itemId];
                self.setValue(itemId, text === undefined || String(text).trim() === '' ? null : text, false);
            }
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

        var type = typeOf(def.key);
        // Entry data (a due date) belongs to its block type; the server drops it too.
        block.items.forEach(function (item) {
            if (item.kind === 'item' && !type.itemEditor) {
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

        if (this.past) {
            // A past round: the list as it was, every control disabled at once.
            this.root.appendChild(this.renderPastBar());
            var card = el('div', {class: 'cko-card cko-card--past'}, [this.renderBlock(this.structure, 0)]);
            Array.prototype.forEach.call(card.querySelectorAll('.cko-sections input, .cko-sections button, .cko-sections select, .cko-sections textarea, .cko-board input, .cko-board__cell button, .cko-board__lane button, .cko-board select, .cko-board textarea'), function (control) {
                control.disabled = true;
            });
            this.root.appendChild(card);
        } else if (this.future) {
            // A round ahead: the list as it is, ticked in advance.
            this.root.appendChild(this.renderFutureBar());
            this.root.appendChild(el('div', {class: 'cko-card cko-card--future'}, [this.renderBlock(this.structure, 0)]));
        } else {
            this.root.appendChild(el('div', {class: 'cko-card'}, [this.renderBlock(this.structure, 0)]));
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

        if (typeof window.Sortable !== 'function') {
            return;
        }

        if (!this.editing) {
            this.initBoardSortable();
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
                'aria-label': label, // the text is hidden on narrow screens
                title: label,
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

        if (this.past || this.future) {
            start = null; // another round: ticking only, the structure is the current round's business
        } else if (this.canEdit && !this.locked) {
            start = el('div', {class: 'cko-mode', role: 'group', 'aria-label': t.mode}, [
                modeButton(false, 'check', t.mode_tick),
                modeButton(true, 'pencil', t.mode_edit)
            ]);
        } else if (this.passwordLocked && !this.locked) {
            start = button('lock', t.unlock_to_edit, function () {
                self.unlockDialog();
            }, {class: 'cko-btn cko-unlock', 'data-focus': 'unlock'});
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

        // Access and the rare actions are for whoever may edit; the lock
        // only where there is a password whose unlock this device holds.
        var access = null;
        var lockDevice = null;
        var more = null;
        if (this.canEdit) {
            access = iconButton('shield', t.access + ': ' + t['level_' + this.level], function () {
                self.openAccessMenu(this);
            }, {'aria-haspopup': this.canList() ? 'menu' : 'dialog', 'data-focus': 'access', class: 'cko-iconbtn cko-access' + (this.listed ? ' is-listed' : '')});
            if (this.isProtected) {
                lockDevice = iconButton('lock', t.password_lock, function () {
                    self.lockAgain();
                }, {'data-focus': 'lock-device', class: 'cko-iconbtn cko-lockdevice'});
            }
            more = iconButton('ellipsis', t.more_actions, function () {
                self.openMoreMenu(this);
            }, {'aria-haspopup': 'menu', 'data-focus': 'more'});
        }

        return el('div', {class: 'cko-bar'}, [
            start,
            el('div', {class: 'cko-bar__end'}, [
                this.renderSaveState(),
                subscribe,
                lockDevice,
                access,
                iconButton('share-2', t.share, function () {
                    openMenu(this, null, self.renderSharePanel());
                }, {'aria-haspopup': 'dialog', 'aria-expanded': 'false', 'data-focus': 'share'}),
                more
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

        return el('div', {class: 'cko-share'}, [
            el('p', {class: 'cko-share__title', text: t.share}),
            row('link', t.link_list, this.url, t['link_hint_' + this.level]),
            row('key-round', t.list_id, this.id, t.id_hint)
        ]);
    };

    /** The round in force and how the last one went, for repeating lists. */
    Checklist.prototype.renderRound = function () {
        var self = this;
        var r = this.round;

        if (!r || !r.repeats) {
            return null;
        }

        var other = this.past || this.future;
        var main = other ? format(t.round, other.number) : (r.finished ? t.series_finished : (r.total ? format(t.round_of, r.number, r.total) : format(t.round, r.number)));
        var when = other ? (other.start === other.end ? other.start : format(t.round_dates, other.start, other.end)) :
            (r.finished ? '' : (r.before_start ? format(t.starts_on, r.start) : (r.start === r.end ? r.start : format(t.round_dates, r.start, r.end))));
        var nav = this.roundNeighbours();

        // Paging through the kept rounds replaces the summary of the last one.
        var history = !!(this.history && this.history.index && this.history.index.length > 0);
        var paging = history || this.upcoming.length > 0;

        function go(number) {
            if (number === r.number) {
                self.showLive();
            } else {
                self.showRound(number);
            }
        }

        return el('div', {class: 'cko-round' + (this.past ? ' is-past' : '') + (this.future ? ' is-future' : '')}, [
            paging ? iconButton('chevron-left', nav.previous === r.number ? t.round_current : t.round_previous, function () {
                go(nav.previous);
            }, {class: 'cko-iconbtn cko-round__nav', disabled: nav.previous === null, 'data-focus': 'round-previous'}) : null,
            el('span', {class: 'cko-chip cko-chip--accent'}, [icon(this.past ? 'history' : (this.future ? 'calendar-clock' : 'repeat')), el('span', {text: main})]),
            paging ? iconButton('chevron-right', nav.next === r.number ? t.round_current : t.round_next, function () {
                go(nav.next);
            }, {class: 'cko-iconbtn cko-round__nav', disabled: nav.next === null, 'data-focus': 'round-next'}) : null,
            // Dates and rule in one quiet line: they qualify the round, they are not controls.
            when || !other ? el('span', {class: 'cko-round__when'}, [
                icon('calendar-days'),
                el('span', {text: [when, other ? '' : r.description].filter(Boolean).join(' · ')})
            ]) : null,
            !history && !other && this.last ? el('span', {
                class: 'cko-round__last',
                text: format(t.last_round, format(t.round, this.last.number), this.last.done, this.last.total)
            }) : null
        ]);
    };

    /**
     * The rounds before and after the one shown, by number: the kept past
     * rounds, the round in force and the rounds ahead (null: none).
     */
    Checklist.prototype.roundNeighbours = function () {
        var live = this.round && this.round.number;
        var numbers = ((this.history && this.history.index) || []).concat(this.upcoming).map(function (entry) {
            return entry.number;
        }).concat(live ? [live] : []);
        var shown = this.past ? this.past.number : (this.future ? this.future.number : live);
        var previous = null;
        var next = null;

        numbers.forEach(function (number) {
            if (number < shown && (previous === null || number > previous)) {
                previous = number;
            }
            if (number > shown && (next === null || number < next)) {
                next = number;
            }
        });

        return {previous: previous, next: next};
    };

    /** Loads another round: a kept one, read only, or one ahead, to tick in advance. */
    Checklist.prototype.showRound = function (number) {
        var self = this;
        var ahead = this.round && number > this.round.number;

        if (number === null || this.loadingRound) {
            return;
        }
        this.flushNotes();
        this.loadingRound = true;

        ckoPost(ahead ? 'cko_upcoming_round' : 'cko_history_round', {id: this.id, number: number}).done(function (res) {
            if (!res || !res.success) {
                toast(t.history_failed, {kind: 'error', icon: 'cloud-alert'});
                return;
            }
            if (!self.live) {
                self.live = {structure: self.structure, values: self.values, stages: self.stages, times: self.times, editing: self.editing};
            }
            var round = res.data;
            round.structure.recurrence = round.structure.recurrence || {freq: 'none'};
            self.past = ahead ? null : round;
            self.future = ahead ? round : null;
            self.structure = ahead ? self.live.structure : round.structure;
            self.values = round.values || {};
            self.stages = round.stages || {};
            // Times stay the owner's, as in the round in force.
            self.times = (ahead && !self.live.times) ? null : (round.times || {});
            self.editing = false;
            var nav = self.roundNeighbours();
            self.focusAfterRender = nav.next === null ? 'round-previous' : (nav.previous === null ? 'round-next' : (ahead ? 'round-next' : 'round-previous'));
            self.render();
        }).fail(function (xhr) {
            toast(errorMessage(xhr, t.history_failed), {kind: 'error', icon: 'cloud-alert'});
        }).always(function () {
            self.loadingRound = false;
        });
    };

    /** Back from another round to the round in force. */
    Checklist.prototype.showLive = function () {
        if (!this.live) {
            return;
        }
        this.flushNotes();
        var wasAhead = !!this.future;
        this.structure = this.live.structure;
        this.values = this.live.values;
        this.stages = this.live.stages;
        this.times = this.live.times;
        this.editing = this.live.editing;
        this.live = null;
        this.past = null;
        this.future = null;
        this.focusAfterRender = wasAhead ? 'round-next' : 'round-previous';
        this.render();
        this.poll();
    };

    /** The line above a past round: what it is, and the way back. */
    Checklist.prototype.renderPastBar = function () {
        var self = this;

        return el('div', {class: 'cko-pastbar', role: 'status'}, [
            icon('history'),
            el('span', {class: 'cko-pastbar__text', text: t.round_past}),
            button('rotate-ccw', t.round_current, function () {
                self.showLive();
            }, {class: 'cko-btn cko-pastbar__back', 'data-focus': 'round-live'})
        ]);
    };

    /** The line above a round ahead: what ticking here means, and the way back. */
    Checklist.prototype.renderFutureBar = function () {
        var self = this;

        return el('div', {class: 'cko-pastbar cko-pastbar--future', role: 'status'}, [
            icon('calendar-clock'),
            el('span', {class: 'cko-pastbar__text', text: format(t.round_ahead, this.future.start)}),
            button('rotate-ccw', t.round_current, function () {
                self.showLive();
            }, {class: 'cko-btn cko-pastbar__back', 'data-focus': 'round-live'})
        ]);
    };

    /** When an entry's value was set: the time today, else date and time. */
    Checklist.prototype.renderTime = function (itemId) {
        var stamp = this.times && this.times[itemId];

        if (!stamp || this.values[itemId] === undefined) {
            return null;
        }

        var date = new Date(stamp * 1000);
        var today = new Date();
        var sameDay = date.toDateString() === today.toDateString();
        var text;

        try {
            text = sameDay
                ? date.toLocaleTimeString(cfg.locale, {hour: '2-digit', minute: '2-digit'})
                : date.toLocaleString(cfg.locale, {day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit'});
        } catch (e) {
            text = date.toLocaleString();
        }

        return el('time', {
            class: 'cko-item__time',
            datetime: date.toISOString(),
            title: date.toLocaleString(cfg.locale)
        }, [text]);
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

        var ahead = this.upcoming.filter(function (round) {
            return round.ticked > 0;
        }).length;

        return el('div', {class: 'cko-block__settings cko-recurrence'}, fields.concat([
            repeats ? el('span', {class: 'cko-setting__desc cko-recurrence__hint', text: t.repeat_hint}) : null,
            ahead ? el('p', {class: 'cko-block__note cko-recurrence__ahead'}, [
                icon('triangle-alert'),
                el('span', {text: format(ahead === 1 ? t.repeat_ahead_one : t.repeat_ahead, ahead)})
            ]) : null
        ]));
    };

    /** Whether the list may be shown publicly (protected, directory on). */
    Checklist.prototype.canList = function () {
        return !!cfg.lists_enabled && this.level === 'protected';
    };

    /**
     * The shield beside the bell: the access dialog, or — where the list may
     * be shown publicly — a menu with it and the directory switch.
     */
    Checklist.prototype.openAccessMenu = function (anchor) {
        var self = this;

        if (!this.canList()) {
            this.accessDialog();
            return;
        }

        var items = [
            {icon: 'shield', label: t.access_change, run: function () { self.accessDialog(); }},
            {separator: true},
            this.listed
                ? {icon: 'eye-off', label: t.listed_stop, run: function () { self.setListed(false); }}
                : {icon: 'globe', label: t.listed, run: function () {
                    confirmDialog(t.listed_hint, t.listed).then(function (ok) {
                        if (ok) {
                            self.setListed(true);
                        }
                    });
                }}
        ];

        openMenu(anchor, items);
    };

    /** The ⋯ beside the share button: clearing the ticks, deleting the list. */
    Checklist.prototype.openMoreMenu = function (anchor) {
        var self = this;

        var items = [
            {icon: 'rotate-ccw', label: t.reset_values, disabled: !!this.past || !!this.future, run: function () { self.resetValues(); }}
        ];

        if (this.history && this.history.index && this.history.index.length) {
            items.push({icon: 'history', label: t.history_delete, run: function () { self.deleteHistory(); }});
        }

        items.push({separator: true});
        items.push({icon: 'trash-2', label: t.delete_list, danger: true, run: function () { self.deleteList(); }});

        openMenu(anchor, items);
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
                    recentRemember(self.id, self.structure.title, self.level === 'private');
                    close();
                    self.focusAfterRender = 'access';
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
            self.focusAfterRender = 'access';
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

    /* ==========================================================
       BOARD: the checklist as a Kanban board
       ==========================================================

       Sections are lanes, the columns are the three stages. Where a card
       stands follows from its value (stageOf(), mirroring the server's
       stage_of()): done by the block's own rule, in progress when
       something is entered or the entry is marked so (its stage, saved
       beside its value), else open. Moving a card sets values; it never changes the
       structure, so whoever may tick may move cards.
    */

    var STAGES = ['open', 'doing', 'done'];
    var STAGE_ICONS = {open: 'circle', doing: 'loader-circle', done: 'circle-check'};

    /** The names of the three columns: the list's own, else the defaults. */
    function stageNames(root) {
        var own = root.stages || [];
        return {
            open: own[0] || t.stage_open,
            doing: own[1] || t.stage_doing,
            done: own[2] || t.stage_done
        };
    }

    /** Where a card stands. */
    Checklist.prototype.stageOf = function (block, item) {
        if (hasValue(this.values[item.id])) {
            var part = this.entryProgress(block, item);
            return (part.total === 0 || part.done >= part.total) ? 'done' : 'doing';
        }

        return this.stages[item.id] === 'doing' ? 'doing' : 'open';
    };

    /** The cards of a lane, through its sub-lists. */
    Checklist.prototype.collectCards = function (block, path, out) {
        var self = this;

        block.items.forEach(function (item) {
            if (item.kind === 'block') {
                self.collectCards(item, item.title ? path.concat([item.title]) : path, out);
            } else {
                out.push({block: block, item: item, path: path.join(' › ')});
            }
        });
    };

    Checklist.prototype.renderBoard = function (root) {
        var self = this;
        var names = stageNames(root);
        var lanes = [];
        var counts = {open: 0, doing: 0, done: 0};

        this.cardIndex = {};

        root.items.forEach(function (section) {
            var cards = [];
            self.collectCards(section, [], cards);
            cards.forEach(function (card) {
                card.stage = self.stageOf(card.block, card.item);
                counts[card.stage] += 1;
                self.cardIndex[card.item.id] = card;
            });
            lanes.push({section: section, cards: cards});
        });

        var grid = el('div', {class: 'cko-board__grid', role: 'region', 'aria-label': t.view_board});

        STAGES.forEach(function (stage) {
            grid.appendChild(el('div', {class: 'cko-board__head cko-board__head--' + stage}, [
                icon(STAGE_ICONS[stage]),
                el('span', {class: 'cko-board__name', text: names[stage]}),
                el('span', {class: 'cko-board__count', text: String(counts[stage])})
            ]));
        });

        lanes.forEach(function (lane) {
            var section = lane.section;

            if (section.title) {
                grid.appendChild(el('div', {class: 'cko-board__lane'}, [
                    el('h3', {class: 'cko-board__lane-title'}, [
                        el('span', {class: 'cko-block__type cko-block__type--quiet', title: blockDef(section.type).label}, [typeIcon(section.type)]),
                        el('span', {text: section.title})
                    ])
                ]));
            }

            STAGES.forEach(function (stage) {
                var cell = el('div', {
                    class: 'cko-board__cell cko-board__cell--' + stage,
                    'data-board-cell': '1',
                    'data-stage': stage,
                    'data-lane': section.id,
                    'aria-label': names[stage]
                });
                lane.cards.forEach(function (card) {
                    if (card.stage === stage) {
                        cell.appendChild(self.renderBoardCard(card, names));
                    }
                });
                grid.appendChild(cell);
            });
        });

        return el('div', {class: 'cko-board'}, [grid]);
    };

    Checklist.prototype.renderBoardCard = function (card, names) {
        var self = this;
        var id = card.item.id;
        var open = !!this.cardOpen[id];
        var value = this.values[id];
        var summary = open ? '' : typeOf(card.block.type).summary(value, card.block.settings || {});
        var stamp = (this.times && this.times[id]) || 0;
        var meta = typeOf(card.block.type).itemMeta(card.item, value, card.block.settings || {});

        var moveItems = STAGES.map(function (stage) {
            return {
                icon: STAGE_ICONS[stage],
                label: format(t.move_to, names[stage]),
                disabled: stage === card.stage,
                run: function () {
                    self.moveCard(card, stage);
                }
            };
        });

        return el('div', {
            class: 'cko-kcard cko-kcard--' + card.stage + (open ? ' is-open' : ''),
            'data-card': id
        }, [
            el('div', {class: 'cko-kcard__head'}, [
                el('button', {
                    type: 'button',
                    class: 'cko-kcard__label',
                    'aria-expanded': open ? 'true' : 'false',
                    'data-focus': 'card-' + id,
                    onclick: function () {
                        self.cardOpen[id] = !open;
                        self.focusAfterRender = 'card-' + id;
                        self.render();
                    }
                }, [
                    card.stage === 'done' ? icon('circle-check', 'cko-kcard__done') : null,
                    el('span', {text: card.item.label || '—'})
                ]),
                iconButton('arrow-right-left', t.move_card, function () {
                    openMenu(this, moveItems);
                }, {class: 'cko-iconbtn cko-kcard__move', 'aria-haspopup': 'menu', 'data-focus': 'move-' + id})
            ]),
            (card.path || summary || stamp || meta) && !open ? el('p', {class: 'cko-kcard__meta'}, [
                card.path ? el('span', {class: 'cko-kcard__path', text: card.path}) : null,
                summary ? el('span', {class: 'cko-kcard__value', text: summary}) : null,
                meta,
                stamp ? this.renderStamp(stamp) : null
            ]) : null,
            open ? el('ul', {class: 'cko-items cko-kcard__body'}, [this.renderItem(card.block, card.item)]) : null
        ]);
    };

    /** A time as the entries show it. */
    Checklist.prototype.renderStamp = function (stamp) {
        var saved = this.times;
        this.times = {stamp: stamp};
        var node = this.renderTime('stamp');
        this.times = saved;
        return node;
    };

    /** Moves a card: sets values, never the structure. */
    Checklist.prototype.moveCard = function (card, stage) {
        var self = this;
        var id = card.item.id;
        var before = {value: this.values[id], mark: this.stages[id] === 'doing'};
        var had = before.value !== undefined && before.value !== null;

        if (stage === card.stage) {
            return;
        }

        function undo() {
            self.setValue(id, had ? before.value : null, false);
            self.setStage(id, before.mark ? 'doing' : null, true);
        }

        if (stage === 'open') {
            if (had) {
                this.setValue(id, null, !before.mark);
            }
            if (before.mark) {
                this.setStage(id, null, true);
            }
            if (had) {
                toast(format(t.card_moved, names(this)[stage]), {icon: STAGE_ICONS[stage], action: {label: t.undo, run: undo}, duration: 6000});
            }
            return;
        }

        if (stage === 'doing') {
            if (had && card.stage === 'done') {
                this.setValue(id, null, false);
            }
            this.setStage(id, 'doing', true);
            if (had && card.stage === 'done') {
                toast(format(t.card_moved, names(this)[stage]), {icon: STAGE_ICONS[stage], action: {label: t.undo, run: undo}, duration: 6000});
            }
            return;
        }

        // Done: at once where the block knows a "done" value; else the card
        // opens for the value, and entering it moves the card.
        var quick = typeOf(card.block.type).doneValue(card.block.settings || {});
        if (quick === undefined) {
            this.cardOpen[id] = true;
            this.focusAfterRender = 'card-' + id;
            this.render();
            toast(t.card_needs_value, {icon: 'pencil'});
            return;
        }
        if (before.mark) {
            this.setStage(id, null, false);
        }
        this.setValue(id, quick, true);

        function names(list) {
            return stageNames(list.structure);
        }
    };

    /** Drag and drop between the columns of a lane. */
    Checklist.prototype.initBoardSortable = function () {
        var self = this;

        if (this.past) {
            return;
        }

        Array.prototype.forEach.call(this.root.querySelectorAll('[data-board-cell]'), function (cell) {
            self.sortables.push(window.Sortable.create(cell, {
                group: 'cko-lane-' + cell.getAttribute('data-lane'),
                draggable: '.cko-kcard',
                filter: 'input, select, textarea, .cko-kcard__move, .cko-kcard__body',
                preventOnFilter: false,
                delay: 180,
                delayOnTouchOnly: true,
                animation: 150,
                ghostClass: 'is-ghost',
                chosenClass: 'is-chosen',
                onEnd: function (evt) {
                    var card = self.cardIndex[evt.item.getAttribute('data-card')];
                    var stage = evt.to.getAttribute('data-stage');
                    if (card && evt.to !== evt.from) {
                        self.moveCard(card, stage);
                    } else {
                        self.render();
                    }
                }
            }));
        });
    };

    /** The view shown: this device's choice, else the list's own. */
    Checklist.prototype.viewOf = function (root) {
        if (this.viewChoice === 'list' || this.viewChoice === 'board') {
            return this.viewChoice;
        }
        return root.view === 'board' ? 'board' : 'list';
    };

    /**
     * List or board, for everybody, beside the title: the choice holds on
     * this device; the list's own view (set while editing) is what others
     * see first.
     */
    Checklist.prototype.renderViewSwitch = function (root) {
        var self = this;
        var current = this.viewOf(root);

        function option(view, name, label) {
            var on = current === view;
            return el('button', {
                type: 'button',
                class: 'cko-viewswitch__btn' + (on ? ' is-on' : ''),
                'aria-pressed': on ? 'true' : 'false',
                'aria-label': label,
                title: label,
                'data-focus': 'view-' + view,
                onclick: function () {
                    if (on) {
                        return;
                    }
                    // Back to the list's own view forgets the choice.
                    self.viewChoice = view === (root.view === 'board' ? 'board' : 'list') ? null : view;
                    if (self.viewChoice) {
                        storageSet(self.viewKey, self.viewChoice);
                    } else {
                        try {
                            window.localStorage.removeItem(self.viewKey);
                        } catch (e) {
                            // Nothing stored then either.
                        }
                    }
                    self.focusAfterRender = 'view-' + view;
                    self.render();
                }
            }, [icon(name)]);
        }

        return el('div', {class: 'cko-viewswitch', role: 'group', 'aria-label': t.view}, [
            option('list', 'list', t.view_list),
            option('board', 'kanban', t.view_board)
        ]);
    };

    /** Edit mode: list or board, and the names of the board's columns. */
    Checklist.prototype.renderViewPanel = function (root) {
        var self = this;
        var board = root.view === 'board';
        var names = stageNames({});
        var own = root.stages || ['', '', ''];

        var select = el('select', {
            class: 'cko-input',
            'data-focus': 'view-select',
            'aria-label': t.view,
            onchange: function () {
                if (this.value === 'board') {
                    root.view = 'board';
                } else {
                    delete root.view;
                }
                self.focusAfterRender = 'view-select';
                self.changed(true);
                self.render();
            }
        }, [
            el('option', {value: 'list', text: t.view_list}),
            el('option', {value: 'board', text: t.view_board})
        ]);
        select.value = board ? 'board' : 'list';

        var fields = [
            el('label', {class: 'cko-setting'}, [el('span', {class: 'cko-setting__label', text: t.view}), select]),
            el('span', {class: 'cko-setting__desc', text: t.view_default_hint})
        ];

        if (board) {
            STAGES.forEach(function (stage, i) {
                fields.push(el('label', {class: 'cko-setting'}, [
                    el('span', {class: 'cko-setting__label', text: format(t.board_column_n, i + 1)}),
                    el('input', {
                        type: 'text',
                        class: 'cko-input',
                        maxlength: 40,
                        value: own[i] || '',
                        placeholder: names[stage],
                        'data-focus': 'stage-' + stage,
                        oninput: function () {
                            var next = (root.stages || ['', '', '']).slice();
                            next[i] = this.value;
                            if (next.join('') === '') {
                                delete root.stages;
                            } else {
                                root.stages = next;
                            }
                            self.changed(false);
                        }
                    })
                ]));
            });
            fields.push(el('span', {class: 'cko-setting__desc', text: t.board_hint}));
        }

        return el('details', {
            class: 'cko-panel cko-view-panel',
            open: this.viewOpen,
            ontoggle: function () {
                self.viewOpen = this.open;
            }
        }, [
            el('summary', {}, [
                icon(board ? 'kanban' : 'list'),
                el('span', {text: t.view}),
                el('strong', {text: board ? t.view_board : t.view_list})
            ]),
            el('div', {class: 'cko-block__settings cko-viewsettings'}, fields)
        ]);
    };

    /* ---------- history of closed rounds (edit access only) ---------- */

    Checklist.prototype.deleteHistory = function () {
        var self = this;

        confirmDialog(t.history_delete_confirm, t.history_delete, true).then(function (ok) {
            if (!ok) {
                return;
            }
            ckoPost('cko_history_delete', {id: self.id}).done(function (res) {
                if (res && res.success) {
                    self.history.rounds = 0;
                    self.history.index = [];
                    if (self.past) {
                        self.showLive();
                    } else {
                        self.render();
                    }
                    toast(t.history_deleted, {icon: 'trash-2'});
                }
            }).fail(function (xhr) {
                toast(errorMessage(xhr, t.save_failed), {kind: 'error', icon: 'cloud-alert'});
            });
        });
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
    Checklist.prototype.renderBlock = function (block, depth) {
        var self = this;
        var isRoot = block.kind === 'checklist';
        var isSection = !isRoot && depth === 1;
        var def = blockDef(block.type);
        var type = typeOf(block.type);
        var editing = this.editing;
        var collapsed = !editing && !isRoot && !!this.collapsed[block.id];
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
                    : el('div', {class: 'cko-head__row'}, [
                        el('h2', {class: 'cko-head__title cko-block__title', text: block.title}),
                        block.items.length ? this.renderViewSwitch(block) : null
                    ]),
                // What the list is about comes right under its name; ticking
                // shows where things stand, editing the round only.
                !editing && block.description ? el('p', {class: 'cko-head__desc', text: block.description}) : null,
                editing ? null : this.renderProgress(block),
                editing ? null : this.renderRound()
            ]);
        } else if (editing || titleText !== '') {
            // An untitled section shows no heading outside editing: it
            // simply continues the list above it.
            header = el('div', {class: 'cko-block__header'}, [
                editing ? el('span', {class: 'cko-handle', title: t.drag, 'aria-hidden': 'true'}, [icon('grip-vertical')]) : null,
                el('span', {class: 'cko-block__type' + (editing ? '' : ' cko-block__type--quiet'), title: def.label}, [typeIcon(block.type)]),
                editing
                    ? el('input', {
                        type: 'text',
                        class: 'cko-input cko-block__title-input',
                        value: block.title,
                        maxlength: 200,
                        placeholder: isSection ? t.section_placeholder : def.label,
                        'aria-label': isSection ? t.section_placeholder : def.label,
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
                // A bar for one entry repeats that entry; while editing it says nothing.
                !editing && this.progress(block).total > 1 ? this.renderProgress(block) : null,
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
            body.appendChild(this.renderViewPanel(block));
        } else if (editing && this.settingsOpen[block.id]) {
            body.appendChild(el('div', {class: 'cko-panel cko-panel--settings'}, [
                this.renderBlockSettings(block, false),
                descField()
            ]));
        }

        if (!editing && !isRoot && block.description) {
            body.appendChild(el('p', {class: 'cko-block__desc', text: block.description}));
        }

        if (!isRoot) {
            type.notes(block, editing).forEach(function (note) {
                body.appendChild(el('p', {class: 'cko-block__note'}, [icon(note.icon), el('span', {text: note.text})]));
            });
        }

        if (isRoot && !editing && this.viewOf(block) === 'board' && block.items.length) {
            body.appendChild(this.renderBoard(block));
        } else if (isRoot) {
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
        } else {
            var list = el('ul', {class: 'cko-items', 'data-sortable': block.id});

            block.items.forEach(function (item, index) {
                if (item.kind === 'block') {
                    list.appendChild(el('li', {class: 'cko-items__entry cko-items__entry--block', 'data-id': item.id}, [
                        self.renderBlock(item, depth + 1)
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

            if (editing) {
                body.appendChild(this.renderNewEntry(block));
            }
        }

        var classes = 'cko-block cko-block--' + (isRoot ? 'checklist' : block.type) + ' cko-block--depth-' + depth;
        classes += isRoot ? ' cko-block--root' : (isSection ? ' cko-block--section' : ' cko-block--nested');
        if (isSection && !titleText) {
            classes += ' cko-block--untitled';
        }
        if (!isRoot && this.progress(block).total > 0 && this.progress(block).done >= this.progress(block).total) {
            classes += ' is-complete';
        }

        return el('section', {class: classes, 'data-block': block.id}, [header, body]);
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
                label: t.add_block,
                disabled: depth >= cfg.max_depth,
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
                typeOf(block.type).itemEditor ? typeOf(block.type).itemEditor(this, block, item) : null,
                iconButton('x', t['delete'], function () {
                    self.remove(item.id);
                }, {class: 'cko-iconbtn cko-item__delete', 'data-focus': 'del-' + item.id})
            ]);
        }

        var label = el('span', {class: 'cko-item__label', text: item.label || '—'});

        return el('li', {
            class: 'cko-items__entry cko-item cko-item--' + block.type + (this.isItemDone(block, item) ? ' is-done' : '') +
                (this.isItemSettled(block, item) ? ' is-settled' : ''),
            'data-id': item.id,
            'data-item': item.id
        }, [this.renderControl(block, item, this.values[item.id], label), this.renderTime(item.id)]);
    };

    /** An entry that holds a value which leaves the count — "not needed". */
    Checklist.prototype.isItemSettled = function (block, item) {
        return hasValue(this.values[item.id]) && blockDef(block.type).progress && this.entryProgress(block, item).total === 0;
    };

    /** The control of one entry, drawn by its block type, with its label. */
    Checklist.prototype.renderControl = function (block, item, value, label) {
        var labelId = 'cko-l-' + item.id;

        if (label.tagName !== 'INPUT') {
            label.id = labelId;
        }

        return typeOf(block.type).control(this, {
            block: block,
            item: item,
            value: value,
            label: label,
            labelId: labelId,
            settings: block.settings || {}
        });
    };

    /* ==========================================================
       BLOCK TYPES
       ==========================================================

       Everything that differs between building blocks lives here, one
       module per block key — the browser's twin of the classes in
       includes/lib/blocks/. The rest of this file never asks for a
       block's type: it calls these methods, and a block not listed gets
       the defaults of BASE_TYPE. Every block therefore works the same way
       everywhere — list, board, history, editor:

       - progress( value, settings )  {done, total} of one entry, the twin
         of Checkoff_Block_Type::progress(). An entry with a value is done
         when total is 0 or done reaches total, else in progress;
       - control( view, c )            the entry's control in tick mode
         (c: block, item, value, label, labelId, settings);
       - summary( value, settings )    a short wording for a closed card;
       - doneValue( settings )         the value that makes an entry done at
         once, undefined where one has to be entered;
       - itemEditor( view, block, item ) / itemMeta( item )  data of an entry
         of its own (the due date) while editing and on a card;
       - notes( block, editing )       hints under the block's heading;
       - icon                           its Lucide icon (else the PHP class's symbol). */

    /** The due date of an entry in words — due on, soon, today, overdue — or null without one. */
    function dueChip(item, value, settings) {
        var due = (item.data && item.data.due) || '';
        if (!due) {
            return null;
        }
        var left = daysUntil(due);
        var soon = parseInt(settings.soon, 10) || 0;
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
        return el('span', {class: 'cko-due' + state, id: 'cko-d-' + item.id, title: t.due_date}, [icon('calendar-clock'), el('span', {text: text})]);
    }

    /** Whether an entry holds anything. */
    function hasValue(value) {
        return value !== undefined && value !== null && value !== false;
    }

    /** A plain checkbox with the entry's label. */
    function checkControl(view, c, checked, onValue) {
        var box = el('input', {
            type: 'checkbox',
            class: 'cko-check',
            checked: checked,
            'aria-labelledby': view.editing ? null : c.labelId,
            'aria-label': view.editing ? (c.item.label || t.item_label) : null,
            'data-focus': 'check-' + c.item.id,
            onchange: function () {
                view.focusAfterRender = 'check-' + c.item.id;
                view.setValue(c.item.id, this.checked ? onValue : null, true);
            }
        });
        return el('span', {class: 'cko-checkbox'}, [box, icon('check')]);
    }

    /**
     * The steps of a colour scale block, left to right: the site's scale,
     * reversed if the block says so, with the block's own names — the
     * twin of Checkoff_Block_Color_Scale::steps_for().
     */
    function scaleFor(settings) {
        var scale = blockDef('color_scale').data.scale || {};
        var steps = clone(scale[settings.steps] || scale[5] || []);
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

    var BASE_TYPE = {
        progress: function (value, settings, def) {
            if (!def.progress) {
                return {done: 0, total: 0};
            }
            return {done: hasValue(value) ? 1 : 0, total: 1};
        },
        control: function (view, c) {
            return el('div', {class: 'cko-item__main'}, [c.label]);
        },
        summary: function (value) {
            return (value === undefined || value === null || value === true) ? '' : String(value);
        },
        doneValue: function () {
            return undefined;
        },
        itemEditor: null,
        itemMeta: function () {
            return null;
        },
        notes: function () {
            return [];
        },
        icon: ''
    };

    var BLOCK_TYPES = {

        simple: {
            icon: 'list-checks',
            control: function (view, c) {
                return el('label', {class: 'cko-item__main'}, [checkControl(view, c, !!c.value, true), c.label]);
            },
            doneValue: function () {
                return true;
            }
        },

        deadline: {
            icon: 'calendar-clock',
            control: function (view, c) {
                var due = (c.item.data && c.item.data.due) || '';
                var chip = dueChip(c.item, c.value, c.settings);
                var box = checkControl(view, c, !!c.value, true);
                if (due) {
                    box.firstChild.setAttribute('aria-describedby', 'cko-d-' + c.item.id);
                }
                return el('label', {class: 'cko-item__main cko-item__main--deadline'}, [box, c.label, chip]);
            },
            doneValue: function () {
                return true;
            },
            itemEditor: function (view, block, item) {
                return el('input', {
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
                        view.changed(false);
                    }
                });
            },
            itemMeta: function (item, value, settings) {
                return dueChip(item, value, settings);
            }
        },

        status: {
            icon: 'ban',
            progress: function (value) {
                // "Not needed" leaves the count: neither done nor open.
                return value === 'skip' ? {done: 0, total: 0} : {done: value === 'done' ? 1 : 0, total: 1};
            },
            control: function (view, c) {
                var skipped = c.value === 'skip';
                return el('div', {class: 'cko-item__main cko-item__main--status'}, [
                    el('label', {class: 'cko-item__tick'}, [checkControl(view, c, c.value === 'done', 'done'), c.label]),
                    skipped ? el('span', {class: 'cko-chip cko-chip--muted cko-status__note', text: t.not_needed}) : null,
                    iconButton('ban', t.mark_not_needed, function () {
                        view.focusAfterRender = 'skip-' + c.item.id;
                        view.setValue(c.item.id, skipped ? null : 'skip', true);
                    }, {
                        class: 'cko-iconbtn cko-status__skip' + (skipped ? ' is-on' : ''),
                        'aria-pressed': skipped ? 'true' : 'false',
                        'data-focus': 'skip-' + c.item.id
                    })
                ]);
            },
            summary: function (value) {
                return value === 'skip' ? t.not_needed : '';
            },
            doneValue: function () {
                return 'done';
            }
        },

        weekdays: {
            icon: 'calendar-days',
            progress: function (value, settings) {
                var days = (value && value.days) || [];
                return {
                    done: days.filter(function (day) {
                        return settings.days.indexOf(day) !== -1;
                    }).length,
                    total: settings.days.length
                };
            },
            control: function (view, c) {
                var checked = (c.value && c.value.days) ? c.value.days : [];
                var id = c.item.id;
                return el('div', {class: 'cko-item__main cko-item__main--stacked'}, [
                    c.label,
                    el('span', {class: 'cko-days', role: 'group', 'aria-labelledby': view.editing ? null : c.labelId},
                        orderedDays().filter(function (day) {
                            return c.settings.days.indexOf(day) !== -1;
                        }).map(function (day) {
                            var on = checked.indexOf(day) !== -1;
                            return el('button', {
                                type: 'button',
                                class: 'cko-day' + (on ? ' is-on' : '') + (day === cfg.today ? ' is-today' : ''),
                                'aria-pressed': on ? 'true' : 'false',
                                'aria-label': cfg.weekdays[day] + (day === cfg.today ? ' (' + t.today + ')' : ''),
                                title: cfg.weekdays[day],
                                'data-focus': 'day-' + day + '-' + id,
                                text: cfg.weekdays_abbr[day],
                                onclick: function () {
                                    var days = checked.filter(function (d) {
                                        return d !== day;
                                    });
                                    if (!on) {
                                        days.push(day);
                                    }
                                    view.focusAfterRender = 'day-' + day + '-' + id;
                                    view.setValue(id, days.length ? {days: days, week: blockDef('weekdays').data.current_week} : null, true);
                                }
                            });
                        })
                    )
                ]);
            },
            summary: function (value, settings) {
                return value ? format(t.progress, (value.days || []).length, (settings.days || []).length) : '';
            },
            doneValue: function (settings) {
                return {days: (settings.days || []).slice(), week: blockDef('weekdays').data.current_week};
            },
            notes: function (block, editing) {
                return (!editing && block.settings.reset === 'weekly') ? [{icon: 'repeat', text: t.week_reset_note}] : [];
            }
        },

        color_scale: {
            icon: 'palette',
            control: function (view, c) {
                var steps = scaleFor(c.settings);
                var current = (c.value === undefined || c.value === null) ? null : parseInt(c.value, 10);
                var id = c.item.id;
                return el('div', {class: 'cko-item__main cko-item__main--stacked'}, [
                    c.label,
                    el('span', {class: 'cko-scale cko-scale--colors', role: 'group', 'aria-labelledby': view.editing ? null : c.labelId}, steps.map(function (step, i) {
                        var on = current === i;
                        return el('button', {
                            type: 'button',
                            class: 'cko-swatch' + (on ? ' is-on' : ''),
                            style: '--cko-swatch:' + step.color,
                            'aria-pressed': on ? 'true' : 'false',
                            'aria-label': step.label,
                            title: step.label,
                            'data-focus': 'swatch-' + i + '-' + id,
                            onclick: function () {
                                view.focusAfterRender = 'swatch-' + i + '-' + id;
                                view.setValue(id, on ? null : i, true);
                            }
                        }, [icon('check')]);
                    }).concat([
                        el('span', {
                            class: 'cko-scale__current',
                            text: current !== null && steps[current] ? steps[current].label : t.not_rated
                        })
                    ]))
                ]);
            },
            summary: function (value, settings) {
                var step = hasValue(value) ? scaleFor(settings)[value] : null;
                return step ? (step.label || '') : '';
            }
        },

        number_scale: {
            icon: 'hash',
            control: function (view, c) {
                var s = c.settings;
                var id = c.item.id;
                var min = parseInt(s.min, 10);
                var max = parseInt(s.max, 10);
                var num = (c.value === undefined || c.value === null) ? null : parseInt(c.value, 10);
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
                                'data-focus': 'num-' + n + '-' + id,
                                text: String(n),
                                onclick: function () {
                                    view.focusAfterRender = 'num-' + n + '-' + id;
                                    view.setValue(id, on ? null : n, true);
                                }
                            }));
                        })(n);
                    }
                    scale = el('span', {class: 'cko-scale cko-scale--numbers', role: 'group', 'aria-labelledby': view.editing ? null : c.labelId}, buttons);
                } else {
                    scale = el('span', {class: 'cko-scale cko-scale--numbers'}, [
                        el('input', {
                            type: 'number',
                            class: 'cko-input cko-input--number',
                            min: min,
                            max: max,
                            step: 1,
                            value: num === null ? '' : num,
                            'aria-labelledby': view.editing ? null : c.labelId,
                            'data-focus': 'numin-' + id,
                            onchange: function () {
                                var v = this.value === '' ? null : Math.max(min, Math.min(max, parseInt(this.value, 10)));
                                view.setValue(id, isNaN(v) ? null : v, false);
                            }
                        }),
                        el('span', {class: 'cko-scale__range', text: min + '–' + max})
                    ]);
                }

                return el('div', {class: 'cko-item__main cko-item__main--stacked'}, [c.label, scale]);
            },
            summary: function (value, settings) {
                return hasValue(value) ? format(t.progress, value, settings.max || 5) : '';
            }
        },

        measurement: {
            icon: 'ruler',
            progress: function (value, settings) {
                return {done: inRange(value, settings) ? 1 : 0, total: 1};
            },
            control: function (view, c) {
                var s = c.settings;
                var id = c.item.id;
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
                var measured = typeof c.value === 'number';
                var rangeState = (measured && range) ? (inRange(c.value, s) ? ' is-in-range' : ' is-out-of-range') : '';
                return el('div', {class: 'cko-item__main cko-item__main--stacked'}, [
                    c.label,
                    el('span', {class: 'cko-measure' + rangeState}, [
                        el('input', {
                            type: 'text',
                            inputmode: decimals ? 'decimal' : 'numeric',
                            class: 'cko-input cko-input--number cko-measure__input',
                            value: measured ? formatNumber(c.value, decimals) : '',
                            placeholder: '–',
                            'aria-labelledby': c.labelId,
                            'aria-describedby': range ? 'cko-r-' + id : null,
                            'data-focus': 'measure-' + id,
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
                                view.setValue(id, number, true);
                            }
                        }),
                        s.unit ? el('span', {class: 'cko-measure__unit', text: s.unit}) : null,
                        range ? el('span', {
                            class: 'cko-measure__target',
                            id: 'cko-r-' + id,
                            title: rangeState === ' is-out-of-range' ? t.out_of_range : (rangeState ? t.in_range : null),
                            text: format(t.target, range)
                        }) : null
                    ])
                ]);
            },
            summary: function (value, settings) {
                return typeof value === 'number' ? formatNumber(value, parseInt(settings.decimals, 10) || 0) + (settings.unit ? ' ' + settings.unit : '') : '';
            }
        },

        choice: {
            icon: 'circle-dot',
            control: function (view, c) {
                var chosen = (c.value === undefined || c.value === null) ? null : parseInt(c.value, 10);
                var id = c.item.id;
                return el('div', {class: 'cko-item__main cko-item__main--stacked'}, [
                    c.label,
                    el('span', {class: 'cko-choice', role: 'group', 'aria-labelledby': c.labelId}, (c.settings.options || []).map(function (option, i) {
                        var on = chosen === i;
                        return el('button', {
                            type: 'button',
                            class: 'cko-choice__option' + (on ? ' is-on' : ''),
                            'aria-pressed': on ? 'true' : 'false',
                            'data-focus': 'choice-' + i + '-' + id,
                            text: option,
                            onclick: function () {
                                view.focusAfterRender = 'choice-' + i + '-' + id;
                                view.setValue(id, on ? null : i, true);
                            }
                        });
                    }))
                ]);
            },
            summary: function (value, settings) {
                return hasValue(value) ? String((settings.options || [])[value] || '').trim() : '';
            }
        },

        counter: {
            icon: 'tally-5',
            progress: function (value, settings) {
                return {done: (parseInt(value, 10) || 0) >= Math.max(1, parseInt(settings.target, 10) || 0) ? 1 : 0, total: 1};
            },
            control: function (view, c) {
                var id = c.item.id;
                var count = parseInt(c.value, 10) || 0;
                var target = parseInt(c.settings.target, 10) || 0;
                return el('div', {class: 'cko-item__main cko-item__main--stacked'}, [
                    c.label,
                    el('span', {class: 'cko-counter' + (target > 0 && count >= target ? ' is-reached' : '')}, [
                        iconButton('minus', t.decrease, function () {
                            view.focusAfterRender = 'minus-' + id;
                            view.setValue(id, count - 1 > 0 ? count - 1 : null, true);
                        }, {class: 'cko-iconbtn cko-counter__minus', disabled: count <= 0, 'data-focus': 'minus-' + id}),
                        el('output', {class: 'cko-counter__value', 'aria-live': 'polite'}, [
                            el('span', {text: String(count)}),
                            target > 0 ? el('span', {class: 'cko-counter__target', text: ' / ' + target}) : null
                        ]),
                        iconButton('plus', t.increase, function () {
                            view.focusAfterRender = 'plus-' + id;
                            view.setValue(id, count + 1, true);
                        }, {class: 'cko-iconbtn cko-counter__plus', 'data-focus': 'plus-' + id})
                    ])
                ]);
            },
            summary: function (value, settings) {
                if (!hasValue(value)) {
                    return '';
                }
                return parseInt(settings.target, 10) ? format(t.progress, value, settings.target) : String(value);
            },
            doneValue: function (settings) {
                return Math.max(1, parseInt(settings.target, 10) || 1);
            }
        },

        note: {
            icon: 'sticky-note',
            control: function (view, c) {
                var multi = !!c.settings.multiline;
                var field = el(multi ? 'textarea' : 'input', {
                    class: 'cko-input cko-note',
                    rows: multi ? 2 : null,
                    type: multi ? null : 'text',
                    maxlength: 2000,
                    value: c.value || '',
                    'aria-labelledby': view.editing ? null : c.labelId,
                    'aria-label': view.editing ? (c.item.label || t.item_label) : null,
                    'data-focus': 'note-' + c.item.id,
                    oninput: function () {
                        if (this.tagName === 'TEXTAREA') {
                            this.style.height = 'auto';
                            this.style.height = this.scrollHeight + 'px';
                        }
                        view.setNoteDebounced(c.item.id, this.value);
                    }
                });
                return el('div', {class: 'cko-item__main cko-item__main--stacked'}, [c.label, field]);
            },
            summary: function (value) {
                var text = hasValue(value) ? String(value) : '';
                return text.length > 60 ? text.slice(0, 57) + '…' : text;
            }
        }
    };

    /** The module of a block type (or of a block), with the defaults filled in. */
    var typeCache = {};
    function typeOf(key) {
        key = blockDef(key).key;
        if (!typeCache[key]) {
            typeCache[key] = Object.assign({}, BASE_TYPE, BLOCK_TYPES[key] || {});
        }
        return typeCache[key];
    }

    /*
     * Modules of add-on blocks. An add-on registers its block's PHP class
     * through the filter `checkoff_block_types` and its browser module here,
     * before or after this file has run:
     *
     *   window.checkoffBlockTypes = window.checkoffBlockTypes || [];
     *   window.checkoffBlockTypes.push(function (kit) {
     *       return {key: 'rating', icon: 'star', control: function (view, c) { … }};
     *   });
     *
     * A factory receives the kit (el, icon, iconButton, button, format,
     * toast, i18n, blockDef, hasValue); a plain object works too. The module
     * has the same methods as the built-in ones (see BASE_TYPE); what it
     * leaves out keeps its default — or, for a built-in key, the built-in's.
     */
    var KIT = {
        el: el,
        icon: icon,
        iconButton: iconButton,
        button: button,
        format: format,
        toast: toast,
        i18n: t,
        blockDef: blockDef,
        hasValue: hasValue
    };

    function registerBlockType(entry) {
        var module = typeof entry === 'function' ? entry(KIT) : entry;

        if (!module || typeof module !== 'object' || typeof module.key !== 'string' || !module.key) {
            return;
        }

        BLOCK_TYPES[module.key] = Object.assign({}, BLOCK_TYPES[module.key] || {}, module);
        typeCache = {};
    }

    (Array.isArray(window.checkoffBlockTypes) ? window.checkoffBlockTypes : []).forEach(registerBlockType);
    window.checkoffBlockTypes = {
        push: function () {
            Array.prototype.forEach.call(arguments, registerBlockType);
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
