/**
 * @file
 * Javascript handle for Authorize.net Visa Checkout.
 */

(function ($, Drupal, drupalSettings) {
  /**
   * Authorize.net Visa Checkout.
   */
  Drupal.commerceAuthorizeNetVisaForm = function ($form, settings) {
    window.onVisaCheckoutReady = function () {
      // eslint-disable-next-line no-undef
      V.init({
        apikey: settings.visaApiKey,
        paymentRequest: {
          currencyCode: settings.currencyCode,
          total: settings.number,
        },
      });
      // eslint-disable-next-line no-undef
      V.on('payment.success', function (payment) {
        $('body').prepend(
          `<div class="authnet-overlay">${Drupal.t(
            'Processing...',
          )}<br/>${Drupal.t(
            'Please wait and do not navigate away from the page until finished.',
          )}</div>`,
        );
        $.post(settings.successUrl, { payment }).done(function (data) {
          window.location.href = settings.nextCheckoutStepUrl;
        });
      });
      // eslint-disable-next-line no-undef
      V.on('payment.error', function (payment, error) {
        $.post(settings.errorUrl, { payment, error });
      });
      // eslint-disable-next-line no-undef
      V.on('payment.cancel', function (payment) {
        $.post(settings.cancelUrl, { payment });
      });
    };
  };
})(jQuery, Drupal, drupalSettings);
