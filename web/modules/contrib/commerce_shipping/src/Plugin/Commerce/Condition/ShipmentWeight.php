<?php

namespace Drupal\commerce_shipping\Plugin\Commerce\Condition;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\commerce\Plugin\Commerce\Condition\ConditionBase;
use Drupal\physical\MeasurementType;
use Drupal\physical\Weight;

/**
 * Provides the weight condition for shipments.
 *
 * @CommerceCondition(
 *   id = "shipment_weight",
 *   label = @Translation("Shipment weight"),
 *   category = @Translation("Shipment"),
 *   entity_type = "commerce_shipment",
 * )
 */
class ShipmentWeight extends ConditionBase {

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'operator' => '>',
      'weight' => NULL,
      'max_weight' => NULL,
    ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);

    $form['operator'] = [
      '#type' => 'select',
      '#title' => $this->t('Operator'),
      '#options' => $this->getComparisonOperators(),
      '#default_value' => $this->configuration['operator'],
      '#required' => TRUE,
    ];
    $form['weight'] = [
      '#type' => 'physical_measurement',
      '#measurement_type' => MeasurementType::WEIGHT,
      '#title' => $this->t('Weight', [], ['context' => 'physical']),
      '#default_value' => $this->configuration['weight'],
      '#required' => TRUE,
    ];
    $form['max_weight'] = [
      '#type' => 'physical_measurement',
      '#measurement_type' => MeasurementType::WEIGHT,
      '#title' => $this->t('Max weight'),
      '#default_value' => $this->configuration['max_weight'],
      '#states' => [
        'visible' => [
          ':input[name$="shipment_weight][configuration][form][operator]"]' => [
            ['value' => '> <'],
            ['value' => '>= <='],
            ['value' => '>= <'],
            ['value' => '> <='],
          ],
        ],
      ],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::validateConfigurationForm($form, $form_state);

    $values = $form_state->getValue($form['#parents']);
    $operator = $values['operator'];

    // Check if Max weight is not empty or less than weight. Add the error in
    // this case or update the value of max weight with measurement unit as
    // weight if everything is OK.
    if (in_array($operator, ['> <', '>= <=', '>= <', '> <='])) {
      if (empty($values['max_weight']['number'])) {
        $form_state->setError($form['max_weight'], $this->t('"Max weight" cannot be empty'));
        return;
      }
      $condition_unit = $values['weight']['unit'];
      $weight = new Weight($values['weight']['number'], $condition_unit);
      $max_weight = (new Weight($values['max_weight']['number'], $values['max_weight']['unit']))->convert($condition_unit);

      if ($operator === '> <' && $max_weight->lessThanOrEqual($weight)) {
        $form_state->setError($form['max_weight'], $this->t('"Max weight" cannot be less or equal to "Weight"'));
      }
      elseif ($max_weight->lessThan($weight)) {
        $form_state->setError($form['max_weight'], $this->t('"Max weight" cannot be less than "Weight"'));
      }
      else {
        $form_state->setValue(array_merge($form['#parents'], ['max_weight']), [
          'number' => $max_weight->getNumber(),
          'unit' => $max_weight->getUnit(),
        ]);
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::submitConfigurationForm($form, $form_state);

    $values = $form_state->getValue($form['#parents']);

    $operator = $values['operator'];
    if (!in_array($operator, ['> <', '>= <=', '>= <', '> <='])) {
      $values['max_weight'] = NULL;
    }

    $this->configuration['operator'] = $operator;
    $this->configuration['weight'] = $values['weight'];
    $this->configuration['max_weight'] = $values['max_weight'];
  }

  /**
   * {@inheritdoc}
   */
  public function evaluate(EntityInterface $entity) {
    $this->assertEntity($entity);
    /** @var \Drupal\commerce_shipping\Entity\ShipmentInterface $shipment */
    $shipment = $entity;
    $weight = $shipment->getWeight();
    if (!$weight) {
      // The conditions can't be applied until the weight is known.
      return FALSE;
    }
    $condition_unit = $this->configuration['weight']['unit'];
    /** @var \Drupal\physical\Weight $weight */
    $weight = $weight->convert($condition_unit);
    $condition_weight = new Weight($this->configuration['weight']['number'], $condition_unit);
    $operator = $this->configuration['operator'];

    $max_weight = NULL;
    if (!empty($this->configuration['max_weight']['number'])) {
      $max_weight = new Weight($this->configuration['max_weight']['number'], $this->configuration['max_weight']['unit']);
      $max_weight = $max_weight->convert($condition_unit);
    }

    switch ($operator) {
      case '>=':
        return $weight->greaterThanOrEqual($condition_weight);

      case '>':
        return $weight->greaterThan($condition_weight);

      case '<=':
        return $weight->lessThanOrEqual($condition_weight);

      case '<':
        return $weight->lessThan($condition_weight);

      case '==':
        return $weight->equals($condition_weight);

      case '> <':
        if (!$max_weight) {
          throw new \InvalidArgumentException("Max weight is not defined");
        }
        return $weight->greaterThan($condition_weight) && $weight->lessThan($max_weight);

      case '>= <=':
        if (!$max_weight) {
          throw new \InvalidArgumentException("Max weight is not defined");
        }
        return $weight->greaterThanOrEqual($condition_weight) && $weight->lessThanOrEqual($max_weight);

      case '>= <':
        if (!$max_weight) {
          throw new \InvalidArgumentException("Max weight is not defined");
        }
        return $weight->greaterThanOrEqual($condition_weight) && $weight->lessThan($max_weight);

      case '> <=':
        if (!$max_weight) {
          throw new \InvalidArgumentException("Max weight is not defined");
        }
        return $weight->greaterThan($condition_weight) && $weight->lessThanOrEqual($max_weight);

      default:
        throw new \InvalidArgumentException("Invalid operator $operator");
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function getComparisonOperators(): array {
    $operators = parent::getComparisonOperators();
    $operators['> <'] = $this->t('Between (exclusive)');
    $operators['>= <='] = $this->t('Between (inclusive)');
    $operators['>= <'] = $this->t('Between (>= Weight, < Max)');
    $operators['> <='] = $this->t('Between (> Weight, <= Max)');

    return $operators;
  }

}
