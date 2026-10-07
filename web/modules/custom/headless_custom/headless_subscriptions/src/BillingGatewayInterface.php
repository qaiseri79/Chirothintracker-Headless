<?php

declare(strict_types=1);
namespace Drupal\headless_subscriptions;

interface BillingGatewayInterface {
  public function publicConfiguration(): array;
  public function paymentConfiguration(): array;
  public function context(): string;
  public function charge(array $purchase, array $opaqueData, array $billing, string $email): string;
  public function chargeProfile(array $purchase, int $amountMinor, string $reference): string;
  public function profile(string $transactionId, int $uid, string $email): array;
  public function schedule(array $purchase): string;
  public function subscription(string $id): array;
  public function transaction(string $id): array;
  public function cancel(string $id): void;
  public function updatePayment(array $purchase, array $opaqueData): void;
  public function paymentMethod(array $purchase): array;
  public function updateRecurringAmount(string $id, int $amountMinor): void;
  public function verifySignature(string $body, string $signature): bool;
}
