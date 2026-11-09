/**
 * CheckOff admin script: the copy button of the checklist ID box, the
 * sending rules table and the send log on the e-mail settings tab.
 *
 * @package CheckOff
 */

/* global checkoffAdmin, jQuery */

jQuery(function ($) {

    'use strict';

    var t = checkoffAdmin;

    /* ---------- colour fields (Design) ---------- */

    if ($.fn.wpColorPicker) {
        $('.cko-color-field').wpColorPicker();
    }

    /* ---------- copy the checklist ID ---------- */

    $(document).on('click', '.cko-admin-copy', function () {
        var $button = $(this);
        var text = String($button.data('copy') || '');
        var old = $button.text();

        function flash(label) {
            $button.text(label);
            setTimeout(function () {
                $button.text(old);
            }, 1500);
        }

        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(text).then(function () {
                flash(t.copied);
            }, function () {
                flash(t.copy_failed);
            });
        } else {
            window.prompt('', text);
        }
    });

    /* ---------- sending rules ---------- */

    var $rules = $('.cko-rules');

    if ($rules.length) {

        var $body = $rules.find('.cko-rules__table tbody');
        var $result = $rules.find('.cko-rules__result');
        var nonce = $rules.data('nonce');

        // The time means nothing for "on confirmation", and "only if open"
        // only for the triggers that can be open.
        var syncRow = function ($row) {
            var $trigger = $row.find('.cko-rule__trigger');
            var trigger = $trigger.val();
            var openTriggers = String($trigger.data('open-triggers') || '').split(',');
            var canBeOpen = openTriggers.indexOf(trigger) !== -1;

            $row.find('.cko-rule__time').prop('disabled', trigger === 'on_confirm');
            $row.find('.cko-rule__open').prop('disabled', !canBeOpen);
            if (!canBeOpen) {
                $row.find('.cko-rule__open').prop('checked', false);
            }
        };

        // Rows are posted as option[0], option[1], …; after removing one the
        // numbers are made consecutive again.
        var renumber = function () {
            $body.find('.cko-rule').each(function (index) {
                $(this).find('[name]').each(function () {
                    this.name = this.name.replace(/\[(\d+|__INDEX__)\]/, '[' + index + ']');
                });
            });
        };

        $body.find('.cko-rule').each(function () {
            syncRow($(this));
        });

        $rules.on('change', '.cko-rule__trigger', function () {
            syncRow($(this).closest('.cko-rule'));
        });

        $rules.on('click', '.cko-rules__add', function () {
            var html = $rules.find('.cko-rules__template').html();
            var $row = $(html.replace(/__INDEX__/g, String($body.find('.cko-rule').length)));
            $body.append($row);
            syncRow($row);
            $row.find('.cko-rule__trigger').trigger('focus');
        });

        $rules.on('click', '.cko-rule__remove', function () {
            $(this).closest('.cko-rule').remove();
            renumber();
        });

        var sendTest = function (data, $button) {
            $button.prop('disabled', true);
            $result.text(t.sending);

            $.post(t.ajax_url, $.extend({
                action: 'cko_test_rule',
                nonce: nonce,
                to: $rules.find('.cko-rules__to').val()
            }, data)).done(function (res) {
                $result.text(res && res.data && res.data.message ? res.data.message : t.test_failed);
            }).fail(function (xhr) {
                var json = xhr.responseJSON;
                $result.text(json && json.data && json.data.message ? json.data.message : t.test_failed);
            }).always(function () {
                $button.prop('disabled', false);
            });
        };

        $rules.on('click', '.cko-rule__test', function () {
            var $row = $(this).closest('.cko-rule');
            sendTest({
                trigger: $row.find('.cko-rule__trigger').val(),
                time: $row.find('.cko-rule__time').val() || '08:00',
                subject: $row.find('.cko-rule__subject').val(),
                template: $row.find('.cko-rule__template').val()
            }, $(this));
        });

        $rules.on('click', '.cko-rules__test-confirm', function () {
            sendTest({template: 'confirm.html'}, $(this));
        });
    }

    /* ---------- send log ---------- */

    $(document).on('click', '#cko-clear-log', function () {
        var $button = $(this);

        if (!window.confirm(t.confirm_clear)) {
            return;
        }

        $.post(t.ajax_url, {
            action: 'cko_clear_mail_log',
            nonce: $button.data('nonce')
        }).done(function () {
            $button.closest('p').prev('table').remove();
            $button.replaceWith($('<span>').text(t.cleared));
        });
    });
});
