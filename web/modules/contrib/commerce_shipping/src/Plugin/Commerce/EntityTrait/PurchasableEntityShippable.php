<?php

namespace Drupal\commerce_shipping\Plugin\Commerce\EntityTrait;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\commerce\Plugin\Commerce\EntityTrait\EntityTraitBase;
use Drupal\entity\BundleFieldDefinition;
use Drupal\physical\MeasurementType;

/**
 * Provides the "purchasable_entity_shippable" trait.
 *
 * @CommerceEntityTrait(
 *   id = "purchasable_entity_shippable",
 *   label = @Translation("Shippable"),
 *   entity_types = {"commerce_product_variation"}
 * )
 */
class PurchasableEntityShippable extends EntityTraitBase {

  /**
   * {@inheritdoc}
   */
  public function buildFieldDefinitions() {
    $fields = [];
    $fields['weight'] = BundleFieldDefinition::create('physical_measurement')
      ->setLabel(new TranslatableMarkup('Weight', [], ['context' => 'physical']))
      ->setRequired(TRUE)
      ->setSetting('measurement_type', MeasurementType::WEIGHT)
      ->setDisplayOptions('form', [
        'type' => 'physical_measurement_default',
        'weight' => 91,
      ]);

    return $fields;
  }

}
