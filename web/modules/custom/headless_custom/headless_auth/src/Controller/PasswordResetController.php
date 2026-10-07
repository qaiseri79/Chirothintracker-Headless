<?php

declare(strict_types=1);

namespace Drupal\headless_auth\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\headless_auth\PasswordResetService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Public endpoints: email possession, rather than a portal session, authorizes reset. */
final class PasswordResetController extends ControllerBase {
  public function __construct(private readonly PasswordResetService $resetService) {}
  public static function create(ContainerInterface $container): self {
    return new self($container->get('headless_auth.password_reset'));
  }
  public function requestReset(Request $request): JsonResponse {
    return $this->respond(function () use ($request) {
      $data = $this->payload($request);
      $email = is_string($data['email'] ?? NULL) ? trim($data['email']) : '';
      if (strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new HttpException(400, 'Enter a valid email address.');
      }
      $this->resetService->request($email, $request->getClientIp() ?? 'unknown');
      return ['message' => 'If an account exists for that email address, you will receive a password reset link.'];
    });
  }
  public function reset(Request $request): JsonResponse {
    return $this->respond(function () use ($request) {
      $data = $this->payload($request);
      if (!is_int($data['uid'] ?? NULL) || $data['uid'] < 1 || !is_int($data['timestamp'] ?? NULL) || $data['timestamp'] < 1 || !is_string($data['hash'] ?? NULL) || !preg_match('/^[A-Za-z0-9_-]{43}$/D', $data['hash']) || !is_string($data['password'] ?? NULL)) {
        throw new HttpException(400, 'The reset link or password is invalid.');
      }
      $this->resetService->reset($data['uid'], $data['timestamp'], $data['hash'], $data['password'], $request->getClientIp() ?? 'unknown');
      return ['message' => 'Your password has been reset. Log in with your new password.'];
    });
  }
  private function payload(Request $request): array {
    if (strlen($request->getContent()) > 4096) {
      throw new HttpException(400, 'Invalid request.');
    }
    try { $data = json_decode($request->getContent(), TRUE, 16, JSON_THROW_ON_ERROR); }
    catch (\JsonException) { throw new HttpException(400, 'Invalid JSON request.'); }
    if (!is_array($data)) { throw new HttpException(400, 'Invalid request.'); }
    return $data;
  }
  private function respond(callable $action): JsonResponse {
    try { $response = new JsonResponse($action()); }
    catch (HttpException $e) { $response = new JsonResponse(['message' => $e->getMessage()], $e->getStatusCode()); }
    $response->headers->set('Cache-Control', 'no-store, private');
    return $response;
  }
}
