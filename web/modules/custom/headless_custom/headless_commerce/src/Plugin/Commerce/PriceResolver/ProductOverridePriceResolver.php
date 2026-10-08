<?php

namespace Drupal\headless_commerce\Plugin\Commerce\PriceResolver;

use Drupal\commerce\Context;
use Drupal\commerce_price\Resolver\PriceResolverInterface;
use Drupal\commerce\PurchasableEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Returns the overridden price for a product variation based on the current store.
 */
class ProductOverridePriceResolver implements PriceResolverInterface {

  protected $entityTypeManager;

  public function __construct(EntityTypeManagerInterface $entity_type_manager) {
    $this->entityTypeManager = $entity_type_manager;
  }

  public function resolve(PurchasableEntityInterface $entity, $quantity, Context $context) {
    if ($entity->getEntityTypeId() !== 'commerce_product_variation') {
      return NULL;
    }

    // Prioritize the store from the context (which in checkout comes from the order).
    $store = $context->getStore();
    if (!$store) {
      return NULL;
    }

    // Load overrides by store and product variation.
    // In our implementation, product_id might be used instead of variation_id, or both.
    // Ensure we handle whichever one was saved in CommerceService.
    $overrides = $this->entityTypeManager->getStorage('product_override')->loadByProperties([
      'store_id' => $store->id(),
      'variation_id' => $entity->id(),
      'status' => 1,
    ]);

    // Fallback to checking by product_id if the override is applied at the product level.
    if (empty($overrides)) {
        $overrides = $this->entityTypeManager->getStorage('product_override')->loadByProperties([
            'store_id' => $store->id(),
            'product_id' => $entity->getProductId(),
            'status' => 1,
        ]);
    }

    if (!empty($overrides)) {
      $override = reset($overrides);
      if (!$override->get('price')->isEmpty()) {
        return $override->get('price')->first()->toPrice();
      }
    }

    return NULL;
  }

}
