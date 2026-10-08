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

    $store = $context->getStore();
    if (!$store) {
      return NULL;
    }

    $overrides = $this->entityTypeManager->getStorage('product_override')->loadByProperties([
      'store_id' => $store->id(),
      'variation_id' => $entity->id(),
      'status' => 1,
    ]);

    if (!empty($overrides)) {
      $override = reset($overrides);
      if (!$override->get('price')->isEmpty()) {
        return $override->get('price')->first()->toPrice();
      }
    }

    return NULL;
  }

}
