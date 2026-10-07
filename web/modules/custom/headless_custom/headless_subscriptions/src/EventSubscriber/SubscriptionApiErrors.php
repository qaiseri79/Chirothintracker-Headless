<?php

declare(strict_types=1);
namespace Drupal\headless_subscriptions\EventSubscriber;

use Drupal\Core\Session\AccountProxyInterface;
use Drupal\headless_subscriptions\SubscriptionRepository;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

/** Prevent HTML/login redirects only for subscription APIs and new managed doctors. */
final class SubscriptionApiErrors implements EventSubscriberInterface {
  public function __construct(private readonly AccountProxyInterface $account, private readonly SubscriptionRepository $repository) {}
  public static function getSubscribedEvents(): array { return [KernelEvents::EXCEPTION => ['onException', 150]]; }
  public function onException(ExceptionEvent $event): void {
    $path = $event->getRequest()->getPathInfo();
    $subscriptionApi = str_starts_with($path, '/api/headless/subscriptions/');
    $managedApi = str_starts_with($path, '/api/headless/') && $this->account->isAuthenticated() && $this->repository->account((int) $this->account->id());
    if (!$subscriptionApi && !$managedApi) return;
    $error = $event->getThrowable();
    $status = $error instanceof HttpExceptionInterface ? $error->getStatusCode() : 500;
    if ($status === 403 && $this->account->isAnonymous()) $status = 401;
    $code = match ($status) { 401 => 'unauthenticated', 403 => 'forbidden', 404 => 'not_found', 405 => 'method_not_allowed', default => 'server_error' };
    $event->setResponse(new JsonResponse(['error' => $code, 'message' => $status >= 500 ? 'The subscription operation could not be completed.' : 'This request is not permitted.'], $status, ['Cache-Control' => 'private, no-store']));
  }
}
