<?php

declare(strict_types=1);

namespace Drupal\headless_commerce;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\headless_clinic\ClinicService;
use Drupal\headless_clinic\ClinicException;
use Drupal\commerce_cart\CartManagerInterface;

class CommerceService {

  protected $entityTypeManager;
  protected $clinicService;
  protected $cartManager;

  public function __construct(EntityTypeManagerInterface $entity_type_manager, ClinicService $clinic_service, CartManagerInterface $cart_manager) {
    $this->entityTypeManager = $entity_type_manager;
    $this->clinicService = $clinic_service;
    $this->cartManager = $cart_manager;
  }

  private function getClinicForUser(AccountInterface $actor) {
    // Reusing logic from ClinicService to find primary clinic.
    // In headless_clinic, the doctor is tied to a clinic via field_clinic.
    $user = $this->entityTypeManager->getStorage('user')->load($actor->id());
    if (!$user || !$user->hasField('field_clinic') || $user->get('field_clinic')->isEmpty()) {
      throw new ClinicException('No clinic found for this user.', 404);
    }
    return $user->get('field_clinic')->entity;
  }

  private function getStoreForClinic($clinic) {
    if (!$clinic) return NULL;
    $store_storage = $this->entityTypeManager->getStorage('commerce_store');
    // Assuming the store is owned by the clinic owner.
    $owner_id = $clinic->getOwnerId();
    $stores = $store_storage->loadByProperties(['uid' => $owner_id]);
    return !empty($stores) ? reset($stores) : NULL;
  }

  public function savePaymentSettings(AccountInterface $actor, array $body): array {
    $clinic = $this->getClinicForUser($actor);

    $login_id = trim((string) ($body['apiLoginId'] ?? ''));
    $transaction_key = trim((string) ($body['transactionKey'] ?? ''));
    $public_client_key = trim((string) ($body['publicClientKey'] ?? ''));

    if ($login_id === '' || $transaction_key === '') {
      throw new ClinicException('API Login ID and Transaction Key are required.', 422);
    }

    $store = $this->getStoreForClinic($clinic);
    if (!$store) {
      throw new ClinicException('Commerce store not found for this clinic.', 404);
    }

    // Find or create the payment gateway for this store.
    $gateway_storage = $this->entityTypeManager->getStorage('commerce_payment_gateway');
    $gateway_id = 'clinic_' . $clinic->id() . '_authnet';

    $gateway = $gateway_storage->load($gateway_id);
    if (!$gateway) {
      $gateway = $gateway_storage->create([
        'id' => $gateway_id,
        'label' => 'Clinic ' . $clinic->id() . ' Authorize.net',
        'plugin' => 'authorizenet_acceptjs', // Standard authorize.net plugin ID
        'configuration' => [
          'api_login_id' => $login_id,
          'transaction_key' => $transaction_key,
          'client_key' => $public_client_key,
          'mode' => 'test',
          'payment_action' => 'authorize_and_capture',
          'collect_billing_information' => TRUE,
        ],
      ]);
    } else {
      $configuration = $gateway->getPluginConfiguration();
      $configuration['api_login_id'] = $login_id;
      $configuration['transaction_key'] = $transaction_key;
      $configuration['client_key'] = $public_client_key;
      $gateway->setPluginConfiguration($configuration);
    }
    $gateway->save();

    // Link gateway to the store
    if ($store->hasField('field_payment_gateway')) {
      $store->set('field_payment_gateway', $gateway->id());
      $store->save();
    } else {
      throw new ClinicException('The store is missing the field_payment_gateway configuration.', 500);
    }

    return ['success' => TRUE];
  }

  public function saveFulfillmentSettings(AccountInterface $actor, array $body): array {
    $clinic = $this->getClinicForUser($actor);

    $type = $body['type'] ?? '';
    if (!in_array($type, ['shipping', 'pickup', 'both'], TRUE)) {
      throw new ClinicException('Invalid fulfillment type. Must be shipping, pickup, or both.', 422);
    }

    if ($clinic->hasField('field_fulfillment_type')) {
      $clinic->set('field_fulfillment_type', $type);
      $clinic->save();
    }

    return ['success' => TRUE, 'type' => $type];
  }

  public function readCatalog(AccountInterface $actor): array {
    $clinic = $this->getClinicForUser($actor);
    $store = $this->getStoreForClinic($clinic);

    // Load all variations of the shared catalog
    $variation_storage = $this->entityTypeManager->getStorage('commerce_product_variation');
    $variations = $variation_storage->loadByProperties(['type' => 'chironutraceutical']);

    // Load existing overrides for this store.
    $overrides = [];
    if ($store) {
      $override_entities = $this->entityTypeManager->getStorage('product_override')->loadByProperties([
        'store_id' => $store->id(),
      ]);
      foreach ($override_entities as $override) {
        $overrides[$override->get('variation_id')->target_id] = [
          'priceMinor' => !$override->get('price')->isEmpty() ? (int) ($override->get('price')->first()->number * 100) : null,
          'status' => (bool) $override->get('status')->value,
        ];
      }
    }

    $catalog = [];
    foreach ($variations as $var) {
      $id = $var->id();
      $catalog[] = [
        'id' => $id,
        'title' => $var->getTitle(),
        'sku' => $var->getSku(),
        'basePriceMinor' => !$var->get('price')->isEmpty() ? (int) ($var->get('price')->first()->number * 100) : 0,
        'currency' => !$var->get('price')->isEmpty() ? $var->get('price')->first()->currency_code : 'USD',
        'override' => $overrides[$id] ?? null,
      ];
    }

    return ['catalog' => $catalog];
  }

