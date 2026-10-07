<?php

declare(strict_types=1);

namespace Drupal\headless_auth;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\headless_mail\Mailer;
use Drupal\user\UserInterface;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Password recovery uses core's signed tokens and password/session handling. */
final class PasswordResetService {
  public function __construct(
    private readonly EntityTypeManagerInterface $entities,
    private readonly ConfigFactoryInterface $config,
    private readonly FloodInterface $flood,
    private readonly TimeInterface $time,
    private readonly LockBackendInterface $lock,
    private readonly Mailer $mailer,
  ) {}

  public function timeout(): int {
    return max(1, (int) ($this->config->get('user.settings')->get('password_reset_timeout') ?? 86400));
  }

  public function request(string $email, string $ip): void {
    // The proxy's IP is deliberately not replaced by untrusted browser headers.
    $this->throttle('headless_auth.request_ip', $ip, 500);
    $base = rtrim((string) $this->config->get('headless_auth.settings')->get('frontend_base_url'), '/');
    $parts = parse_url($base);
    if (!$parts || !in_array($parts['scheme'] ?? '', ['http', 'https'], TRUE) || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
      throw new HttpException(503, 'Password recovery is not configured. Please contact support.');
    }
    // Throttle the identifier before lookup; account existence does not change
    // the response. Lowercasing applies only to the flood identifier.
    $identifier = hash('sha256', strtolower($email));
    if (!$this->flood->isAllowed('headless_auth.request_email', 5, 3600, $identifier)) {
      return;
    }
    $this->flood->register('headless_auth.request_email', 3600, $identifier);
    $accounts = $this->entities->getStorage('user')->loadByProperties(['mail' => $email, 'status' => 1]);
    $account = reset($accounts);
    if (!$account instanceof UserInterface || !(int) $account->id()) {
      return;
    }
    $timestamp = $this->time->getRequestTime();
    $url = $base . '/reset-password/' . $account->id() . '/' . $timestamp . '/' . user_pass_rehash($account, $timestamp);
    // Delivery failure is logged by the mailer, but must not expose whether
    // the submitted address belongs to an account.
    $this->mailer->send('headless_auth', 'password_reset', $account->getEmail(), [
      'url' => $url,
      'hours' => ceil($this->timeout() / 3600),
    ], $account->getPreferredLangcode());
  }

  public function validToken(UserInterface $account, int $timestamp, string $hash): bool {
    $now = $this->time->getRequestTime();
    return (int) $account->id() > 0 && $account->isActive()
      && $timestamp > 0 && $timestamp <= $now
      && $timestamp >= (int) $account->getLastLoginTime()
      // Unlike core's first-login exception, recovery always expires.
      && $now - $timestamp < $this->timeout()
      && hash_equals(user_pass_rehash($account, $timestamp), $hash);
  }

  public function reset(int $uid, int $timestamp, string $hash, string $password, string $ip): void {
    $this->throttle('headless_auth.reset_ip', $ip, 100);
    if (mb_strlen($password) < 8 || strlen($password) > 512) {
      throw new HttpException(400, 'Use a password with at least 8 characters (maximum 512 bytes).');
    }
    $name = 'headless_auth.reset.' . $uid;
    if (!$this->lock->acquire($name, 30)) {
      throw new HttpException(409, 'A reset is already in progress. Please try again.');
    }
    try {
      $storage = $this->entities->getStorage('user');
      $storage->resetCache([$uid]);
      $account = $storage->load($uid);
      if (!$account instanceof UserInterface || !$this->validToken($account, $timestamp, $hash)) {
        throw new HttpException(400, 'This reset link is invalid, expired, or has already been used. Request a new link.');
      }
      $account->setPassword($password);
      $violations = $account->get('pass')->validate();
      if ($violations->count()) {
        throw new HttpException(400, 'This password does not meet the account password requirements.');
      }
      // Core hashes the password and invalidates existing sessions on save.
      // The changed hash also invalidates this token and all outstanding links.
      $account->save();
      $this->flood->clear('user.password_request_user', (string) $uid);
    }
    finally {
      $this->lock->release($name);
    }
  }

  private function throttle(string $event, string $identifier, int $limit): void {
    if (!$this->flood->isAllowed($event, $limit, 3600, $identifier)) {
      throw new HttpException(429, 'Too many password recovery attempts. Please try again later.');
    }
    $this->flood->register($event, 3600, $identifier);
  }
}
