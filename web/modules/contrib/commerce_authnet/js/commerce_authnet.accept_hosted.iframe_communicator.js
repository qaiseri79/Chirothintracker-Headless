/**
 * @file
 * Defines behaviors for the Authorize.net iframe communicator.
 */

(function (Drupal) {
  /**
   * Attaches Authorize.net iframe communicator behavior.
   */
  Drupal.behaviors.commerceAuthorizeNetIframeCommunicator = {
    attach(context) {
      const callParentFunction = function (str) {
        if (
          str &&
          str.length > 0 &&
          window.parent &&
          window.parent.parent &&
          window.parent.parent.CommunicationHandler &&
          window.parent.parent.CommunicationHandler.onReceiveCommunication
        ) {
          window.parent.parent.CommunicationHandler.onReceiveCommunication(str);
        }
      };

      const receiveMessage = function (event) {
        if (event && event.data) {
          callParentFunction(event.data);
        }
      };

      if (window.addEventListener) {
        window.addEventListener('message', receiveMessage, false);
      } else if (window.attachEvent) {
        window.attachEvent('onmessage', receiveMessage);
      }

      if (window.location.hash && window.location.hash.length > 1) {
        callParentFunction(window.location.hash.substring(1));
      }
    },
  };
})(Drupal);
