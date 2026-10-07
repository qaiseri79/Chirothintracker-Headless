<?php

namespace Drupal\custom_module\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Handles license expiration and user role revocation on subscription cancellation.
 */
class SubscriptionCancelSubscriber implements EventSubscriberInterface {

  protected $logger;
  protected EntityTypeManagerInterface $entityTypeManager;

  public function __construct(LoggerChannelFactoryInterface $logger_factory, EntityTypeManagerInterface $entity_type_manager) {
    $this->logger = $logger_factory->get('custom_module');
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      // Fires when subscription is canceled (includes payment failures)
      'commerce_subscription.cancel.post_transition' => 'onSubscriptionStateChange',
      // Fires when subscription expires
      'commerce_subscription.expire.post_transition' => 'onSubscriptionStateChange',
    ];
  }

  /**
   * Callback when a subscription state changes.
   */
  public function onSubscriptionStateChange($event): void {
    // Get the subscription entity from the event
    $subscription = $event->getEntity();
    $state = $subscription->getState()->getId();

    // Only process if subscription is canceled or expired
    if (!in_array($state, ['canceled', 'expired'])) {
      return;
    }

    $this->logger->notice('Processing subscription @id with state: @state', [
      '@id' => $subscription->id(),
      '@state' => $state,
    ]);

    // Get the user who owns this subscription
    $user = $subscription->getCustomer();
    if (!$user) {
      $this->logger->warning('Subscription @id has no customer.', [
        '@id' => $subscription->id(),
      ]);
      return;
    }

    // The new billing service owns access for explicitly registered accounts.
    if (\Drupal::hasService('headless_subscriptions.subscription')
      && \Drupal::service('headless_subscriptions.subscription')->managed((int) $user->id())) {
      return;
    }

    // Load ALL active licenses owned by this user
    try {
      $license_storage = $this->entityTypeManager->getStorage('commerce_license');
      $license_ids = $license_storage->getQuery()
        ->condition('uid', $user->id())
        ->condition('type', 'role')  // Only get role-type licenses
        ->condition('state', 'active')  // Only get active licenses
        ->accessCheck(FALSE)
        ->execute();

      if (empty($license_ids)) {
        $this->logger->notice('User @uid has no active role-type licenses to cancel.', [
          '@uid' => $user->id(),
        ]);
        return;
      }

      $licenses = $license_storage->loadMultiple($license_ids);
      
      $this->logger->notice('Found @count active role-type licenses for user @uid. Processing cancellation.', [
        '@count' => count($licenses),
        '@uid' => $user->id(),
      ]);

      foreach ($licenses as $license) {
        $this->processLicenseCancellation($license, $subscription, $user);
      }
    }
    catch (\Exception $e) {
      $this->logger->error('Error loading licenses for user @uid: @message', [
        '@uid' => $user->id(),
        '@message' => $e->getMessage(),
      ]);
    }
  }

  /**
   * Process license cancellation.
   */
  protected function processLicenseCancellation($license, $subscription, $user): void {
    // Ensure license has a state field.
    if (!$license->hasField('state')) {
      $this->logger->warning('License @id has no state field.', ['@id' => $license->id()]);
      return;
    }

    $state = $license->getState()->getId();

    // Double-check the license is active (should always be true due to query filter)
    if ($state === 'active') {
      try {
        $license->getState()->applyTransitionById('cancel');
        $license->save();
        $this->logger->notice('License @id canceled for user @uid due to subscription @sid cancellation.', [
          '@id' => $license->id(),
          '@uid' => $user->id(),
          '@sid' => $subscription->id(),
        ]);
        
        // Revoke roles attached to the license from the user.
        $this->revokeLicenseRoles($license, $user);
      }
      catch (\InvalidArgumentException $e) {
        $this->logger->warning('License @id could not be canceled — transition not available: @message', [
          '@id' => $license->id(),
          '@message' => $e->getMessage(),
        ]);
      }
    }
    else {
      $this->logger->notice('License @id is not active (current state: @state), skipping.', [
        '@id' => $license->id(),
        '@state' => $state,
      ]);
    }
  }

  /**
   * Revoke roles associated with a license from the user.
   */
  protected function revokeLicenseRoles($license, $user): void {
    if (!$license->hasField('license_role')) {
      $this->logger->notice('License @lid has no license_role field.', ['@lid' => $license->id()]);
      return;
    }

    $roles = $license->get('license_role')->getValue();
    if (empty($roles)) {
      $this->logger->notice('License @lid has no roles to revoke.', ['@lid' => $license->id()]);
      return;
    }

    foreach ($roles as $role_ref) {
      $role_id = $role_ref['target_id'];

      if ($user->hasRole($role_id)) {
        $user->removeRole($role_id);
        $user->save();
        $this->logger->notice('Role @role removed from user @uid due to license @lid cancellation.', [
          '@role' => $role_id,
          '@uid' => $user->id(),
          '@lid' => $license->id(),
        ]);
      }
      else {
        $this->logger->notice('User @uid does not have role @role; no action needed.', [
          '@uid' => $user->id(),
          '@role' => $role_id,
        ]);
      }
    }
  }

}