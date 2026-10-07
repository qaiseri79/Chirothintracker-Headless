<?php

declare(strict_types=1);
namespace Drupal\headless_subscriptions\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\headless_subscriptions\SubscriptionException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

final class SubscriptionController extends ControllerBase {
  public function handle(Request $request, string $action): JsonResponse {
    try {
      $uid = (int) $this->currentUser()->id();
      if ($request->getMethod() === 'POST' && $action !== 'webhook') {
        $flood = \Drupal::service('flood');
        $identity = $uid > 0 ? 'uid:' . $uid : 'ip:' . $request->getClientIp();
        $limit = $action === 'register' ? 5 : 30;
        if (!$flood->isAllowed('headless_subscriptions.' . $action, $limit, 3600, $identity)) throw new SubscriptionException('rate_limited', 'Too many requests. Try again later.', 429);
        $flood->register('headless_subscriptions.' . $action, 3600, $identity);
      }
      if (strlen($request->getContent()) > 16384) throw new SubscriptionException('payload_too_large', 'The request is too large.', 413);
      if ($request->getMethod() === 'POST' && !str_starts_with(strtolower($request->headers->get('Content-Type', '')), 'application/json')) {
        throw new SubscriptionException('json_required', 'Use application/json.', 415);
      }
      if ($request->getMethod() === 'POST' && $action !== 'webhook') {
        $origin = $request->headers->get('Origin');
        $allowed = array_merge([$request->getSchemeAndHttpHost()], \Drupal\Core\Site\Settings::get('headless_subscriptions_allowed_origins', []));
        if ($origin !== NULL && !in_array($origin, $allowed, TRUE)) throw new SubscriptionException('origin_denied', 'This request origin is not allowed.', 403);
      }
      $body = [];
      if ($request->getMethod() === 'POST') {
        try { $body = json_decode($request->getContent(), TRUE, 32, JSON_THROW_ON_ERROR); }
        catch (\JsonException $e) { throw new SubscriptionException('invalid_json', 'Provide a JSON request body.'); }
        if (!is_array($body) || !str_starts_with(ltrim($request->getContent()), '{')) throw new SubscriptionException('invalid_json', 'Provide a JSON object.');
      }
      $service = \Drupal::service('headless_subscriptions.subscription');
      $result = match ($action) {
        'plans' => ['plans' => \Drupal::service('headless_subscriptions.catalog')->all(), 'billing' => \Drupal::service('headless_subscriptions.gateway')->publicConfiguration()],
        'register' => \Drupal::service('headless_subscriptions.registration')->register($body),
        'cart_create' => $service->cart($this->planId($body), $uid),
        'cart_read' => $service->getCart((string) $request->query->get('id', ''), $uid),
        'checkout' => $service->checkout($uid, $body),
        'status' => $service->status($uid),
        'management' => $service->management($uid),
        'refresh' => $service->refresh($uid),
        'change_plan' => $service->changePlan($uid, $this->planId($body), is_string($body['quoteId'] ?? NULL) ? $body['quoteId'] : ''),
        'change_quote' => $service->planChangeQuote($uid, $this->planId($body)),
        'change_patients' => $service->planChangePatients($uid),
        'change_archive' => $service->archivePlanChangePatients($uid, $this->planId($body), is_array($body['patientIds'] ?? NULL) ? $body['patientIds'] : []),
        'cancel' => $service->cancel($uid),
        'resume' => $service->resume($uid),
        'payment_method' => $service->updatePayment($uid, $body),
        'webhook' => $this->webhook($request, $body),
        default => throw new SubscriptionException('not_found', 'Unknown operation.', 404),
      };
      return $this->jsonResult($result, $action === 'register' ? 201 : 200);
    }
    catch (SubscriptionException $e) { return $this->jsonResult(['error' => $e->error, 'message' => $e->getMessage(), 'fields' => $e->fields ?: NULL], $e->status); }
    catch (\Throwable $e) {
      // Do not expose passwords, payment nonces, or HTTP request/response bodies.
      \Drupal::logger('headless_subscriptions')->error('Subscription action @action failed for uid @uid (@class, line @line).', ['@action' => $action, '@uid' => (int) $this->currentUser()->id(), '@class' => get_class($e), '@line' => $e->getLine()]);
      return $this->jsonResult(['error' => 'server_error', 'message' => 'The subscription operation could not be completed.'], 500);
    }
  }
  private function planId(array $body): int {
    $id = $body['planId'] ?? NULL;
    if (!is_int($id) || $id <= 0) throw new SubscriptionException('invalid_plan', 'Provide a numeric plan ID.', 422);
    return $id;
  }
  private function jsonResult(array $body, int $status): JsonResponse {
    return new JsonResponse($body, $status, ['Cache-Control' => 'private, no-store']);
  }
  private function webhook(Request $request, array $body): array {
    $gateway = \Drupal::service('headless_subscriptions.gateway');
    if (!$gateway->verifySignature($request->getContent(), $request->headers->get('X-ANET-Signature', ''))) throw new SubscriptionException('invalid_signature', 'Invalid payment notification signature.', 401);
    $id = $body['notificationId'] ?? '';
    $type = $body['eventType'] ?? '';
    if (!is_string($id) || !preg_match('/^[a-zA-Z0-9-]{1,64}$/', $id) || !is_string($type) || !str_starts_with($type, 'net.authorize.') || !ctype_digit((string) ($body['payload']['id'] ?? ''))) throw new SubscriptionException('invalid_event', 'Invalid payment notification.');
    $event = ['eventType' => $type, 'payload' => ['id' => (string) $body['payload']['id']]];
    $repository = \Drupal::service('headless_subscriptions.repository');
    $eventId = hash('sha256', $gateway->context() . ':' . $id);
    if ($repository->addEvent($eventId, $event, \Drupal::time()->getCurrentTime())) \Drupal::queue('headless_subscription_events')->createItem($eventId);
    return ['received' => TRUE];
  }
}
