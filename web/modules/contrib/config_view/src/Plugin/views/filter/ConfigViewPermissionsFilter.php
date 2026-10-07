<?php

namespace Drupal\config_view\Plugin\views\filter;

use Drupal\user\Plugin\views\filter\Permissions;

/**
 * Filter handler for config entities used in the View.
 *
 * Operators have to be modified to the operators used by EntityFieldQuery
 * For Drupal 8.1.x those are allowed operations: 'IN', 'NOT IN','BETWEEN',
 * '=', '<>', '>', '>=', '<', '<=', 'STARTS_WITH', 'CONTAINS', 'ENDS_WITH'.
 *
 * @ingroup views_filter_handlers
 *
 * @ViewsFilter("config_view_permissions_filter")
 */
class ConfigViewPermissionsFilter extends Permissions {

  /**
   * Override empty and not empty operator labels to be clearer for user roles.
   *
   * @return array
   *   Return only accepted operations.
   */
  public function operators(): array {
    return [
      'IN' => [
        'title' => $this->t('Is one of'),
        'short' => $this->t('in'),
        'short_single' => $this->t('='),
        'method' => 'opSimple',
        'values' => 1,
      ],
      'NOT IN' => [
        'title' => $this->t('Is not one of'),
        'short' => $this->t('NOT IN'),
        'short_single' => $this->t('<>'),
        'method' => 'opSimple',
        'values' => 1,
      ],
    ];
  }

}
