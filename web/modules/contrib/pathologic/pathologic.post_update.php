<?php

/**
 * @file
 * Post update functions for the Pathologic module.
 */

use Drupal\filter\Entity\FilterFormat;
use Drupal\filter\FilterPluginCollection;

/**
 * Move setting for allowed schemes to a new name (#3216612).
 */
function pathologic_post_update_rename_scheme_allow_list() {
  $config_factory = \Drupal::configFactory();
  $config = $config_factory->getEditable('pathologic.settings');
  // Yes it's a flag word. We're getting rid of it.
  // cspell:ignore whitelist
  $existing_schema = $config->get('scheme_whitelist');
  $config->set('scheme_allow_list', $existing_schema);
  // Be gone!
  $config->clear('scheme_whitelist');
  $config->save();
}

/**
 * Set new 'keep_language_prefix' option to match existing sites's behavior.
 */
function pathologic_post_update_set_keep_language_prefix() {
  \Drupal::configFactory()->getEditable('pathologic.settings')
    ->set('keep_language_prefix', FALSE)
    ->save();

  if (\Drupal::service('plugin.manager.filter')->hasDefinition('filter_pathologic')) {
    foreach (FilterFormat::loadMultiple() as $format) {
      $collection = $format->filters();
      $configuration = $collection->getConfiguration();
      assert($collection instanceof FilterPluginCollection);
      if (
        isset($configuration['filter_pathologic'])
        && !isset($configuration['filter_pathologic']['settings']['local_settings']['keep_language_prefix'])
      ) {
        $configuration['filter_pathologic']['settings']['local_settings']['keep_language_prefix'] = FALSE;
        $collection->setConfiguration($configuration);
        $format->save();
      }
    }
  }
}
