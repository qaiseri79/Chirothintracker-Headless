<?php


namespace Drupal\commerce_product_restriction\Plugin\Commerce\ProductRestriction;

use Drupal\commerce_product\Entity\Product;
use Drupal\Core\Session\AccountInterface;

trait PurchasedProductTrait {

  /**
   * Get the existing purchased number of variations for this product.
   *
   * Or specified variation IDs.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   User account.
   * @param \Drupal\commerce_product\Entity\Product $product
   *   Product entity.
   * @param array|null $variation_ids
   *   Array of variation Ids or null.
   *
   * @return int
   *   Count.
   */
  public function countUserPurchasedVariationOfProduct(AccountInterface $account, Product $product, array $variation_ids = NULL) {
    if (!$variation_ids) {
      $variation_ids = $product->getVariationIds();
    }

    if (!$variation_ids || count($variation_ids) == 0) {
      return FALSE;
    }

    $form_data = $this->context ? $this->context->getData('form') : NULL;
    $query = \Drupal::database()->select('commerce_order_item', 'i');
    $query->join('commerce_order', 'o', 'i.order_id = o.order_id');

    if (empty($form_data)) {
      // @todo allow selecting order states in config.
      $stateCondition = $query->orConditionGroup()
        ->condition('o.state', 'completed');
    }
    else {
      $stateCondition = $query->orConditionGroup()
        ->condition('o.state', 'processing')
        ->condition('o.state', 'completed');
    }

    $query->fields('i', ['order_item_id'])
      ->condition('i.purchased_entity', $variation_ids, 'IN')
      ->condition('o.uid', $account->id(), '=')
      ->condition('i.quantity', 0, ">")
      ->condition($stateCondition)
      ->distinct();

    $ids = $query->execute()->fetchCol();

    return count($ids);
  }

}