<?php

namespace Drupal\xquantity_stock\Plugin\Field\FieldType;

use Drupal\xnumber\Plugin\Field\FieldType\XdecimalItem;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\xnumber\Utility\Xnumber as Numeric;
use Drupal\Core\Form\FormStateInterface;

/**
 * Defines the 'xquantity_stock' field type.
 *
 * @FieldType(
 *   id = "xquantity_stock",
 *   label = @Translation("Xquantity Stock (decimal)"),
 *   description = @Translation("This field stores a commerce product variation stock quantity."),
 *   category = @Translation("Number"),
 *   default_widget = "xquantity_stock",
 *   default_formatter = "xquantity_stock"
 * )
 */
class XquantityStockItem extends XdecimalItem {

  /**
   * {@inheritdoc}
   */
  public static function defaultFieldSettings(): array {
    return [
      'threshold' => '1800',
    ] + parent::defaultFieldSettings();
  }

  /**
   * {@inheritdoc}
   */
  public function fieldSettingsForm(array $form, FormStateInterface $form_state): array {
    $element = parent::fieldSettingsForm($form, $form_state);
    $settings = $this->getQuantityWidgetSettings();
    if (!empty($settings['step'])) {
      $element['step']['#step'] = $settings['step'];
      $element['min']['#step'] = $settings['step'];
      $element['max']['#step'] = $settings['step'];
    }
    $element['step']['#min'] = $settings['step'];
    $min = $settings['min'];
    $min = (!is_numeric($min) || ($min < 0)) && $settings['unsigned'] ? '0' : $min;
    $element['min']['#min'] = $min;
    $element['max']['#min'] = $min;

    $element['threshold'] = [
      '#type' => 'number',
      '#step' => '1',
      '#field_suffix' => $this->t('seconds', [], ['context' => 'xquantity stock']),
      '#title' => $this->t('Threshold', [], ['context' => 'xquantity stock']),
      '#description' => $this->t('Stock rotation threshold. Read more: <a href=":href" target="_blank">admin/help/xquantity_stock#stock-rotation</a>', [
        ':href' => '/admin/help/xquantity_stock#stock-rotation',
      ]),
      '#default_value' => $settings['threshold'],
    ];

    return $element;
  }

  /**
   * {@inheritdoc}
   */
  public function getQuantityWidgetSettings(): array {
    $settings = [];
    // If 'Add to cart' form display mode is enabled we prefer its settings
    // because exactly those settings are exposed to and used by a customer.
    $type_id = $this->getEntity()->getOrderItemTypeId();
    $form_display = \Drupal::entityTypeManager()->getStorage('entity_form_display');
    $form_display_mode = $form_display->load("commerce_order_item.{$type_id}.add_to_cart");
    $quantity = $form_display_mode ? $form_display_mode->getComponent('quantity') : NULL;

    if (!$quantity) {
      $form_display_mode = $form_display->load("commerce_order_item.{$type_id}.default");
      $quantity = $form_display_mode ? $form_display_mode->getComponent('quantity') : NULL;
    }

    if (isset($quantity['settings']['step'])) {
      $settings = $form_display_mode->getRenderer('quantity')->getFormDisplayModeSettings();
    }

    return $settings + $this->getSettings();
  }

  /**
   * {@inheritdoc}
   */
  public static function generateSampleValue(FieldDefinitionInterface $field_definition): array {
    $settings = $field_definition->getSettings();
    $precision = $settings['precision'] ?: 10;
    $scale = $settings['scale'] ?: 2;
    $step = $settings['step'] ?: '1';
    $ceil = $settings['max'] ?: '10';
    $floor = $settings['min'] ?: $step;
    $step_scale = Numeric::getDecimalDigits($step);

    // Explicit wrong value.
    $value = bcsub($floor, $step, $step_scale);
    while (bccomp($floor, $value, $step_scale) > 0
      || bccomp($value, $ceil, $step_scale) > 0
      || !Numeric::validStep($value, $step, $floor)
      ) {
      $value = bcadd(Numeric::toString(mt_rand(intval($floor), intval($ceil))), $step, $step_scale);
      $value = bcsub($value, bcmod($value, $step, $scale), $step_scale);
      $value = preg_replace('/^\d+\-/', '-', $value);
    }

    return ['value' => $value];
  }

}
