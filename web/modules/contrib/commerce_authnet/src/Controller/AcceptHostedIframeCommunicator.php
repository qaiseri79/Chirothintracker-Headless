<?php

namespace Drupal\commerce_authnet\Controller;

use Drupal\Core\Controller\ControllerBase;

/**
 * Handles the communication logic for the Accept Hosted iframe.
 */
class AcceptHostedIframeCommunicator extends ControllerBase {

  /**
   * Outputs an iframe communicator page.
   *
   * @return array
   *   A render array.
   */
  public function page(): array {
    $build = [];
    $build['#attached']['library'][] = 'commerce_authnet/accept-hosted-iframe-communicator';

    return $build;
  }

}
