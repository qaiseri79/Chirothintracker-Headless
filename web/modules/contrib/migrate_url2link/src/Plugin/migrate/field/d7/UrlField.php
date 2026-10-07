<?php

namespace Drupal\migrate_url2link\Plugin\migrate\field\d7;

use Drupal\migrate_drupal\Plugin\migrate\field\FieldPluginBase;

/**
 * @MigrateField(
 *   id = "field_url",
 *   core = {7},
 *   type_map = {
 *    "url" = "link"
 *   },
 *   source_module = "url",
 *   destination_module = "link"
 * )
 */
class UrlField extends FieldPluginBase {

  /**
   * {@inheritdoc}
   */
  public function getFieldFormatterMap() {
    return [
      'url_default' => 'link',
      'url_plain' => 'link',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getFieldWidgetMap() {
    return [
      'url_external' => 'link_default',
    ];
  }

}
