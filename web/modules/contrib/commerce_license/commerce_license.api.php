<?php

/**
 * @file
 * Hooks provided by the commerce_license module.
 */

/**
 * @addtogroup hooks
 * @{
 */

/**
 * Modify the list of available License Type plugins.
 *
 * This hook may be used to modify plugin properties after they have been
 * specified by other modules.
 *
 * @param array $plugins
 *   An array of all the existing plugin definitions, passed by reference.
 *
 * @see \Drupal\commerce_license\LicenseTypeManager
 */
function hook_commerce_license_type_info_alter(array &$plugins) {
  // Remove a plugin that won't be used on the site.
  // Note: if there is existing data, this will break things.
  unset($plugins['unneeded_plugin']);
  // Change a plugin label.
  $plugins['some_plugin']['label'] = t('Better name');
}

/**
 * Modify the list of available License Period plugins.
 *
 * This hook may be used to modify plugin properties after they have been
 * specified by other modules.
 *
 * @param array $plugins
 *   An array of all the existing plugin definitions, passed by reference.
 *
 * @see \Drupal\commerce_license\LicensePeriodManager
 */
function hook_commerce_license_period_info_alter(array &$plugins) {
  // Remove a plugin that won't be used on the site.
  // Note: if there is existing data, this will break things.
  unset($plugins['unneeded_plugin']);
  // Change a plugin label.
  $plugins['some_plugin']['label'] = t('Better name');
}

/**
 * @} End of "addtogroup hooks".
 */
