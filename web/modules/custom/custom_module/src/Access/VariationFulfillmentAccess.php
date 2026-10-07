<?php

namespace Drupal\custom_module\Access;

use Drupal\commerce_product\Entity\ProductVariationTypeInterface;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Restricts product variation "add" access by clinic fulfillment type.
 */
class VariationFulfillmentAccess
{

  /**
   * Maps variation type IDs to the fulfillment type that allows them.
   */
  protected const VARIATION_FULFILLMENT_MAP = [
    'chironutraceutical_variations' => 'shipping',
    'chironutraceutical_ns' => 'pickup',
  ];

  /**
   * Checks access to add a product variation based on clinic fulfillment type.
   *
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   The route match.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The currently logged-in user.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access result.
   */
  public static function access(RouteMatchInterface $route_match, AccountInterface $account)
  {
    $variation_type = $route_match->getParameter('commerce_product_variation_type');

    // Only restrict the two fulfillment-specific variation types.
    if (!$variation_type instanceof ProductVariationTypeInterface || !isset(self::VARIATION_FULFILLMENT_MAP[$variation_type->id()])) {
      return AccessResult::allowed();
    }

    $subscriber = \Drupal::service('custom_module.services')
      ->getSubscriberByChiropractor((int) $account->id());

    $access = AccessResult::allowed()->cachePerUser();

    if (!$subscriber) {
      return $access;
    }
    $access = $access->addCacheableDependency($subscriber);

    if (!$subscriber->hasField('field_clinic') || $subscriber->get('field_clinic')->isEmpty()) {
      return $access;
    }

    $clinic = $subscriber->get('field_clinic')->entity;
    if (!$clinic) {
      return $access;
    }
    $access = $access->addCacheableDependency($clinic);

    if (!$clinic->hasField('field_fulfillment_type') || $clinic->get('field_fulfillment_type')->isEmpty()) {
      return $access;
    }

    $fulfillment_type = $clinic->get('field_fulfillment_type')->value;

    // 'both' keeps every fulfillment-specific variation type available.
    if ($fulfillment_type === 'both') {
      return $access;
    }

    return self::VARIATION_FULFILLMENT_MAP[$variation_type->id()] === $fulfillment_type
      ? $access
      : AccessResult::forbidden()->cachePerUser()->addCacheableDependency($subscriber)->addCacheableDependency($clinic);
  }

}
