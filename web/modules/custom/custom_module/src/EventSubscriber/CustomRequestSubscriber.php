<?php

namespace Drupal\custom_module\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Class CustomRequestSubscriber
 *
 * Handles role changes based on subscription status.
 */
class CustomRequestSubscriber implements EventSubscriberInterface
{

    protected $entityTypeManager;
    protected $currentUser;

    public function __construct(AccountProxyInterface $current_user, EntityTypeManagerInterface $entity_type_manager)
    {
        $this->currentUser = $current_user;
        $this->entityTypeManager = $entity_type_manager;
    }

    public function onKernelRequest(RequestEvent $event)
    {
        $user_id = $this->currentUser->id();

        // New subscriptions have their own paid-period policy. Legacy users stay unchanged.
        if (\Drupal::hasService('headless_subscriptions.subscription')
            && (\Drupal::service('headless_subscriptions.subscription')->managed((int) $user_id)
                || \Drupal::service('headless_subscriptions.repository')->member((int) $user_id))) {
            return;
        }


        // Handle Chiropractor role check.
        if ($this->currentUser->hasRole('chiropractor_active_')) {
            $user = $this->entityTypeManager->getStorage('user')->load($user_id);

            if (!$user->get('field_chiropractor_subscribers')->isEmpty()) {
                $subscriber_id = $user->get('field_chiropractor_subscribers')->target_id;

                $subscription_ids = $this->entityTypeManager
                    ->getStorage('commerce_subscription')
                    ->getQuery()
                    ->condition('uid', $subscriber_id)
                    ->condition('state', 'active')
                    ->accessCheck(FALSE)
                    ->execute();

                if (empty($subscription_ids)) {
                    $user->removeRole('chiropractor_active_');
                    $user->addRole('chiropractor_inactive_');
                    $user->save();

                    \Drupal::logger('custom_module')->notice('Chiropractor "@id" demoted to inactive.', ['@id' => $user_id]);
                }
            }
        }

        // Uncomment to check patient role based on assigned chiropractor's role.
        /*
        if ($this->currentUser->isAuthenticated() && $this->currentUser->hasRole('enrolled_patient')) {
          $user = User::load($user_id);
          $chiropractor_id = $user->get('field_chiropractor')->target_id ?? NULL;

          if ($chiropractor_id) {
            $chiropractor = User::load($chiropractor_id);
            if (!$chiropractor->hasRole('chiropractor_active_')) {
              $user->removeRole('enrolled_patient');
              $user->addRole('archived_patient');
              $user->save();

              \Drupal::logger('custom_module')->notice('Patient "@id" archived due to inactive chiropractor.', ['@id' => $user->id()]);
            }
          }
        }
        */
    }

    public static function getSubscribedEvents()
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 0],
        ];
    }

}
