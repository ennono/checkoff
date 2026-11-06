/**
 * CheckOff admin script: the copy button of the checklist ID box.
 *
 * @package CheckOff
 */

/* global checkoffAdmin, jQuery */

jQuery(function ($) {

    'use strict';

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
                flash(checkoffAdmin.copied);
            }, function () {
                flash(checkoffAdmin.copy_failed);
            });
        } else {
            window.prompt('', text);
        }
    });
});
