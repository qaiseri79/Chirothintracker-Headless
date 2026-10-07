<?php
namespace Drupal\custom_module\EventSubscriber;

use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Drupal\Core\Session\AccountInterface;
use Symfony\Component\Routing\RequestContext;

class RedirectSubscriber implements EventSubscriberInterface {

  protected $currentUser;
  protected $requestContext;

  public function __construct(AccountInterface $currentUser, RequestContext $requestContext) {
    $this->currentUser = $currentUser;
    $this->requestContext = $requestContext;
  }

  public function checkRedirect(RequestEvent $event) {
    /* -------------------------------------------------------------------
     * DISABLED pending headless Drupal login in the Next.js frontend.
     *
     * Previously this redirected anonymous users on non-whitelisted domains
     * to /user/login (the Drupal session login page). The frontend now
     * authenticates patients against Drupal itself, so this redirect must
     * not fire for the portal pages or the API. Re-enable only if the
     * Next.js frontend stops owning the public routes.
     * ------------------------------------------------------------------- */
/*
    $request = $event->getRequest();
    // Only act for anonymous users
    if ($this->currentUser->isAnonymous()) {
      $host = $request->getHost();
      $main_domain = 'chirothintracker.com';
      // Allow main domain, www, booking, and meet subdomains
      if (
        $host !== $main_domain &&
        $host !== 'www.' . $main_domain &&
        $host !== 'booking.' . $main_domain &&
        $host !== 'meet.' . $main_domain &&
        $host !== 'dev.' . $main_domain &&
        $host !== 'chirothintracker.local.com'
      ) {
        $current_path = $request->getPathInfo();
        $excluded_paths = [
          '/user/login',
          '/user/password',
          '/intake',
        ];
        $excluded_prefixes = [
          '/user/reset',
          '/room',
          '/intake/',
          '/api/',
        ];
        if (in_array($current_path, $excluded_paths)) {
          return;
        }
        foreach ($excluded_prefixes as $prefix) {
          if (str_starts_with($current_path, $prefix)) {
            return;
          }
        }
        $event->setResponse(new RedirectResponse('/user/login'));
      }
    }
*/
}


  public static function getSubscribedEvents() {
    return [
      KernelEvents::REQUEST => ['checkRedirect', 31],
    ];
  }
}
