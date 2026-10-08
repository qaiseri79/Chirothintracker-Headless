<?php

namespace Drupal\headless_commerce\StoreResolver;

use Drupal\commerce_store\Resolver\StoreResolverInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Resolves the store based on the active user's assigned clinic.
 */
class ClinicStoreResolver implements StoreResolverInterface {

  protected $entityTypeManager;
  protected $currentUser;

  public function __construct(EntityTypeManagerInterface $entity_type_manager, AccountInterface $current_user) {
    $this->entityTypeManager = $entity_type_manager;
    $this->currentUser = $current_user;
  }

  public function resolve() {
    // If user is anonymous, they shouldn't be checking out in our closed system.
    if ($this->currentUser->isAnonymous()) {
      return NULL;
    }

    $user = $this->entityTypeManager->getStorage('user')->load($this->currentUser->id());

    // Check if the user is a patient with a linked clinic
    if ($user->hasField('field_clinic') && !$user->get('field_clinic')->isEmpty()) {
      $clinic = $user->get('field_clinic')->entity;
      if ($clinic) {
        // Find the store owned by the clinic owner.
        $store_storage = $this->entityTypeManager->getStorage('commerce_store');
        $stores = $store_storage->loadByProperties(['uid' => $clinic->getOwnerId()]);
        if (!empty($stores)) {
          return reset($stores);
        }
      }
    }

    return NULL;
  }
}
