<?php

namespace Drupal\xnumber\Plugin\Field\FieldType;

use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\xnumber\Utility\Xnumber as Numeric;

/**
 * Base class for xnumeric configurable field types.
 */
abstract class XnumericItemBase extends FieldItemBase {

  /**
   * {@inheritdoc}
   */
  public static function defaultFieldSettings() {
    return [
      'step' => '',
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
  public function fieldSettingsForm(array $form, FormStateInterface $form_state) {
    $element = [];
    $none = $this->t('None', [], ['context' => 'numeric item']);
    $settings = $this->getSettings();
    extract($this->getValue());

    $base_default_value = $this->t('The <em>Default Value</em> field above is the regular number field displayed in a <em>default</em> form display mode. So, if any of the settings are changed on the mode then they will override the field settings. In its turn, the value on this <em>Default Value</em> field may be set to serve as a base default value for any of the form display modes, the <em>default</em> form display mode including (if not overriden).</ br>The current <em>Base Default Value</em>: <strong>@value</strong><h3>Need help?</h3>A verbose tutorial with a lot of screenshots can be found here: <a href=":href" target="_blank">admin/help/xnumber</a>', [
      '@value' => isset($value) && is_numeric($value) ? Numeric::toString($value) : $none,
      ':href' => 'https://git.drupalcode.org/project/xnumber/-/blob/2.0.x/README.md',
    ]);

    $element['base_default_value'] = [
      '#type' => 'details',
      '#title' => t('Base Default Value'),
      '#open' => TRUE,
      '#markup' => "<div class='description'>$base_default_value</div>",
    ];
    $element['step'] = [
      '#type' => 'number',
      '#title' => $this->t('Step', [], ['context' => 'numeric item']),
      '#description' => $this->t('The default minimum allowed amount to increment or decrement the field value. Note that setting an integer for this value on a decimal or float field restricts the input on the field to integer values only. Can be overriden on a form display mode. While updating this field it is recommended to keep the <em>Default Value</em>, <em>Minimum</em> and <em>Maximum</em> fields blank.'),
    ];
    $element['min'] = [
      '#type' => 'number',
      '#title' => $this->t('Minimum', [], ['context' => 'numeric item']),
      '#default_value' => Numeric::toString($settings['min']),
      '#description' => $this->t('The default minimum value that should be allowed in this field. Can be overriden on a form display mode. While updating this field it is recommended to keep the <em>Default Value</em> and <em>Maximum</em> fields blank. Leave blank for default minimum.'),
    ];
    $element['max'] = [
      '#type' => 'number',
      '#title' => $this->t('Maximum', [], ['context' => 'numeric item']),
      '#default_value' => Numeric::toString($settings['max']),
      '#description' => $this->t('The default maximum value that should be allowed in this field. Can be overriden on a form display mode. While updating this field it is recommended to keep the <em>Default Value</em> field blank. Leave blank for default maximum.'),
    ];
    $element['prefix'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Prefix', [], ['context' => 'numeric item']),
      '#default_value' => $settings['prefix'],
      '#size' => 60,
      '#description' => t("Define a default string that should be prefixed to the value, like '$ ' or '&euro; '. Leave blank for none. Separate singular and plural values with a pipe ('pound|pounds'). Can be overriden on a form display mode."),
    ];
    $element['suffix'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Suffix', [], ['context' => 'numeric item']),
      '#default_value' => $settings['suffix'],
      '#size' => 60,
      '#description' => t("Define a default string that should be suffixed to the value, like ' m', ' kb/s'. Leave blank for none. Separate singular and plural values with a pipe ('pound|pounds'). Can be overriden on a form display mode."),
    ];
    $element['placeholder'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Placeholder', [], ['context' => 'numeric item']),
      '#default_value' => $settings['placeholder'] ?? '',
      '#description' => t('The default text that will be shown inside the field until a value is entered. This hint is usually a sample value or a brief description of the expected format. Can be overriden on a form display mode.'),
    ];

    return $element;
  }

  /**
   * {@inheritdoc}
   */
  public function isEmpty() {
    if (empty($this->value) && Numeric::toString($this->value) !== '0') {
      return TRUE;
    }
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public static function getFormDisplaySettings(FieldDefinitionInterface $field_definition): array {
    $entity_type_id = $field_definition->getTargetEntityTypeId();
    $bundle = $field_definition->getTargetBundle();
    $field_name = $field_definition->getName();
    $config = [
      'type' => 'xnumber',
      'settings' => $field_definition->getSettings(),
    ];
    /** @var \Drupal\Core\Entity\EntityDisplayRepositoryInterface $display_repository */
    $display_repository = \Drupal::service('entity_display.repository');
    $widget = $display_repository->getFormDisplay($entity_type_id, $bundle);
    $widget->setComponent($field_name, $config);

    return $widget->getRenderer($field_name)->getFormDisplayModeSettings();
  }

  /**
   * {@inheritdoc}
   */
  public function preSave() {
    $violations = $this->validate();
    foreach ($violations->getIterator() as $violation) {
      throw new \InvalidArgumentException($violation->getMessage());
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getConstraints() {
    $constraint_manager = \Drupal::typedDataManager()->getValidationConstraintManager();
    $constraints = parent::getConstraints();
    $field_definition = $this->getFieldDefinition();
    $name = $field_definition->getName();
    if (!$field_definition->getTargetBundle()) {
      // The base field definitions have no target bundles so add it temporarily
      // to pass to the static::getFormDisplaySettings().
      $field_definition->setTargetBundle($this->getEntity()->bundle());
    }
    $settings = static::getFormDisplaySettings($field_definition);
    extract($settings);
    $min = $min !== '' ? $min : $floor;
    $max = $max !== '' ? $max : $ceil;
    $step_scale = is_numeric($step) ? Numeric::getDecimalDigits($step) : NULL;

    $value = $this->value;

    if (is_numeric($min)) {
      $constraints[] = $constraint_manager->create('ComplexData', [
        'value' => [
          'XnumberRange' => [
            'min' => $min,
            'stepScale' => $step_scale,
            'minMessage' => "$name must be higher than or equal to $min",
          ],
        ],
      ]);
    }
    if (is_numeric($max)) {
      $constraints[] = $constraint_manager->create('ComplexData', [
        'value' => [
          'XnumberRange' => [
            'max' => $max,
            'stepScale' => $step_scale,
            'maxMessage' => "$name must be lower than or equal to $max",
          ],
        ],
      ]);
    }
    if (is_numeric($step)) {
      $constraints[] = $constraint_manager->create('ComplexData', [
        'value' => [
          'XnumberValidStep' => [
            'step' => $step,
            'min' => $min,
            'message' => "$name is not a valid number. (number $value, step $step, min $min)",
          ],
        ],
      ]);
    }

    return $constraints;
  }

}
