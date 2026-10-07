/**
 * @file
 * Javascript to handle authorize.net forms.
 */

(function ($, Drupal, drupalSettings, once) {
  /**
   * Attaches the commerceAuthorizeNetForm behavior.
   */
  Drupal.behaviors.commerceAuthorizeNetForm = {
    attach(context) {
      let $form = once(
        'authorize-net-accept-js-processed',
        '.authorize-net-accept-js-form',
        context,
      );
      if ($form.length === 0) {
        return;
      }
      $form = $($form[0]).closest('form');
      $form.find('.button--primary').prop('disabled', false);
      const settings = drupalSettings.commerceAuthorizeNet;
      if (settings.paymentMethodType === 'credit_card') {
        Drupal.commerceAuthorizeNetAcceptForm($form, settings);
      } else if (settings.paymentMethodType === 'authnet_echeck') {
        Drupal.commerceAuthorizeNetEcheckForm($form, settings);
      } else if (settings.paymentMethodType === 'authnet_visa') {
        Drupal.commerceAuthorizeNetVisaForm($form, settings);
      }
    },
    detach(context) {
      const $form = $('.authorize-net-accept-js-form', context).closest('form');
      if ($form.length) {
        if ($form.find('.authorize-net-accept-js-form').length) {
          once.remove(
            'authorize-net-accept-js-processed',
            $form.find('.authorize-net-accept-js-form')[0],
          );
        }
        $form.off('submit.authnet');
      }
    },
    errorDisplay(code, errorMessage) {
      console.log(`${code}: ${errorMessage}`);
      const $form = $('.authorize-net-accept-js-form').closest('form');
      // Display the message error in the payment form.
      const errors = $form.find('#payment-errors');
      errors.html(Drupal.theme('commerceAuthorizeNetError', errorMessage));
      window.scrollTo({
        top: errors.offset().top,
        behavior: 'smooth',
      });

      // Allow the customer to re-submit the form.
      $form.find('.button--primary').prop('disabled', false);
    },
  };

  $.extend(
    Drupal.theme,
    /** @lends Drupal.theme */ {
      commerceAuthorizeNetError(message) {
        return $('<div class="messages messages--error"></div>').html(message);
      },
    },
  );
})(jQuery, Drupal, drupalSettings, once);
