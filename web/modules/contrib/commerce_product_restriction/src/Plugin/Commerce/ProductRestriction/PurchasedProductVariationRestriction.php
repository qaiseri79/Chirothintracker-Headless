<?php

namespace Drupal\commerce_product_restriction\Plugin\Commerce\ProductRestriction;

use Drupal\commerce_product_restriction\Annotation\ProductRestrictionPlugin;

/**
 * Provides product restriction by user role.
 *
 * @ProductRestrictionPlugin(
 *   id = "purchased_product_variations",
 *   label = @Translation("Restrict to users who have purchased specific product variation(s)"),
 *   category = @Translation("Purchases"),
 *   entity_type = "commerce_product"
 * )
 */
class PurchasedProductVariationRestriction extends PurchasedProductRestriction {

  /**
   * {@inheritdoc}
   */
  public function getEntityTypeId(): string {
    return 'commerce_product_variation';
  }

  /**
   * {@inheritdoc}
   */
  public static function getElementTitle(): \Drupal\Component\Render\FormattableMarkup|string|\Drupal\Core\StringTranslation\TranslatableMarkup {
    return t('Product variations user must have purchased');
  }


}
