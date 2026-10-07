<?php

declare(strict_types=1);
namespace Drupal\headless_subscriptions\EventSubscriber;

use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\headless_subscriptions\SubscriptionService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\HttpKernel\Event\RequestEvent;

/** Enforce expiry before route access, including legacy Drupal pages, for NEW users only. */
final class ManagedAccountRoles implements EventSubscriberInterface {
  public function __construct(private readonly AccountProxyInterface $account, private readonly SubscriptionService $subscriptions, private readonly EntityTypeManagerInterface $entities, private readonly \Drupal\headless_subscriptions\Sponsorship $sponsorship) {}
  public static function getSubscribedEvents(): array { return [KernelEvents::REQUEST => ['onRequest', 34]]; }
  public function onRequest(RequestEvent $event): void {
    if (!$event->isMainRequest() || !$this->account->isAuthenticated()) return;
    $uid = (int) $this->account->id();
    if ($this->subscriptions->managed($uid)) $this->subscriptions->syncRoles($uid);
    else $this->sponsorship->syncRole($uid);
    if ($user = $this->entities->getStorage('user')->load($uid)) $this->account->setAccount($user);
  }
}
