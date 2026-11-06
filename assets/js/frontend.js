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
                        el('span', {class: 'cko-recent__name', text: entry.title || entry.id}),
                        ' ',
                        el('code', {class: 'cko-recent__id', text: entry.id})
                    ]),
                    el('button', {
                        type: 'button',
                        class: 'cko-icon-button cko-recent__remove',
                        'aria-label': t.recent_remove,
                        title: t.recent_remove,
                        text: '×',
                        onclick: function () {
                            recentForget(entry.id);
                        }
                    })
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
        this.structure = data.structure;
        this.values = data.values || {};
        this.revision = data.revision;
        this.round = data.round || {repeats: false};
        this.last = data.last || null;
        this.subscribeOpen = false;
        this.structure.recurrence = this.structure.recurrence || {freq: 'none'};
        this.canEdit = data.access !== 'tick';   // the tick link only ticks
        this.tickUrl = data.tick_url || '';
        this.state = data.state || '';           // fingerprint for the live update
        this.pending = {};                       // entry ID => value requests running
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

    /* ---------- progress ---------- */

    /** {done, total} of a block including its sub-lists. */
    Checklist.prototype.progress = function (block) {
        var self = this;
        var def = blockDef(block.type);
        var result = {done: 0, total: 0};

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
                default:
                    result.total += 1;
                    result.done += (value !== undefined && value !== null && value !== false) ? 1 : 0;
            }
        });

        return result;
    };

    /* ---------- saving ---------- */

    Checklist.prototype.status = function (text, kind) {
        var node = this.root.querySelector('.cko-status');
        if (!node) {
            return;
        }
        node.textContent = text || '';
        node.className = 'cko-status' + (kind ? ' cko-status--' + kind : '');
    };

    /** Marks the structure as changed and schedules a save. */
    Checklist.prototype.changed = function (rerender) {
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
        }, 700);
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
                class: 'cko-button',
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

        parent.items.push(block);
        this.collapsed[block.id] = false;
        this.focusAfterRender = 'title-' + block.id;
        this.changed(true);
    };

    Checklist.prototype.remove = function (id) {
        var where = this.locate(id);
        if (!where || !where.parent) {
            return;
        }

        var node = where.node;
        var question = null;

        if (node.kind === 'block' && node.items.length) {
            question = t.confirm_delete_block;
        } else if (node.kind === 'item' && this.values[node.id] !== undefined) {
            question = t.confirm_delete_item;
        }

        if (question && !window.confirm(question)) {
            return;
        }

        where.parent.items.splice(where.index, 1);

        // Focus the previous entry, so a list can be cleared with the keyboard.
        var prev = where.parent.items[where.index - 1];
        this.focusAfterRender = prev
            ? (prev.kind === 'block' ? 'title-' + prev.id : 'label-' + prev.id)
            : 'title-' + where.parent.id;

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
        this.focusAfterRender = (delta < 0 ? 'up-' : 'down-') + id;
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
        this.changed(true);
    };

    Checklist.prototype.resetValues = function () {
        var self = this;

        if (!window.confirm(t.confirm_reset)) {
            return;
        }

        this.values = {};
        this.render();
        this.status(t.saving, 'busy');

        ckoPost('cko_reset_values', {id: this.id}).done(function () {
            self.status(t.saved, 'ok');
        }).fail(function (xhr) {
            self.status(errorMessage(xhr, t.save_failed), 'error');
        });
    };

    Checklist.prototype.deleteList = function () {
        var self = this;

        if (!window.confirm(t.confirm_delete_list)) {
            return;
        }

        ckoPost('cko_delete', {id: this.id}).done(function () {
            recentForget(self.id);
            self.root.textContent = '';
            self.root.appendChild(el('p', {class: 'cko-message', role: 'status', text: t.deleted}));
        }).fail(function (xhr) {
            self.status(errorMessage(xhr, t.save_failed), 'error');
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
        var statusNode = this.root.querySelector('.cko-status');
        var statusText = statusNode ? statusNode.textContent : '';
        var statusClass = statusNode ? statusNode.className : 'cko-status';

        this.focusAfterRender = null;

        // Keep a conflict banner, replace everything else.
        Array.prototype.slice.call(this.root.children).forEach(function (child) {
            if (!child.classList.contains('cko-data') && !child.classList.contains('cko-conflict') && child.parentNode === self.root) {
                self.root.removeChild(child);
            }
        });

        this.root.classList.toggle('is-editing', this.editing);
        this.root.appendChild(this.renderToolbar(statusText, statusClass));

        var roundNode = this.renderRound();
        if (roundNode) {
            this.root.appendChild(roundNode);
        }
        if (this.subscribeOpen) {
            this.root.appendChild(this.renderSubscribe());
        }
        this.root.appendChild(this.renderBlock(this.structure, 0));

        if (this.editing) {
            this.root.appendChild(this.renderFooter());
        }

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

    Checklist.prototype.renderToolbar = function (statusText, statusClass) {
        var self = this;

        function copyButton(label, text) {
            return el('button', {
                type: 'button',
                class: 'cko-button cko-button--small',
                text: label,
                onclick: function () {
                    copyText(text).then(function () {
                        self.status(t.copied, 'ok');
                    }, function () {
                        self.status(t.copy_failed, 'error');
                    });
                }
            });
        }

        return el('div', {class: 'cko-toolbar' + (this.canEdit ? '' : ' cko-toolbar--tick')}, [
            el('div', {class: 'cko-toolbar__id'}, [
                el('span', {class: 'cko-toolbar__id-label', text: t.your_id + ': '}),
                el('code', {class: 'cko-toolbar__id-value', title: this.canEdit ? t.id_hint : t.tick_only_hint, text: this.id}),
                ' ',
                copyButton(t.copy_id, this.id),
                ' ',
                copyButton(t.copy_link, this.url),
                this.canEdit && this.tickUrl ? ' ' : null,
                this.canEdit && this.tickUrl ? copyButton(t.copy_tick_link, this.tickUrl) : null,
                this.canEdit ? null : el('span', {class: 'cko-badge', title: t.tick_only_hint, text: t.tick_only})
            ]),
            el('div', {class: 'cko-toolbar__actions'}, [
                el('span', {class: statusClass || 'cko-status', role: 'status', 'aria-live': 'polite', text: statusText}),
                (cfg.subscribe && cfg.subscribe.enabled) ? el('button', {
                    type: 'button',
                    class: 'cko-button',
                    'aria-expanded': this.subscribeOpen ? 'true' : 'false',
                    'data-focus': 'subscribe',
                    text: t.subscribe,
                    onclick: function () {
                        self.subscribeOpen = !self.subscribeOpen;
                        self.focusAfterRender = self.subscribeOpen ? 'subscribe-email' : 'subscribe';
                        self.render();
                    }
                }) : null,
                (this.locked || !this.canEdit) ? null : el('button', {
                    type: 'button',
                    class: 'cko-button ' + (this.editing ? 'cko-button--primary' : ''),
                    'aria-pressed': this.editing ? 'true' : 'false',
                    'data-focus': 'mode',
                    text: this.editing ? t.done_editing : t.edit,
                    onclick: function () {
                        self.editing = !self.editing;
                        self.focusAfterRender = 'mode';
                        self.render();
                        if (!self.editing) {
                            clearTimeout(self.saveTimer);
                            self.saveStructure();
                        }
                    }
                })
            ])
        ]);
    };

    /** The round in force and how the last one went, for repeating lists. */
    Checklist.prototype.renderRound = function () {
        var r = this.round;

        if (!r || !r.repeats) {
            return null;
        }

        var parts = [];

        if (r.finished) {
            parts.push(el('strong', {text: t.series_finished}));
        } else {
            parts.push(el('strong', {
                text: r.total ? format(t.round_of, r.number, r.total) : format(t.round, r.number)
            }));
            parts.push(' · ');
            parts.push(r.before_start ? format(t.starts_on, r.start) : format(t.round_dates, r.start, r.end));
        }

        parts.push(el('span', {class: 'cko-round__rule', text: ' · ' + r.description}));

        if (this.last) {
            parts.push(el('span', {
                class: 'cko-round__last',
                text: format(t.last_round, format(t.round, this.last.number), this.last.done, this.last.total)
            }));
        }

        return el('p', {class: 'cko-round'}, parts);
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
            el('p', {class: 'cko-subscribe__title', text: t.subscribe_title}),
            el('p', {class: 'cko-field'}, [
                el('label', {for: uid, text: t.email}),
                el('input', {type: 'email', id: uid, required: true, autocomplete: 'email', 'data-focus': 'subscribe-email'})
            ]),
            el('p', {class: 'cko-subscribe__consent'}, [
                el('label', {}, [el('input', {type: 'checkbox', required: true}), ' ', consent])
            ]),
            el('p', {}, [el('button', {type: 'submit', class: 'cko-button cko-button--primary', text: t.subscribe_submit})]),
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

        return el('div', {class: 'cko-footer'}, [
            el('p', {class: 'cko-footer__hint', text: t.id_hint + ' ' + t.share_hint}),
            el('button', {
                type: 'button',
                class: 'cko-button',
                text: t.reset_values,
                onclick: function () {
                    self.resetValues();
                }
            }),
            ' ',
            el('button', {
                type: 'button',
                class: 'cko-button cko-button--danger',
                text: t.delete_list,
                onclick: function () {
                    self.deleteList();
                }
            })
        ]);
    };

    Checklist.prototype.renderProgress = function (block) {
        var p = this.progress(block);

        if (!p.total) {
            return el('span', {class: 'cko-progress cko-progress--none', 'data-progress': block.id});
        }

        var pct = Math.round(p.done / p.total * 100);

        return el('span', {
            class: 'cko-progress' + (p.done >= p.total ? ' is-complete' : ''),
            'data-progress': block.id
        }, [
            el('span', {class: 'cko-progress__bar', 'aria-hidden': 'true'}, [
                el('span', {class: 'cko-progress__fill', style: 'width:' + pct + '%'})
            ]),
            el('span', {class: 'cko-progress__text', text: format(t.progress, p.done, p.total)})
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
     * depth 1: a heading, no frame) or a nested sub-list (framed).
     */
    Checklist.prototype.renderBlock = function (block, depth, tools) {
        var self = this;
        var isRoot = block.kind === 'checklist';
        var isSection = !isRoot && depth === 1;
        var def = blockDef(block.type);
        var editing = this.editing;
        var collapsed = !editing && !isRoot && !!this.collapsed[block.id];
        var headingTag = 'h' + Math.min(6, depth + 2);
        var placeholder = isRoot ? t.title_placeholder : (isSection ? t.section_placeholder : def.label);
        var titleText = block.title || (isRoot || isSection ? '' : def.label);

        // An untitled section shows no heading at all outside editing: it
        // simply continues the list above it.
        var showHeader = editing || isRoot || titleText !== '';

        var header = !showHeader ? null : el('div', {class: 'cko-block__header'}, [
            isRoot ? null : el('button', {
                type: 'button',
                class: 'cko-icon-button cko-block__toggle',
                'aria-expanded': collapsed ? 'false' : 'true',
                'aria-label': collapsed ? t.expand : t.collapse,
                title: collapsed ? t.expand : t.collapse,
                'data-focus': 'toggle-' + block.id,
                text: collapsed ? '▸' : '▾',
                hidden: editing,
                onclick: function () {
                    self.collapsed[block.id] = !collapsed;
                    storageSet(self.collapsedKey, self.collapsed);
                    self.focusAfterRender = 'toggle-' + block.id;
                    self.render();
                }
            }),
            editing && !isRoot ? el('span', {
                class: 'cko-block__type',
                title: def.label,
                'aria-hidden': 'true',
                text: def.icon
            }) : null,
            editing
                ? el('input', {
                    type: 'text',
                    class: 'cko-input cko-block__title-input',
                    value: block.title,
                    maxlength: 200,
                    placeholder: placeholder,
                    'aria-label': placeholder,
                    'data-focus': 'title-' + block.id,
                    oninput: function () {
                        block.title = this.value;
                        self.changed(false);
                    },
                    onkeydown: function (e) {
                        // Enter in a heading starts typing the block's entries.
                        if (e.key === 'Enter' && !isRoot) {
                            e.preventDefault();
                            self.addItem(block, -1);
                        }
                    }
                })
                : el(headingTag, {class: 'cko-block__title', text: titleText}),
            this.renderProgress(block),
            tools || null
        ]);

        var body = el('div', {class: 'cko-block__body', hidden: collapsed});

        if (editing) {
            body.appendChild(el('textarea', {
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
            }));
            body.appendChild(isRoot ? this.renderRecurrence() : this.renderBlockSettings(block, false));
        } else if (block.description) {
            body.appendChild(el('p', {class: 'cko-block__desc', text: block.description}));
        }

        if (block.type === 'weekdays' && block.settings.reset === 'weekly' && !editing) {
            body.appendChild(el('p', {class: 'cko-block__note', text: t.week_reset_note}));
        }

        if (isRoot) {
            // The stacked blocks.
            var sections = el('div', {class: 'cko-sections'});

            block.items.forEach(function (item, index) {
                // A section's move/duplicate/delete buttons sit in its own
                // heading row, where they plainly belong to it.
                sections.appendChild(el('div', {class: 'cko-sections__entry'}, [
                    self.renderBlock(item, 1, editing ? self.renderTools(item, index, block.items.length) : null)
                ]));
            });

            if (block.items.length) {
                body.appendChild(sections);
            } else {
                body.appendChild(el('p', {
                    class: 'cko-block__empty',
                    text: editing ? t.empty_checklist_edit : t.empty_checklist
                }));
            }
        } else {
            var list = el('ul', {class: 'cko-items'});

            block.items.forEach(function (item, index) {
                if (item.kind === 'block') {
                    list.appendChild(el('li', {class: 'cko-items__entry cko-items__entry--block'}, [
                        self.renderBlock(item, depth + 1),
                        editing ? self.renderTools(item, index, block.items.length) : null
                    ]));
                } else {
                    list.appendChild(self.renderItem(block, item, index));
                }
            });

            if (block.items.length) {
                body.appendChild(list);
            } else {
                body.appendChild(el('p', {
                    class: 'cko-block__empty',
                    text: editing ? t.empty_block_edit : t.empty_block
                }));
            }
        }

        if (editing) {
            body.appendChild(this.renderAddBar(block, depth));
        }

        var classes = 'cko-block cko-block--' + (isRoot ? 'checklist' : block.type) + ' cko-block--depth-' + depth;
        classes += isRoot ? ' cko-block--root' : (isSection ? ' cko-block--section' : ' cko-block--nested');
        if (isSection && !titleText) {
            classes += ' cko-block--untitled';
        }

        return el('section', {class: classes, 'data-block': block.id}, [header, body]);
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

    /** A button that opens a picker of building blocks to add to a parent. */
    Checklist.prototype.blockPicker = function (parent, label) {
        var self = this;
        var picker = el('div', {class: 'cko-picker', hidden: true, role: 'group', 'aria-label': t.choose_block},
            (cfg.enabled || []).map(function (key) {
                var candidate = blockDef(key);
                return el('button', {
                    type: 'button',
                    class: 'cko-picker__option',
                    onclick: function () {
                        self.addBlock(parent, key);
                    }
                }, [
                    el('span', {class: 'cko-picker__icon', 'aria-hidden': 'true', text: candidate.icon}),
                    el('span', {class: 'cko-picker__label', text: candidate.label}),
                    el('span', {class: 'cko-picker__desc', text: candidate.description})
                ]);
            })
        );

        var button = el('button', {
            type: 'button',
            class: 'cko-button cko-button--small',
            'aria-expanded': 'false',
            'data-focus': 'addblock-' + parent.id,
            text: '+ ' + label,
            onclick: function () {
                picker.hidden = !picker.hidden;
                this.setAttribute('aria-expanded', picker.hidden ? 'false' : 'true');
            }
        });

        return [button, picker];
    };

    Checklist.prototype.renderAddBar = function (block, depth) {
        var self = this;

        // The checklist takes building blocks, stacked; a block takes
        // entries and, nested, sub-lists.
        if (block.kind === 'checklist') {
            return el('div', {class: 'cko-addbar cko-addbar--sections'}, this.blockPicker(block, t.add_section));
        }

        var bar = el('div', {class: 'cko-addbar'}, [
            el('button', {
                type: 'button',
                class: 'cko-button cko-button--small',
                'data-focus': 'additem-' + block.id,
                text: '+ ' + t.add_item,
                onclick: function () {
                    self.addItem(block);
                }
            })
        ]);

        if (blockDef(block.type).children && depth < cfg.max_depth) {
            bar.appendChild(document.createTextNode(' '));
            this.blockPicker(block, t.add_block).forEach(function (node) {
                bar.appendChild(node);
            });
        }

        return bar;
    };

    /** Move / duplicate / delete buttons of an entry or sub-list. */
    Checklist.prototype.renderTools = function (node, index, count) {
        var self = this;

        return el('span', {class: 'cko-tools'}, [
            el('button', {
                type: 'button',
                class: 'cko-icon-button',
                'aria-label': t.move_up,
                title: t.move_up,
                'data-focus': 'up-' + node.id,
                disabled: index === 0,
                text: '↑',
                onclick: function () {
                    self.move(node.id, -1);
                }
            }),
            el('button', {
                type: 'button',
                class: 'cko-icon-button',
                'aria-label': t.move_down,
                title: t.move_down,
                'data-focus': 'down-' + node.id,
                disabled: index === count - 1,
                text: '↓',
                onclick: function () {
                    self.move(node.id, 1);
                }
            }),
            el('button', {
                type: 'button',
                class: 'cko-icon-button',
                'aria-label': t.duplicate,
                title: t.duplicate,
                text: '⧉',
                onclick: function () {
                    self.duplicate(node.id);
                }
            }),
            el('button', {
                type: 'button',
                class: 'cko-icon-button cko-icon-button--danger',
                'aria-label': t['delete'],
                title: t['delete'],
                text: '×',
                onclick: function () {
                    self.remove(node.id);
                }
            })
        ]);
    };

    Checklist.prototype.renderItem = function (block, item, index) {
        var self = this;
        var editing = this.editing;
        var value = this.values[item.id];
        var done = this.isItemDone(block, item);

        var label = editing
            ? el('input', {
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
                    // removes it: a list can be typed without the mouse.
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        self.addItem(block, block.items.indexOf(item));
                    } else if (e.key === 'Backspace' && this.value === '') {
                        e.preventDefault();
                        self.remove(item.id);
                    }
                }
            })
            : el('span', {class: 'cko-item__label', text: item.label || '—'});

        var control = this.renderControl(block, item, value, label);

        return el('li', {
            class: 'cko-items__entry cko-item cko-item--' + block.type + (done ? ' is-done' : ''),
            'data-item': item.id
        }, [
            control,
            editing ? this.renderTools(item, index, block.items.length) : null
        ]);
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
                return this.editing
                    ? el('span', {class: 'cko-item__main'}, [box, label])
                    : el('label', {class: 'cko-item__main'}, [box, label]);

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
                var steps = cfg.scale[s.steps] || cfg.scale[5];
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
                            });
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

            case 'counter':
                var count = parseInt(value, 10) || 0;
                var target = parseInt(s.target, 10) || 0;
                return el('div', {class: 'cko-item__main cko-item__main--stacked'}, [
                    label,
                    el('span', {class: 'cko-counter'}, [
                        el('button', {
                            type: 'button',
                            class: 'cko-icon-button cko-counter__minus',
                            'aria-label': t.decrease,
                            title: t.decrease,
                            disabled: count <= 0,
                            'data-focus': 'minus-' + item.id,
                            text: '−',
                            onclick: function () {
                                self.focusAfterRender = 'minus-' + item.id;
                                self.setValue(item.id, count - 1 > 0 ? count - 1 : null, true);
                            }
                        }),
                        el('output', {class: 'cko-counter__value', 'aria-live': 'polite', text: String(count)}),
                        target > 0 ? el('span', {class: 'cko-counter__target', text: '/ ' + target}) : null,
                        el('button', {
                            type: 'button',
                            class: 'cko-icon-button cko-counter__plus',
                            'aria-label': t.increase,
                            title: t.increase,
                            'data-focus': 'plus-' + item.id,
                            text: '+',
                            onclick: function () {
                                self.focusAfterRender = 'plus-' + item.id;
                                self.setValue(item.id, count + 1, true);
                            }
                        })
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
