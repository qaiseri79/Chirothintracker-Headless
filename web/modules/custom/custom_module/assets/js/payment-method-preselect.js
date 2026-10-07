(function ($, Drupal) {
  window.paymentMethodAjaxTriggered = window.paymentMethodAjaxTriggered || false;
  Drupal.behaviors.paymentMethodAutoSelect = {
    attach: function (context, settings) {
      // Only run on initial page load
      if (
        settings.custom_module &&
        settings.custom_module.selected_payment_method &&
        settings.custom_module.is_initial_load &&
        !window.paymentMethodAjaxTriggered
      ) {
        var selectedPaymentMethod = settings.custom_module.selected_payment_method;
        var $paymentMethod = $(
          'input[name="payment_information[payment_method]"][value="' + selectedPaymentMethod + '"]',
          context
        );
        if ($paymentMethod.length && !$paymentMethod.hasClass('auto-selected')) {
          console.log('Initial load - Preselecting payment method and triggering AJAX: ' + selectedPaymentMethod);
          $paymentMethod.addClass('auto-selected');
          $paymentMethod.prop('checked', true);
          $paymentMethod.trigger('change');
          window.paymentMethodAjaxTriggered = true;
          settings.custom_module.is_initial_load = false;
        }
      } else if (!settings.custom_module.is_initial_load) {
        console.log('AJAX rebuild detected - Skipping payment method trigger');
      }
    }
  };
})(jQuery, Drupal);
