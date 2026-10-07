<?php

namespace Drupal\commerce_authnet\EventSubscriber;

use Drupal\Core\Url;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Kernel events subscriber.
 */
class KernelEventSubscriber implements EventSubscriberInterface {

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      KernelEvents::RESPONSE => [['onKernelResponse', -10]],
    ];
  }

  /**
   * Remove the X-Frame-Options header.
   *
   * @param \Symfony\Component\HttpKernel\Event\ResponseEvent $event
   *   The response event object.
   */
  public function onKernelResponse(ResponseEvent $event): void {
    // The response header "X-Frame-Options: sameorigin" refuses the site
    // contents to be iframed from the same site, where the frame is hosted.
    // The "iFrame Communicator" JavaScript code allows a channel to
    // communicate between the iframed JavaScript and the host JavaScript
    // functions to exchange information by communicating, which is required
    // in case of "Accept Hosted" payment gateway to sync the transaction
    // responses with Commerce Order checkout completion.
    // We need to remove the "X-Frame-Options" header for this response so that
    // the Drupal controller will be allowed to load the referenced JavaScript
    // code in iFrame.
    if ($event->getRequest()->getRequestUri() == Url::fromRoute('commerce_authnet.iframe_communicator')->toString()) {
      $event->getResponse()->headers->remove('X-Frame-Options');
    }
  }

}
