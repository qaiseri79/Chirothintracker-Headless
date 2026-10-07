<?php

namespace Drupal\custom_module\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Drupal\Core\Messenger\MessengerInterface;

/**
 * Custom event subscriber to alter dynamic error messages.
 */
class CustomErrorMessageSubscriber implements EventSubscriberInterface {

  /**
   * The messenger service.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messenger;

  /**
   * Constructs a CustomErrorMessageSubscriber object.
   *
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger service.
   */
  public function __construct(MessengerInterface $messenger) {
    $this->messenger = $messenger;
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    $events[KernelEvents::EXCEPTION][] = ['onException', 100];
    return $events;
  }

  /**
   * Alters dynamic error messages.
   */
  public function onException(ExceptionEvent $event) {
    $exception = $event->getThrowable();
    $message = $exception->getMessage();
    // Check and alter the specific error message.
    if (strpos($message, 'You already have the Chiropractor (Active) role.') !== FALSE) {
      $new_message = 'You cannot purchase this product because you already have the Chiropractor (Active) role.';
      $this->messenger->addError($new_message);
      $response = new Response();
      $response->setContent($new_message);
      $event->setResponse($response);
    }
  }
}