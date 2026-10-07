/**
 * @file
 * Javascript to generate Accept.js token in PCI-compliant way.
 */

(function ($, Drupal, drupalSettings, once) {
  /**
   * Authorize.net accept.js form js.
   */
  Drupal.commerceAuthorizeNetAcceptForm = function ($form, settings) {
    let last4 = '';
    // To be used to temporarily store month and year.
    let expiration = {};

    $form.find('.button--primary').prop('disabled', false);

    const processTransactionDataFromAnet = function (responseData) {
      $('.accept-js-data-descriptor', $form).get(0).value =
        responseData.dataDescriptor;
      $('.accept-js-data-value', $form).get(0).value = responseData.dataValue;

      $('.accept-js-data-last4', $form).get(0).value = last4;
      $('.accept-js-data-month', $form).get(0).value = expiration.month;
      $('.accept-js-data-year', $form).get(0).value = `20${expiration.year}`;

      // Clear out the values so they don't get posted to Drupal. They would
      // never be used, but for PCI compliance we should never send them at.
      $('#credit-card-number').get(0).value = '';
      $('#expiration-month').get(0).value = '';
      $('#expiration-year').get(0).value = '';
      $('#cvv').get(0).value = '';

      // Submit the form.
      const $primaryButton = $form.find(':input.button--primary');
      $form.append(
        `<input type="hidden" name="_triggering_element_name" value="${$primaryButton.attr(
          'name',
        )}" />`,
      );
      $form.append(
        `<input type="hidden" name="_triggering_element_value" value="${$primaryButton.get(0).value}" />`,
      );
      $primaryButton.prop('disabled', false);
      $form.trigger('submit', { populated: true });
    };

    // Process the response from Authorize.Net to retrieve the two elements of
    // the payment nonce. If the data looks correct, record the OpaqueData to
    // the console and call the transaction processing function.
    const responseHandler = function (response) {
      if (response.messages.resultCode === 'Error') {
        for (let i = 0; i < response.messages.message.length; i++) {
          Drupal.behaviors.commerceAuthorizeNetForm.errorDisplay(
            response.messages.message[i].code,
            response.messages.message[i].text,
          );
        }
      } else {
        processTransactionDataFromAnet(response.opaqueData);
      }
    };

    // Sends the card data to Authorize.Net and receive the payment nonce in
    // response.
    const sendPaymentDataToAnet = function (event) {
      const secureData = {};
      const authData = {};
      const cardData = {};
      // Extract the card number, expiration date, and card code.
      cardData.cardNumber = $('#credit-card-number')
        .get(0)
        .value.replace(/ /g, '');
      cardData.month = $('#expiration-month').get(0).value;
      cardData.year = $('#expiration-year').get(0).value;
      cardData.cardCode = $('#cvv').get(0).value;
      secureData.cardData = cardData;

      // The Authorize.Net Client Key is used in place of the traditional
      // Transaction Key. The Transaction Key is a shared secret and must never
      // be exposed. The Client Key is a public key suitable for use where
      // someone outside the merchant might see it.
      authData.clientKey = settings.clientKey;
      authData.apiLoginID = settings.apiLoginID;
      secureData.authData = authData;

      // Pass the card number and expiration date to Accept.js for submission
      // to Authorize.Net.
      // eslint-disable-next-line no-undef
      Accept.dispatchData(secureData, responseHandler);
    };

    // Form submit.
    $form.on('submit.authnet', function (event, options) {
      options = options || {};
      if (options.populated) {
        return;
      }
      // Disable the submit button to prevent repeated clicks.
      $form.find(':input.button--primary').prop('disabled', true);
      event.preventDefault();

      // Store last4 digit.
      const creditCardNumber = $('#credit-card-number').get(0).value;
      last4 = creditCardNumber.substr(creditCardNumber.length - 4);
      expiration = {
        month: $('#expiration-month').get(0).value,
        year: $('#expiration-year').get(0).value,
      };

      // Send payment data to anet.
      sendPaymentDataToAnet(event);
      return false;
    });
  };
})(jQuery, Drupal, drupalSettings, once);
