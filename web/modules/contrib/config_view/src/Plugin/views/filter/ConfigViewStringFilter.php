<?php

namespace Drupal\config_view\Plugin\views\filter;

use Drupal\views\Plugin\views\filter\StringFilter;

/**
 * Filter handler for config entities.
 *
 * Operators have to be modified to the operators used by EntityFieldQuery
 * For Drupal 8.1.x those are allowed operations: 'IN', 'NOT IN','BETWEEN',
 * '=', '<>', '>', '>=', '<', '<=', 'STARTS_WITH', 'CONTAINS', 'ENDS_WITH'.
 *
 * @ingroup views_filter_handlers
 *
 * @ViewsFilter("config_view_string_filter")
 */
class ConfigViewStringFilter extends StringFilter {

  /**
   * EntityFiledQuery allows only following operations.
   *
   * @return array
   *   Return only accepted operations.
   */
  public function operators(): array {
    return [
      '=' => [
        'title' => $this->t('Is equal to'),
        'short' => $this->t('='),
        'method' => 'opEqual',
        'values' => 1,
      ],
      '!=' => [
        'title' => $this->t('Is not equal to'),
        'short' => $this->t('!='),
        'method' => 'opEqual',
        'values' => 1,
      ],
      'contains' => [
        'title' => $this->t('Contains'),
        'short' => $this->t('contains'),
        'method' => 'opContains',
        'values' => 1,
      ],
      'starts' => [
        'title' => $this->t('Starts with'),
        'short' => $this->t('begins'),
        'method' => 'opStartsWith',
        'values' => 1,
      ],
      'ends' => [
        'title' => $this->t('Ends with'),
        'short' => $this->t('ends'),
        'method' => 'opEndsWith',
        'values' => 1,
      ],
    ];
  }

}
