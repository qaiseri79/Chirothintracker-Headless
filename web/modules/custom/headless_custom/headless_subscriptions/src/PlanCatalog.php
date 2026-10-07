<?php

declare(strict_types=1);
namespace Drupal\headless_subscriptions;

use Drupal\Core\Entity\EntityTypeManagerInterface;

class PlanCatalog {
  public function __construct(private readonly EntityTypeManagerInterface $entities) {}
  public function all(): array {
    $storage = $this->entities->getStorage('commerce_product_variation');
    $ids = $storage->getQuery()->accessCheck(FALSE)->condition('type', 'portal_membership')->condition('status', 1)->sort('field_portal_plan_id')->execute();
    $plans = [];
    foreach ($storage->loadMultiple($ids) as $variation) {
      $product = $variation->getProduct();
      if (!$product || !$product->isPublished() || $product->bundle() !== 'portal_membership') continue;
      $price = $variation->getPrice();
      if (!$price || $price->getCurrencyCode() !== 'USD') continue;
      try { $minor = SubscriptionService::minor($price->getNumber()); } catch (SubscriptionException $e) { continue; }
      if ($minor <= 0) continue;
      $limit = (int) $variation->get('field_portal_patient_limit')->value;
      $interval = (int) $variation->get('field_portal_interval')->value;
      $planId = (int) $variation->get('field_portal_plan_id')->value;
      if ($planId <= 0 || !in_array($interval, [1, 12], TRUE) || $limit < 0) continue;
      foreach ($plans as $existing) if ($existing['id'] === $planId) throw new SubscriptionException('catalog_configuration', 'Subscription plan identifiers must be unique.', 503);
      $plans[] = [
        'id' => (int) $variation->get('field_portal_plan_id')->value,
        'sponsoredDoctors' => TRUE, 'clinicModel' => 'shared',
        'variationId' => (int) $variation->id(),
        'name' => $variation->getTitle(), 'amountMinor' => $minor,
        'price' => $minor / 100, 'currency' => 'USD',
        'intervalMonths' => (int) $variation->get('field_portal_interval')->value,
        'per' => (int) $variation->get('field_portal_interval')->value === 12 ? 'year' : 'month',
        'patientLimit' => $limit > 0 ? $limit : NULL,
        'ecommerce' => (bool) $variation->get('field_portal_ecommerce')->value,
        'laser' => (bool) $variation->get('field_portal_laser')->value,
        'trialPolicy' => $variation->get('field_portal_trial')->value ?: 'none',
        'activationFeeMinor' => 0,
      ];
    }
    return $plans;
  }
  public function require(int $id): array {
    foreach ($this->all() as $plan) if ($plan['id'] === $id) return $plan;
    throw new SubscriptionException('plan_unavailable', 'Choose an available subscription plan.', 404);
  }
}
