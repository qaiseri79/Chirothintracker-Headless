(function ($, Drupal) {
    window.addPaymentMethodAjaxTriggered = window.addPaymentMethodAjaxTriggered || false;
    Drupal.behaviors.addPaymentMethodAutoSelect = {
      attach: function (context, settings) {
        // Only run on initial page load
        if (
          settings.custom_module_add_payment &&
          settings.custom_module_add_payment.payment_machine_name &&
          settings.custom_module_add_payment.is_initial_load &&
          !window.addPaymentMethodAjaxTriggered
        ) {
          var paymentMachineName = settings.custom_module_add_payment.payment_machine_name;
          var $paymentMethod = $(
            'input[name="payment_method"][value="' + paymentMachineName + '"]',
            context
          );
          if ($paymentMethod.length && !$paymentMethod.hasClass('auto-selected')) {
            console.log('Initial load - Preselecting payment methods: ' + paymentMachineName);
            $paymentMethod.addClass('auto-selected');
            $paymentMethod.prop('checked', true);
            $paymentMethod.trigger('change');
            window.addPaymentMethodAjaxTriggered = true;
            settings.custom_module_add_payment.is_initial_load = false;
          }
        } else if (!settings.custom_module_add_payment.is_initial_load) {
          console.log('AJAX rebuild detected - Skipping payment method trigger');
        }
      }
    };
  })(jQuery, Drupal);

