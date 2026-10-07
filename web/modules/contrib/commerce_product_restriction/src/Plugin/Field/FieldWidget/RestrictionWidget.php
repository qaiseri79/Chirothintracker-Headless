<?php

namespace Drupal\commerce_product_restriction\Plugin\Field\FieldWidget;

use Drupal\commerce\Plugin\Field\FieldWidget\ConditionsWidget;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Form\FormStateInterface;

/**
 * Plugin implementation of the 'commerce_conditions' widget.
 *
 * @FieldWidget(
 *   id = "commerce_restrictions",
 *   label = @Translation("Product restrictions"),
 *   field_types = {
 *     "commerce_plugin_item:product_restriction"
 *   },
 *   multiple_values = TRUE
 * )
 */
class RestrictionWidget extends ConditionsWidget {

  /**
   * {@inheritdoc}
   */
  public function formElement(FieldItemListInterface $items, $delta, array $element, array &$form, FormStateInterface $form_state) {
    $element = parent::formElement($items, $delta, $element, $form, $form_state);

    $element['form']['#type'] = 'commerce_restrictions';
    $element['form']['#entity_types'] = [
      'commerce_product',
      'commerce_product_variation',
    ];

    return $element;
  }

  /**
   * {@inheritdoc}
   */
  public function settingsForm(array $form, FormStateInterface $formState) {
    $element = [];
    return $element;
  }

  /**
   * {@inheritdoc}
   */
  public function settingsSummary() {
    return [];
  }

}
