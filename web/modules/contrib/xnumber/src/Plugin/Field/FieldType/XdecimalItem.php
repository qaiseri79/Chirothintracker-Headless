<?php

namespace Drupal\xnumber\Plugin\Field\FieldType;

use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\xnumber\Utility\Xnumber as Numeric;

/**
 * Defines the 'decimal' field type.
 *
 * @FieldType(
 *   id = "xdecimal",
 *   label = @Translation("Xnumber (decimal)"),
 *   description = @Translation("This field stores a number in the database in a fixed decimal format."),
 *   category = @Translation("Number"),
 *   default_widget = "xnumber",
 *   default_formatter = "number_decimal"
 * )
 */
class XdecimalItem extends XnumericItemBase {

  /**
   * {@inheritdoc}
   */
  public static function defaultStorageSettings(): array {
    return [
      'unsigned' => FALSE,
      'precision' => 10,
      'scale' => 2,
    ] + parent::defaultStorageSettings();
  }

  /**
   * {@inheritdoc}
   */
  public static function defaultFieldSettings(): array {
    return [
      'step' => Numeric::toString(pow(0.1, static::defaultStorageSettings()['scale'])),
      'min' => '',
      'max' => '',
      'prefix' => '',
      'suffix' => '',
      'placeholder' => '',
    ] + parent::defaultFieldSettings();
  }

  /**
   * {@inheritdoc}
   */
  public function fieldSettingsForm(array $form, FormStateInterface $form_state): array {
    $element = parent::fieldSettingsForm($form, $form_state);
    $settings = $this->getSettings();

    $scale = is_numeric($settings['scale']) ? $settings['scale'] : static::defaultStorageSettings()['scale'];
    $precision = is_numeric($settings['precision']) ? $settings['precision'] : static::defaultStorageSettings()['precision'];
    $sizes = Numeric::getStorageMaxMin([
      'precision' => $precision,
      'scale' => $scale,
    ]);
    $step = Numeric::toString(pow(0.1, $scale));

    $element['step']['#step'] = $step;
    $element['step']['#min'] = $step;
    $min_step = "min $step";
    $step = isset($settings['step']) ? Numeric::toString($settings['step']) : $step;
    $element['step']['#default_value'] = $step;
    $element['min']['#step'] = $step;
    $element['max']['#step'] = $step;

    if (!empty($settings['unsigned'])) {
      $floor = '0';
      $min = $settings['min'] < $floor ? $floor : Numeric::toString($settings['min']);
      $ceil = $sizes['unsigned'];
      $max = !is_numeric($settings['max']) || $settings['max'] > $ceil ? $ceil : Numeric::toString($settings['max']);
    }
    else {
      $floor = $sizes['signed']['min'];
      $min = $settings['min'] < $floor ? $floor : Numeric::toString($settings['min']);
      $ceil = $sizes['signed']['max'];
      $max = !is_numeric($settings['max']) || $settings['max'] > $ceil ? $ceil : Numeric::toString($settings['max']);
    }

    $element['min']['#min'] = $floor;
    $element['max']['#min'] = $min;
    $element['max']['#max'] = $ceil;
    $element['min']['#max'] = $element['step']['#max'] = $max;
    $element['min']['#field_suffix'] = "min $floor, max $max";
    $element['max']['#field_suffix'] = "min $min, max $ceil";
    $element['step']['#field_suffix'] = "$min_step, max $max <mark><strong>Save it first!</strong></mark>";

    return $element;
  }

  /**
   * {@inheritdoc}
   */
  public static function propertyDefinitions(FieldStorageDefinitionInterface $field_definition): array {
    $properties['value'] = DataDefinition::create('string')
      ->setLabel(t('Decimal value'))
      ->setRequired(TRUE);

    return $properties;
  }

