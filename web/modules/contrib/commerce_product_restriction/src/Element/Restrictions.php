<?php

namespace Drupal\commerce_product_restriction\Element;

use Drupal\commerce\Element\CommerceElementTrait;
use Drupal\commerce\Element\Conditions as ElementConditions;
use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Form\FormStateInterface;

/**
 * Provides a form element for configuring restrictions.
 *
 * Usage example:
 *
 * @code
 * $form['conditions'] = [
 *   '#type' => 'commerce_restrictions',
 *   '#title' => 'Conditions',
 *   '#parent_entity_type' => 'commerce_promotion',
 *   '#entity_types' => ['commerce_order', 'commerce_order_item'],
 *   '#default_value' => [
 *     [
 *       'plugin' => 'order_total_price',
 *       'configuration' => [
 *         'operator' => '<',
 *         'amount' => [
 *           'number' => '10.00',
 *           'currency_code' => 'USD',
 *         ],
 *       ],
 *     ],
 *   ],
 * ];
 * @endcode
 *
 * @FormElement("commerce_restrictions")
 */
class Restrictions extends ElementConditions {

  use CommerceElementTrait;

  /**
   * Processes the restrictions form element.
   *
   * Only varies Conditions processConditions to change plugin manager and type.
   *
   * @param array $element
   *   The form element to process.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   * @param array $complete_form
   *   The complete form structure.
   *
   * @return array
   *   The processed element.
   *
   * @throws \InvalidArgumentException
   *   Thrown for missing or malformed #parent_entity_type, #entity_types,
   *   #default_value properties.
   */
  public static function processConditions(array &$element, FormStateInterface $form_state, array &$complete_form) {
    if (empty($element['#parent_entity_type'])) {
      throw new \InvalidArgumentException('The product_restriction element requires the #parent_entity_type property.');
    }
    if (empty($element['#entity_types'])) {
      throw new \InvalidArgumentException('The product_restriction element requires the #entity_types property.');
    }
    if (!is_array($element['#entity_types'])) {
      throw new \InvalidArgumentException('The product_restriction #entity_types property must be an array.');
    }
    if (!is_array($element['#default_value'])) {
      throw new \InvalidArgumentException('The product_restriction #default_value property must be an array.');
    }

    $default_value = array_column($element['#default_value'], 'configuration', 'plugin');

    /** @var \Drupal\commerce_product_restriction\Plugin\ProductRestrictionPluginManager $plugin_manager */
    $plugin_manager = \Drupal::service('plugin.manager.product_restriction');

    $definitions = $plugin_manager->getFilteredDefinitions($element['#parent_entity_type'], $element['#entity_types']);
    $grouped_definitions = [];
    foreach ($definitions as $plugin_id => $definition) {
      $category = (string) $definition['category'];
      $grouped_definitions[$category][$plugin_id] = $definition;
    }
    ksort($grouped_definitions);
    $tab_group = implode('][', array_merge($element['#parents'], ['conditions']));

    $element['#attached']['library'][] = 'commerce/conditions';
    $element['#after_build'] = [[get_called_class(), 'clearValues']];
    $element['#categories'] = [];

    // Render vertical tabs only if there is more than a single category.
    $render_vertical_tabs = count($grouped_definitions) > 1;
    if ($render_vertical_tabs) {
      $element['conditions'] = [
        '#type' => 'vertical_tabs',
        '#title' => $element['#title'],
      ];
    }
    else {
      $element['conditions_title'] = [
        '#type' => 'item',
        '#title' => $element['#title'],
      ];
      $element['conditions'] = [
        '#type' => 'container',
      ];
    }

    foreach ($grouped_definitions as $category => $definitions) {
      $category_id = preg_replace('/[^a-zA-Z\-]/', '_', strtolower($category));
      $element['#categories'][] = $category_id;

      $element[$category_id] = [
        '#type' => $render_vertical_tabs ? 'details' : 'container',
        '#title' => $category,
        '#group' => $tab_group,
      ];
      foreach ($definitions as $plugin_id => $definition) {
        $plugin = $plugin_manager->createInstance($plugin_id, $definition);
        /** @var \Drupal\commerce_product\Entity\Product|\Drupal\commerce_product\Entity\ProductVariation $entity */
        $entity = $form_state->getFormObject()->getEntity();
        if ($entity && !$plugin->checkApplicable($entity)) {
          continue;
        }

        $category_parents = array_merge($element['#parents'], [$category_id]);
        $checkbox_parents = array_merge($category_parents, [
          $plugin_id,
          'enable',
        ]);
        $checkbox_name = self::buildElementName($checkbox_parents);
        $checkbox_input = NestedArray::getValue($form_state->getUserInput(), $checkbox_parents);
        $enabled = isset($default_value[$plugin_id]) || !empty($checkbox_input);
        $ajax_wrapper_id = Html::getUniqueId('ajax-wrapper-' . $plugin_id);

        // Preselect the first vertical tab that has an enabled condition.
        if ($enabled && !isset($element['conditions']['#default_tab'])) {
          $element['conditions']['#default_tab'] = 'edit-' . implode('-', $category_parents);
        }

        $element[$category_id][$plugin_id] = [
          '#prefix' => '<div id="' . $ajax_wrapper_id . '">',
          '#suffix' => '</div>',
        ];

        // print_r($definition);
        $element[$category_id][$plugin_id]['enable'] = [
          '#type' => 'checkbox',
          '#title' => $definition['display_label'],
          '#description' => $definition['description'] ?? NULL,
          '#default_value' => $enabled,
          '#attributes' => [
            'class' => ['enable'],
          ],
          '#ajax' => [
            'callback' => [get_called_class(), 'ajaxRefresh'],
            'wrapper' => $ajax_wrapper_id,
          ],
        ];

        if ($enabled) {
          $inline_form_manager = \Drupal::service('plugin.manager.commerce_inline_form');
          $inline_form = $inline_form_manager->createInstance('plugin_configuration', [
            'plugin_type' => 'product_restriction',
            'plugin_id' => $plugin_id,
            'plugin_configuration' => isset($default_value[$plugin_id]) ? $default_value[$plugin_id] : [],
            'enforce_unique_parents' => FALSE,
          ]);
          $element[$category_id][$plugin_id]['configuration']['#inline_form'] = $inline_form;
          $element[$category_id][$plugin_id]['configuration']['#parents'] = array_merge(
            $element['#parents'],
            [
              $category_id,
              $plugin_id,
              'configuration',
            ]
          );
          $element[$category_id][$plugin_id]['configuration'] = $inline_form->buildInlineForm($element[$category_id][$plugin_id]['configuration'], $form_state);
          $element[$category_id][$plugin_id]['configuration']['#states'] = [
            'visible' => [
              ':input[name="' . $checkbox_name . '"]' => ['checked' => TRUE],
            ],
          ];
          // The element is already keyed by $plugin_id, no need to do it twice.
          $element[$category_id][$plugin_id]['configuration']['#enforce_unique_parents'] = FALSE;
        }
      }
    }

    return $element;
  }

}
