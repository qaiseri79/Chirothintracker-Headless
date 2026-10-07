(function ($, Drupal, once) {

    Drupal.behaviors.chirothintrackerSubtheme = {
        attach: function (context) {

            // Get all target forms in the context, process each only once
            const forms = '.contact-message-zerona-profile-form .field--name-field-treatment-area .form-checkboxes,' +
                '.contact-message-zerona-profile-edit-form .field--name-field-treatment-area .form-checkboxes';

            // Wrap forms in jQuery so we can call .find()
            $('body').find(forms).each(function () {
                const field = $(this);

                // Avoid adding multiple buttons
                if (field.find('.shield-toggle-all').length === 0) {
                    field.prepend(`<div class="w-100"><button type="button" class="btn btn-sm btn-success shield-toggle-all">Select All</button></div>`);
                }
            });

            // Click handler (works in modals too)
            $(context).on('click', '.shield-toggle-all', function () {
                const wrapper = $(this).closest('.form-checkboxes');
                const checkboxes = wrapper.find('.form-type-checkbox input[type="checkbox"]');

                const allChecked = checkboxes.filter(':checked').length === checkboxes.length;

                checkboxes.prop('checked', !allChecked);
                $(this).text(allChecked ? 'Select All' : 'Deselect All');
            });

            // ── Responsive subscription block copy ──────────────────────────
            const BLOCK_ID      = '#block-chirothintracker-views-block-clinician-subscription-block-1';
            const HEADER_TOP    = '.header-top';
            const CLONE_WRAPPER = 'header-bottom';
            const CLONE_ID      = 'subscription-block-mobile-clone';
            const BREAKPOINT    = 768;

            function handleSubscriptionBlock() {
                const isMobile = $(window).width() < BREAKPOINT;
                const $clone   = $(`#${CLONE_ID}`);

                if (isMobile) {
                    // Only inject once
                    if ($clone.length === 0) {
                        const $blockClone = $(BLOCK_ID).clone(true, true); // deep clone with events
                        $blockClone.removeAttr('id');                       // avoid duplicate IDs

                        // Build the header-bottom wrapper and append after header-top
                        const $wrapper = $(`<div class="${CLONE_WRAPPER}" id="${CLONE_ID}"></div>`);
                        $wrapper.append($blockClone);
                        $(HEADER_TOP).after($wrapper);
                    }
                } else {
                    // Remove the mobile clone on tablet / desktop
                    $clone.remove();
                }
            }

            // Run on attach
            handleSubscriptionBlock();

            // Re-run on window resize (debounced to avoid rapid firing)
            let resizeTimer;
            $(window).on('resize.subscriptionBlock', function () {
                clearTimeout(resizeTimer);
                resizeTimer = setTimeout(handleSubscriptionBlock, 150);
            });
            // ────────────────────────────────────────────────────────────────

        }
    };

})(jQuery, Drupal, once);