  /**
   * {@inheritdoc}
   */
  public static function schema(FieldStorageDefinitionInterface $field_definition): array {
    return [
      'columns' => [
        'value' => [
          'type' => 'numeric',
          'unsigned' => $field_definition->getSetting('unsigned'),
          'precision' => $field_definition->getSetting('precision'),
          'scale' => $field_definition->getSetting('scale'),
        ],
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function storageSettingsForm(array &$form, FormStateInterface $form_state, $has_data): array {
    $element = [];
    $settings = $this->getSettings();

    $element['unsigned'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Unsigned', [], ['context' => 'numeric item']),
      '#default_value' => !empty($settings['unsigned']) ? TRUE : FALSE,
      '#return_value' => TRUE,
      '#description' => $this->t('Whether the number should be restricted to positive values (without leading minus "-" sign).'),
      '#disabled' => $has_data,
    ];

    $element['precision'] = [
      '#type' => 'number',
      '#title' => $this->t('Precision', [], ['context' => 'numeric item']),
      '#min' => 2,
      '#max' => 32,
      '#default_value' => $settings['precision'],
      '#field_suffix' => $this->t('More than <strong>@precision</strong> digits precision is <a href=":href" target="_blank">not recommended</a>.', [
        '@precision' => 14,
        ':href' => 'https://www.php.net/manual/en/language.types.float.php',
      ]),
      '#description' => $this->t('The total number of digits to store in the database, including those to the right of the decimal. <a href=":href" target="_blank">Read more</a>.', [
        ':href' => 'https://dev.mysql.com/doc/refman/8.0/en/fixed-point-types.html',
      ]),
      '#disabled' => $has_data,
    ];

    $element['scale'] = [
      '#type' => 'number',
      '#title' => $this->t('Scale', [], ['context' => 'decimal places']),
      '#min' => 0,
      '#max' => 10,
      '#default_value' => $settings['scale'],
      '#description' => $this->t('The number of digits to the right of the decimal.'),
      '#disabled' => $has_data,
    ];

    return $element;
  }

  /**
   * {@inheritdoc}
   */
  public function getConstraints(): array {
    $constraint_manager = \Drupal::typedDataManager()->getValidationConstraintManager();
    $constraints = parent::getConstraints();
    $name = $this->getFieldDefinition()->getName();

    $constraints[] = $constraint_manager->create('ComplexData', [
      'value' => [
        'Regex' => [
          // The decimal number may also take a form of an integer without a
          // fractional part, so we accept the following formats with a possible
          // leading + or - sign:
          // integer - 0, 1, 10, 101
          // decimal - .1, .01, 0.1, 0.01, 1.1, 1.01, 10.1, 10.01
          // No scientific notation numbers will match as they don't need to. By
          // design they are converted to regular numbers presented as strings.
          // @see Drupal\Core\Render\Element\Number::valueCallback()
          // @see https://dev.mysql.com/doc/refman/5.7/en/precision-math-numbers.html
          'pattern' => '/^[+-]?((\d+(\.\d*)?)|(\.\d+))$/i',
          'message' => "$name is not a valid number. (pattern)",
        ],
      ],
    ]);

    return $constraints;
  }

  /**
   * {@inheritdoc}
   */
  public static function generateSampleValue(FieldDefinitionInterface $field_definition): array {
    $settings = parent::getFormDisplaySettings($field_definition);
    extract($settings);
    $field_name = $field_definition->getName();
    $field_settings = $field_definition->getSettings();

    if (bccomp($ceil, $floor) !== 1 || bccomp($ceil, $step) === -1) {
      return ['value' => $base_step] + $field_settings + $settings;
    }
    $scale = $field_settings['scale'];
    $step_scale = Numeric::getDecimalDigits($step);

    // Explicit wrong value.
    $value = bcsub($floor, $step, $step_scale);
    while (bccomp($floor, $value, $step_scale) > 0
      || bccomp($value, $ceil, $step_scale) > 0
      || !Numeric::validStep($value, $step)
      ) {
      $value = bcadd(Numeric::toString(mt_rand(intval($floor), intval($ceil))), $step, $step_scale);
      $value = bcsub($value, bcmod($value, $step, $scale), $step_scale);
      $value = preg_replace('/^\d+\-/', '-', $value);
    }
    $rand_min = $floor >= 0 ? '-1' : '1';
    while (bccomp($floor, $rand_min, $step_scale) > 0
      || bccomp($rand_min, $value, $step_scale) > 0
      || !Numeric::validStep($rand_min, $step)
      || !Numeric::validStep($value, $step, $rand_min)
      ) {
      $rand_min = bcadd(Numeric::toString(mt_rand(intval($floor), intval($value))), $step, $step_scale);
      $rand_min = bcsub($rand_min, bcmod($rand_min, $step, $scale), $step_scale);
      $rand_min = preg_replace('/^\d+\-/', '-', $rand_min);
    }
    $rand_max = bcadd($ceil, $step, $step_scale);
    while (bccomp($rand_max, $ceil, $step_scale) > 0
      || bccomp($value, $rand_max, $step_scale) > 0
      || !Numeric::validStep($rand_max, $step)
      ) {
      $rand_max = bcadd(Numeric::toString(mt_rand(intval($value), intval($ceil))), $step, $step_scale);
      $rand_max = bcsub($rand_max, bcmod($rand_max, $step, $scale), $step_scale);
      $rand_max = preg_replace('/^\d+\-/', '-', $rand_max);
    }

    return [
      'step' => $step,
      'min' => $rand_min,
      'value' => $value,
      'max' => $rand_max,
      'placeholder' => "{$field_name} placeholder",
      'prefix' => "{$field_name} prefix",
      'suffix' => "{$field_name} suffix",
      'default_value' => $value,
    ];
  }

}
