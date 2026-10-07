<?php

namespace Drupal\time_field\Plugin\Field\FieldType;

use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemList;
use Drupal\Core\Form\FormStateInterface;

/**
 * Represents a configurable entity time field.
 */
class TimeFieldItemList extends FieldItemList {

  /**
   * Defines the default value as now.
   */
  const DEFAULT_VALUE_NOW = 'now';

  /**
   * {@inheritdoc}
   */
  public function defaultValuesForm(array &$form, FormStateInterface $form_state) {
    $element = parent::defaultValuesForm($form, $form_state);
    if (empty($this->getFieldDefinition()->getDefaultValueCallback())) {
      $default_value = $this->getFieldDefinition()->getDefaultValueLiteral();
      $element['use_current_time'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Current time'),
        '#description' => $this->t('Use current time as the default value.'),
        '#default_value' => isset($default_value[0]) && $default_value[0] === static::DEFAULT_VALUE_NOW,
      ];
    }
    return $element;
  }

  /**
   * {@inheritdoc}
   */
  public function defaultValuesFormSubmit(array $element, array &$form, FormStateInterface $form_state) {
    if ($form_state->getValue(['default_value_input', 'use_current_time'])) {
      return [static::DEFAULT_VALUE_NOW];
    }
    return parent::defaultValuesFormSubmit($element, $form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public static function processDefaultValue($default_value, FieldableEntityInterface $entity, FieldDefinitionInterface $definition) {
    $default_value = parent::processDefaultValue($default_value, $entity, $definition);
    if (isset($default_value[0]) && $default_value[0] === static::DEFAULT_VALUE_NOW) {
      $default_value[0] = \Drupal::time()->getRequestTime() - strtotime('today midnight');
    }
    return $default_value;
  }

}
