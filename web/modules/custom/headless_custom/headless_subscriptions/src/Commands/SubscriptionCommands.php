<?php

declare(strict_types=1);
namespace Drupal\headless_subscriptions\Commands;

use Drush\Commands\DrushCommands;
use Drush\Attributes as CLI;

final class SubscriptionCommands extends DrushCommands {
  /** Reconcile a new-system subscription or recover a verified payment receipt. */
  #[CLI\Command(name: 'headless-subscriptions:reconcile')]
  #[CLI\Argument(name: 'purchase', description: 'A purchase ID from the new subscription system.')]
  #[CLI\Option(name: 'transaction-id', description: 'Original provider transaction ID, verified before recovery.')]
  #[CLI\Option(name: 'subscription-id', description: 'Previously created ARB subscription ID, required after an uncertain ARB response.')]
  public function reconcile(string $purchase, array $options = ['transaction-id' => NULL, 'subscription-id' => NULL]): void {
    $service = \Drupal::service('headless_subscriptions.subscription');
    if ($options['transaction-id']) $service->recover($purchase, (string) $options['transaction-id'], $options['subscription-id'] ? (string) $options['subscription-id'] : NULL);
    else $service->reconcile($purchase);
    $this->logger()->success('The new-system purchase was reconciled.');
  }

  /** Recover an uncertain mid-cycle payment using its original verified receipt. */
  #[CLI\Command(name: 'headless-subscriptions:recover-upgrade')]
  #[CLI\Argument(name: 'quote', description: 'The submitted upgrade quote ID.')]
  #[CLI\Argument(name: 'transaction', description: 'Original upgrade transaction ID, or none when no charge was due.')]
  #[CLI\Option(name: 'subscription-id', description: 'Previously created replacement ARB ID, when its response was lost.')]
  public function recoverUpgrade(string $quote, string $transaction, array $options = ['subscription-id' => NULL]): void {
    \Drupal::service('headless_subscriptions.subscription')->recoverPlanChange($quote, $transaction, $options['subscription-id'] ? (string) $options['subscription-id'] : NULL);
    $this->logger()->success('The upgrade was verified and recovered without a new charge.');
  }
}