  public function saveCatalogOverride(AccountInterface $actor, array $body): array {
    $clinic = $this->getClinicForUser($actor);
    $variation_id = $body['variationId'] ?? null;
    $price_minor = $body['priceMinor'] ?? null;
    $status = $body['status'] ?? true;

    if (!$variation_id) {
      throw new ClinicException('Variation ID is required.', 422);
    }

    $store = $this->getStoreForClinic($clinic);
    if (!$store) {
      throw new ClinicException('A store must be configured before managing the catalog.', 409);
    }

    $override_storage = $this->entityTypeManager->getStorage('product_override');
    $existing = $override_storage->loadByProperties([
      'store_id' => $store->id(),
      'variation_id' => $variation_id,
    ]);

    $override = reset($existing);
    if (!$override) {
      $override = $override_storage->create([
        'store_id' => $store->id(),
        'variation_id' => $variation_id,
      ]);
    }

    if ($price_minor !== null) {
      $variation = $this->entityTypeManager->getStorage('commerce_product_variation')->load($variation_id);
      $currency = !$variation->get('price')->isEmpty() ? $variation->get('price')->first()->currency_code : 'USD';
      $override->set('price', [
        'number' => number_format($price_minor / 100, 2, '.', ''),
        'currency_code' => $currency,
      ]);
    } else {
      $override->set('price', null);
    }

    $override->set('status', (bool) $status);
    $override->save();

    return ['success' => TRUE];
  }

  public function readCustomProducts(AccountInterface $actor): array {
    $clinic = $this->getClinicForUser($actor);
    $store = $this->getStoreForClinic($clinic);
    if (!$store) {
      return ['products' => []];
    }

    $product_storage = $this->entityTypeManager->getStorage('commerce_product');
    // For custom products, we assume the doctor creates them directly under their store.
    // They usually have a different bundle like 'default' or 'custom'.
    $products = $product_storage->loadByProperties(['stores' => $store->id()]);

    $result = [];
    foreach ($products as $product) {
      // Exclude shared catalog products which might be assigned to multiple stores
      if ($product->bundle() === 'chironutraceutical') {
        continue;
      }

      $var = $product->getDefaultVariation();
      $result[] = [
        'id' => $product->id(),
        'title' => $product->getTitle(),
        'sku' => $var ? $var->getSku() : '',
        'priceMinor' => ($var && !$var->get('price')->isEmpty()) ? (int) ($var->get('price')->first()->number * 100) : 0,
        'currency' => ($var && !$var->get('price')->isEmpty()) ? $var->get('price')->first()->currency_code : 'USD',
      ];
    }

    return ['products' => $result];
  }

  public function createCustomProduct(AccountInterface $actor, array $body): array {
    $clinic = $this->getClinicForUser($actor);
    $store = $this->getStoreForClinic($clinic);
    if (!$store) {
      throw new ClinicException('A store must be configured before managing custom products.', 409);
    }

    $title = trim($body['title'] ?? '');
    $sku = trim($body['sku'] ?? '');
    $price_minor = $body['priceMinor'] ?? 0;

    if ($title === '' || $sku === '') {
      throw new ClinicException('Title and SKU are required.', 422);
    }

    $variation = $this->entityTypeManager->getStorage('commerce_product_variation')->create([
      'type' => 'default',
      'sku' => $sku,
      'title' => $title,
      'status' => 1,
      'price' => [
        'number' => number_format($price_minor / 100, 2, '.', ''),
        'currency_code' => 'USD',
      ],
    ]);
    $variation->save();

    $product = $this->entityTypeManager->getStorage('commerce_product')->create([
      'type' => 'default',
      'title' => $title,
      'stores' => [$store->id()],
      'variations' => [$variation],
      'uid' => $actor->id(),
    ]);
    $product->save();

    return ['success' => TRUE, 'id' => $product->id()];
  }

  public function deleteCustomProduct(AccountInterface $actor, int $id): array {
    $clinic = $this->getClinicForUser($actor);
    $store = $this->getStoreForClinic($clinic);

    $product = $this->entityTypeManager->getStorage('commerce_product')->load($id);
    if (!$product) {
      throw new ClinicException('Product not found.', 404);
    }

    // Ensure the product belongs to their store
    $store_ids = array_column($product->get('stores')->getValue(), 'target_id');
    if (!$store || !in_array($store->id(), $store_ids)) {
      throw new ClinicException('You do not have permission to delete this product.', 403);
    }
    if ($product->bundle() === 'chironutraceutical') {
      throw new ClinicException('You cannot delete shared catalog products.', 403);
    }

    $product->delete();
    return ['success' => TRUE];
  }
}
