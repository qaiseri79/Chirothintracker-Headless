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

    // Validate Authorize.net credentials before saving.
    $authRequest = new \net\authorize\api\contract\v1\MerchantAuthenticationType();
    $authRequest->setName($login_id);
    $authRequest->setTransactionKey($transaction_key);

    $request = new \net\authorize\api\contract\v1\AuthenticateTestRequest();
    $request->setMerchantAuthentication($authRequest);

    $controller = new \net\authorize\api\controller\AuthenticateTestController($request);
    $response = $controller->executeWithApiResponse(\net\authorize\api\constants\ANetEnvironment::SANDBOX);

    if ($response == null || $response->getMessages()->getResultCode() != "Ok") {
        throw new ClinicException('Invalid Authorize.net credentials.', 422);
    }

    // Save keys using the Key module with the 'file' provider.
    $key_storage = $this->entityTypeManager->getStorage('key');

    // Helper function to create or update a key
    $save_key = function($id, $label, $value) use ($key_storage) {
      $key = $key_storage->load($id);
      $filepath = 'private://keys/' . $id . '.key';

      // Ensure private keys directory exists
      $dir = 'private://keys';
      \Drupal::service('file_system')->prepareDirectory($dir, \Drupal\Core\File\FileSystemInterface::CREATE_DIRECTORY | \Drupal\Core\File\FileSystemInterface::MODIFY_PERMISSIONS);

      file_put_contents($filepath, $value);

      if (!$key) {
        $key = $key_storage->create([
          'id' => $id,
          'label' => $label,
          'key_provider' => 'file',
          'key_provider_settings' => [
            'file_location' => $filepath,
            'base64_encoded' => FALSE,
          ],
          'key_type' => 'authentication',
        ]);
        $key->save();
      }
      return $key->id();
    };

    $login_key_id = $save_key('clinic_' . $clinic->id() . '_authnet_login', 'Clinic ' . $clinic->id() . ' AuthNet Login', $login_id);
    $tran_key_id = $save_key('clinic_' . $clinic->id() . '_authnet_tran', 'Clinic ' . $clinic->id() . ' AuthNet Tran Key', $transaction_key);
    $client_key_id = $save_key('clinic_' . $clinic->id() . '_authnet_client', 'Clinic ' . $clinic->id() . ' AuthNet Client Key', $public_client_key);

    $store = $this->getStoreForClinic($clinic);

    // Opt-in: Create the store if it does not exist yet.
    if (!$store) {
      $store = $this->entityTypeManager->getStorage('commerce_store')->create([
        'type' => 'online',
        'name' => $clinic->label() . ' Store',
        'uid' => $actor->id(),
        'default_currency' => 'USD',
        'mail' => $actor->getEmail(),
        'address' => [
          'country_code' => 'US',
        ],
      ]);
      $store->save();
    }

    // Find or create the payment gateway for this store.
    $gateway_storage = $this->entityTypeManager->getStorage('commerce_payment_gateway');
    $gateway_id = 'clinic_' . $clinic->id() . '_authnet';

    $gateway = $gateway_storage->loadOverrideFree($gateway_id);
    if (!$gateway) {
      $gateway = $gateway_storage->create([
        'id' => $gateway_id,
        'label' => 'Clinic ' . $clinic->id() . ' Authorize.net',
        'plugin' => 'authorizenet_acceptjs', // Standard authorize.net plugin ID
        'configuration' => [
          'api_login' => $login_key_id,
          'transaction_key' => $tran_key_id,
          'client_key' => $client_key_id,
          'mode' => 'test',
          'payment_action' => 'authorize_and_capture',
          'collect_billing_information' => TRUE,
        ],
        // Restrict this payment gateway exclusively to this store natively.
        'conditions' => [
          [
            'plugin' => 'order_store',
            'configuration' => [
              'stores' => [$store->uuid() => $store->uuid()],
            ],
          ]
        ],
        'conditionOperator' => 'AND',
      ]);
    } else {
      $configuration = $gateway->getPluginConfiguration();
      $configuration['api_login'] = $login_key_id;
      $configuration['transaction_key'] = $tran_key_id;
      $configuration['client_key'] = $client_key_id;
      $gateway->setPluginConfiguration($configuration);

      // Ensure condition remains enforced.
      $gateway->set('conditions', [
        [
          'plugin' => 'order_store',
          'configuration' => [
            'stores' => [$store->uuid() => $store->uuid()],
          ],
        ]
      ]);
    }
    $gateway->save();

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
      // Validation: Enforce cross-doctor security.
    if ($product->bundle() !== 'chironutraceutical') {
      // For custom products, require that the product's owner is the patient's doctor's store.
      $store_ids = array_column($product->get('stores')->getValue(), 'target_id');
      if (!in_array($store->id(), $store_ids)) {
         throw new ClinicException('This product is not available from your clinic.', 403);
      }
    } else {
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
    // Validation: Enforce cross-doctor security.
    if ($product->bundle() !== 'chironutraceutical') {
      // For custom products, require that the product's owner is the patient's doctor's store.
      $store_ids = array_column($product->get('stores')->getValue(), 'target_id');
      if (!in_array($store->id(), $store_ids)) {
         throw new ClinicException('This product is not available from your clinic.', 403);
      }
    } else {
      throw new ClinicException('You cannot delete shared catalog products.', 403);
    }

    $product->delete();
    return ['success' => TRUE];
  }

  public function addToCart(AccountInterface $actor, array $body): array {
    $variation_id = (int) ($body['variation_id'] ?? 0);
    $quantity = (int) ($body['quantity'] ?? 1);

    if (!$variation_id || $quantity <= 0) {
      throw new ClinicException('Invalid variation ID or quantity.', 422);
    }

    $variation = $this->entityTypeManager->getStorage('commerce_product_variation')->load($variation_id);
    if (!$variation) {
      throw new ClinicException('Product variation not found.', 404);
    }

    // Determine the doctor's store for the current patient/actor.
    // In our architecture, the patient might be tied to a doctor via another module,
    // or the doctor is the current user (if they are testing).
    // The instructions say: "Build a custom add-to-cart endpoint that explicitly gets or creates the cart for the patient's doctor's store"
    // So we use ClinicStoreResolver or logic to find the store.

    $store_resolver = \Drupal::service('headless_commerce.clinic_store_resolver');
    $store = $store_resolver->resolve();

    if (!$store) {
      throw new ClinicException('The clinic store could not be resolved.', 403);
    }

    // Explicitly refuse if field_ecommerce_enabled is false or the gateway is disabled.
    $clinic_storage = $this->entityTypeManager->getStorage('clinic');
    $clinics = $clinic_storage->loadByProperties(['uid' => $store->getOwnerId()]);
    $doctor_clinic = reset($clinics);
    if ($doctor_clinic && $doctor_clinic->hasField('field_ecommerce_enabled') && !(bool) $doctor_clinic->get('field_ecommerce_enabled')->value) {
      throw new ClinicException('The clinic store is currently disabled.', 403);
    }

    $gateway_storage = $this->entityTypeManager->getStorage('commerce_payment_gateway');
    $gateway_id = 'clinic_' . $doctor_clinic->id() . '_authnet';
    $gateway = $gateway_storage->load($gateway_id);

    $has_gateway = FALSE;
    if ($gateway && $gateway->status()) {
      $conditions = $gateway->get('conditions');
      if (!empty($conditions)) {
        foreach ($conditions as $condition) {
          if ($condition['plugin'] === 'order_store' && !empty($condition['configuration']['stores'][$store->uuid()])) {
            $has_gateway = TRUE;
            break;
          }
        }
      }
    }
    if (!$has_gateway) {
      throw new ClinicException('The clinic store does not have an active payment gateway.', 403);
    }

    // Validate that the doctor has an enabled ProductOverride for the product if it's a shared catalog product.
    $product = $variation->getProduct();
    // 'chironutraceutical' is the shared master product bundle.
    // Validation: Enforce cross-doctor security.
    if ($product->bundle() !== 'chironutraceutical') {
      // For custom products, require that the product's owner is the patient's doctor's store.
      $store_ids = array_column($product->get('stores')->getValue(), 'target_id');
      if (!in_array($store->id(), $store_ids)) {
         throw new ClinicException('This product is not available from your clinic.', 403);
      }
    } else {
      $override_storage = $this->entityTypeManager->getStorage('product_override');

      // Check for a specific variation override first.
      $overrides = $override_storage->loadByProperties([
        'store_id' => $store->id(),
        'variation_id' => $variation->id(),
        'status' => 1,
      ]);

      // If no variation override exists, check for a product-level override fallback.
      if (empty($overrides)) {
          $query = $override_storage->getQuery();
          $query->accessCheck(FALSE)
              ->condition('store_id', $store->id())
              ->condition('product_id', $product->id())
              ->condition('status', 1);

          // Ensure it's a true product-level override (no variation specified).
          $orGroup = $query->orConditionGroup()
              ->notExists('variation_id')
              ->condition('variation_id', 0)
              ->condition('variation_id', NULL, 'IS NULL');
          $query->condition($orGroup);

          $override_ids = $query->execute();
          if (!empty($override_ids)) {
              $overrides = $override_storage->loadMultiple($override_ids);
          }
      }

      if (empty($overrides)) {
        throw new ClinicException('This product is not currently available from your clinic.', 403);
      }
    }

    // Now explicitly get or create the cart in the doctor's store.
    $cart_provider = \Drupal::service('commerce_cart.cart_provider');
    $cart_manager = \Drupal::service('commerce_cart.cart_manager');

    // Create or get the default order type cart.
    $cart = $cart_provider->getCart('default', $store, $actor);
    if (!$cart) {
      $cart = $cart_provider->createCart('default', $store, $actor);
    }

    // Add entity to cart
    $order_item = $cart_manager->addEntity($cart, $variation, $quantity);

    return [
      'message' => 'Item added to cart',
      'cart_id' => $cart->id(),
      'item_id' => $order_item->id(),
    ];
  }
}