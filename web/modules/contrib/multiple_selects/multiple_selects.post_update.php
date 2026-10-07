<?php

/**
 * @file
 * Post update functions for Multiple Selects.
 */

use Drupal\Core\Config\Entity\ConfigEntityUpdater;
use Drupal\Core\Entity\Display\EntityFormDisplayInterface;

/**
 * Add element type to all fields using the multiple_options_select widget.
 */
function multiple_selects_post_update_add_element_type_to_widgets(?array &$sandbox = NULL) {
  /** @var \Drupal\Core\Config\Entity\ConfigEntityUpdater $config_entity_updater */
  $config_entity_updater = \Drupal::classResolver(ConfigEntityUpdater::class);
  $config_entity_updater->update($sandbox, 'entity_form_display', function (EntityFormDisplayInterface $form_display) {
    $updated = FALSE;
    foreach ($form_display->getComponents() as $id => $component) {
      if (isset($component['type']) && $component['type'] === 'multiple_options_select' && !isset($component['settings']['element_type'])) {
        $component['settings']['element_type'] = 'select';
        $form_display->setComponent($id, $component);
        $updated = TRUE;
      }
    }

    return $updated;
  });
}
