<?php

// @codingStandardsIgnoreFile

/**
 * @file
 * Contains database additions to drupal-8.8.0.bare.standard.php.gz.
 */

use Drupal\Core\Database\Database;

$connection = Database::getConnection();

// Update core.extension. Add multiple_Selects module.
$extensions = $connection->select('config')
  ->fields('config', ['data'])
  ->condition('collection', '')
  ->condition('name', 'core.extension')
  ->execute()
  ->fetchField();
$extensions = unserialize($extensions);
$extensions['module']['multiple_selects'] = 0;
$connection->update('config')
  ->fields([
    'data' => serialize($extensions),
  ])
  ->condition('collection', '')
  ->condition('name', 'core.extension')
  ->execute();

// Update UID field widget to multiple_options_select.
$page_form_display = $connection->select('config')
  ->fields('config', ['data'])
  ->condition('collection', '')
  ->condition('name', 'core.entity_form_display.node.page.default')
  ->execute()
  ->fetchField();
$page_form_display = unserialize($page_form_display);
$page_form_display['content']['uid']['type'] = 'multiple_options_select';
$page_form_display['content']['uid']['settings'] = [];
$connection->update('config')
  ->fields([
    'data' => serialize($page_form_display),
  ])
  ->condition('collection', '')
  ->condition('name', 'core.entity_form_display.node.page.default')
  ->execute();
