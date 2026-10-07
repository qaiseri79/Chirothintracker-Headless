<?php

namespace Drupal\commerce_authnet_test\EventSubscriber;

use CommerceGuys\AuthNet\DataTypes\Setting;
use Drupal\commerce_authnet\Event\AuthorizeNetEvents;
use Drupal\commerce_authnet\Event\HostedPaymentSettingsEvent;
use Drupal\Core\Url;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * AuthorizeNet events subscriber.
 */
class AuthorizeNetEventSubscriber implements EventSubscriberInterface {

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      AuthorizeNetEvents::HOSTED_PAYMENT_SETTINGS => 'onHostedPaymentSettings',
    ];
  }

  /**
   * Changes the HostedPaymentSettings setting to display an iframe.
   *
   * The domain name must match the pattern "https//.".
   *
   * @param \Drupal\commerce_authnet\Event\HostedPaymentSettingsEvent $event
   *   The event.
   */
  public function onHostedPaymentSettings(HostedPaymentSettingsEvent $event): void {
    $hosted_settings = $event->getHostedPaymentSettings();

    $hosted_settings->removeSetting('hostedPaymentIFrameCommunicatorUrl');
    $hosted_settings->addSetting(new Setting('hostedPaymentIFrameCommunicatorUrl', [
      'url' => 'https://127.0.0.1/' . Url::fromRoute('commerce_authnet.iframe_communicator')->toString(),
    ]));
  }

}
