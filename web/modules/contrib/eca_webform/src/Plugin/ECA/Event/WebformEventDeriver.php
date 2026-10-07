<?php

namespace Drupal\eca_webform\Plugin\ECA\Event;

use Drupal\eca\Plugin\ECA\Event\EventDeriverBase;

/**
 * Deriver for ECA Webform event plugins.
 */
class WebformEventDeriver extends EventDeriverBase {

  /**
   * {@inheritdoc}
   */
  protected function definitions(): array {
    return WebformEvent::definitions();
  }

}
