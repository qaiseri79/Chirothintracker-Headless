<?php

namespace Drupal\headless_commerce\Entity;

use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;

/**
 * Defines the Product Override entity.
 *
 * @ContentEntityType(
 *   id = "product_override",
 *   label = @Translation("Product Override"),
 *   base_table = "product_override",
 *   entity_keys = {
 *     "id" = "id",
 *     "uuid" = "uuid",
 *   }
 * )
 */
class ProductOverride extends ContentEntityBase {

  public static function baseFieldDefinitions(EntityTypeInterface $entity_type) {
    $fields = parent::baseFieldDefinitions($entity_type);

    $fields['id'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('ID'))
      ->setDescription(t('The ID of the Product Override entity.'))
      ->setReadOnly(TRUE);

    $fields['uuid'] = BaseFieldDefinition::create('uuid')
      ->setLabel(t('UUID'))
      ->setDescription(t('The UUID of the Product Override entity.'))
      ->setReadOnly(TRUE);

    $fields['store_id'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Store'))
      ->setSetting('target_type', 'commerce_store')
      ->setRequired(TRUE);

    $fields['product_id'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Product'))
      ->setSetting('target_type', 'commerce_product')
      ->setRequired(FALSE);

    $fields['variation_id'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Product Variation'))
      ->setSetting('target_type', 'commerce_product_variation')
      ->setRequired(FALSE);

    $fields['price'] = BaseFieldDefinition::create('commerce_price')
      ->setLabel(t('Overridden Price'))
      ->setRequired(FALSE);

    $fields['status'] = BaseFieldDefinition::create('boolean')
      ->setLabel(t('Enabled'))
      ->setDefaultValue(TRUE);

    return $fields;
  }
}
