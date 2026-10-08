<?php

namespace Drupal\headless_commerce\Config;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\ConfigFactoryOverrideInterface;
use Drupal\Core\Config\StorageInterface;
use Drupal\key\KeyRepositoryInterface;

/**
 * Injects plaintext API keys into commerce_payment_gateway configuration at runtime.
 */
class GatewayKeyConfigOverride implements ConfigFactoryOverrideInterface {

  /**
   * The key repository.
   *
   * @var \Drupal\key\KeyRepositoryInterface
   */
  protected $keyRepository;

  /**
   * Constructs a new GatewayKeyConfigOverride object.
   *
   * @param \Drupal\key\KeyRepositoryInterface $key_repository
   *   The key repository.
   */
  public function __construct(KeyRepositoryInterface $key_repository) {
    $this->keyRepository = $key_repository;
  }

  /**
   * {@inheritdoc}
   */
  public function loadOverrides($names) {
    $overrides = [];
    foreach ($names as $name) {
      if (strpos($name, 'commerce_payment.commerce_payment_gateway.clinic_') === 0) {
        // We cannot use configFactory()->get() inside an override to avoid infinite loops,
        // we must read raw from storage or assume the config array structure.
        $storage = \Drupal::service('config.storage');
        $data = $storage->read($name);
        if ($data && isset($data['plugin']) && in_array($data['plugin'], ['authorizenet_acceptjs', 'authorizenet_echeck', 'authorizenet_visa_checkout'])) {

          $key_fields = ['api_login', 'transaction_key', 'client_key'];
          foreach ($key_fields as $field) {
            $key_id = $data['configuration'][$field] ?? '';
            if (!empty($key_id) && strpos($key_id, 'clinic_') === 0) {
              $key_entity = $this->keyRepository->getKey($key_id);
              if ($key_entity) {
                $overrides[$name]['configuration'][$field] = $key_entity->getKeyValue();
              }
            }
          }
        }
      }
    }
    return $overrides;
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheSuffix() {
    return 'GatewayKeyConfigOverride';
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheableMetadata($name) {
    return new CacheableMetadata();
  }

  /**
   * {@inheritdoc}
   */
  public function createConfigObject($name, $collection = StorageInterface::DEFAULT_COLLECTION) {
    return NULL;
  }

}
