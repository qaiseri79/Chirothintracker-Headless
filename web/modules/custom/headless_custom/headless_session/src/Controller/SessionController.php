<?php

namespace Drupal\headless_session\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Reports the account behind the current session.
 *
 * Core can only say whether a session is authenticated
 * (`GET /user/login_status`), not who it belongs to, so a headless frontend
 * cannot restore the signed-in user. This endpoint is the single place that
 * answers that question, and it only ever describes the caller's own account.
 */
class SessionController extends ControllerBase {

  /**
   * Returns the current account.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   200 with the account, or 401 when there is no authenticated session.
   */
  public function current(): JsonResponse {
    $account = $this->currentUser();

    if (!$account->isAuthenticated()) {
      return new JsonResponse(['error' => 'unauthenticated'], 401);
    }

    $data = [
      'id' => (int) $account->id(),
      'name' => $account->getAccountName(),
      'mail' => $account->getEmail(),
      'roles' => array_values($account->getRoles()),
    ];
    if (\Drupal::hasService('headless_subscriptions.subscription')) {
      $subscriptions = \Drupal::service('headless_subscriptions.subscription');
      if ($subscriptions->managed((int) $account->id())) {
        $status = $subscriptions->status((int) $account->id());
        $data['subscription'] = $status['subscription'];
        $data['capabilities'] = $status['capabilities'];
      }
    }
    $data['portalAccess'] = \Drupal::service('headless_access.portal_access')->resolve($account);
    return new JsonResponse($data, 200, ['Cache-Control' => 'private, no-store']);
  }

}
