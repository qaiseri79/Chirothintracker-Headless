/**
 * @file
 * Defines behaviors for the Authorize.Net Accept Hosted payment form
 * in iframe.
 */

((Drupal, once) => {
  /**
   * The Drupal Commerce Accept Hosted Instances.
   *
   * @type {Map}
   */
  Drupal.CommerceAcceptHostedInstances = new Map();

  class CommerceAuthorizeNetAcceptHosted {
    /**
     * The settings.
     */
    settings;

    /**
     * The Commerce Accept Hosted constructor.
     */
    constructor(settings) {
      this.settings = settings;
    }

    /**
     * Initialize the Accept Hosted component.
     */
    async initializeAcceptHosted() {
      await this.setupIframeCommunication();
      await this.initializeAcceptHostedPaymentForm();
    }

    /**
     * Sets up communication listener for the Accept Hosted iframe.
     */
    async setupIframeCommunication() {
      const { settings } = this;
      if (!window.CommunicationHandler) window.CommunicationHandler = {};
      window.CommunicationHandler.onReceiveCommunication = function (query) {
        const params = new URLSearchParams(query);
        const action = params.get('action');
        switch (action) {
          case 'cancel':
            window.location = settings.cancelUrl;
            break;

          case 'transactResponse':
            window.location = `${settings.continueUrl}?response=${params.get('response')}`;
            break;

          case 'resizeWindow': {
            const iframe = document.getElementById(settings.iframeTargetId);
            if (iframe) {
              iframe.width = params.get('width') || iframe.width;
              iframe.height = params.get('height') || iframe.height;
            }
            break;
          }

          // no default
        }
      };
    }

    /**
     * Creates and submits a form to load the Accept Hosted payment form in an iframe.
     *
     * @see https://developer.authorize.net/api/reference/features/accept-hosted.html#Integrating_an_Embedded_Iframe
     */
    async initializeAcceptHostedPaymentForm() {
      // Create a temporary hidden form to post the token to the Accept Hosted URL.
      const form = document.createElement('form');
      form.method = 'POST';
      form.action = this.settings.tokenSubmitAction;
      form.target = this.settings.iframeTargetId;
      // Add Drupal's visually-hidden class.
      form.classList.add('visually-hidden');
      // Add the token input field.
      const tokenField = document.createElement('input');
      tokenField.type = 'hidden';
      tokenField.name = 'token';
      tokenField.value = this.settings.paymentToken;
      form.appendChild(tokenField);
      // Append the form to the body.
      document.body.appendChild(form);
      // Submit the form to load the payment form inside the target iframe.
      form.submit();
      // Remove the temporary form used to send the token.
      form.remove();
    }
  }

  /**
   * Attaches the commerceAuthorizeNetAcceptHosted behavior.
   */
  Drupal.behaviors.commerceAuthorizeNetAcceptHosted = {
    async attach(context, drupalSettings) {
      // Validate all required dependencies.
      if (!Drupal || !drupalSettings) {
        console.error('Required dependencies are not available');
        return;
      }

      const settings = drupalSettings.commerceAuthorizeNet;
      if (!settings?.paymentToken || !settings?.iframeTargetId) {
        console.error('Required settings are missing');
        return;
      }

      const [element] = once(
        'accept-hosted-processed',
        `#${settings.iframeTargetId}`,
        context,
      );
      if (element) {
        const acceptHostedComponent = new CommerceAuthorizeNetAcceptHosted(
          settings,
        );
        await acceptHostedComponent.initializeAcceptHosted();
        Drupal.CommerceAcceptHostedInstances.set(
          element,
          acceptHostedComponent,
        );
      }
    },
  };
})(Drupal, once);
