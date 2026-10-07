<?php
/**
 * Implements hook_views_data().
 */
function custom_module_views_data() {
  $data['custom_module']['table']['group'] = t('Custom Global');
  $data['custom_module']['table']['join'] = [
    '#global' => [],
  ];
  $data['custom_module']['phase_views_field'] = [
    'title' => t('Phase'),
    'help' => t('The Phase which the current project is in.'),
    'field' => [
      'id' => 'phase_views_field',
    ],
  ];
  $data['custom_module']['set_lock_field'] = [
    'title' => t('Custom Set Lock Phase'),
    'help' => t('Custom plugin to set lock phase in user.'),
    'field' => [
      'id' => 'set_lock_field',
    ],
  ];
  $data['custom_module']['customized_role_field'] = [
    'title' => t('Customized Role Field'),
    'help' => t('Meant to custom the role field'),
    'field' => [
      'id' => 'customized_role_field',
    ],
  ];
  $data['custom_module']['daily_change_field'] = [
    'title' => t('Daily Change'),
    'help' => t('a custom view field to calculate daily change.'),
    'field' => [
      'id' => 'daily_change_field',
    ],
  ];
  return $data;
}