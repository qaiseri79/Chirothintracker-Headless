<?php

declare(strict_types=1);

namespace Drupal\headless_intake;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Backing store for headless intake invite tokens.
 *
 * Next.js owns the opaque token in the URL; this service owns the mapping
 * token -> clinic and the invariants (one-time use, expiry, revocation).
 * The clinic is always derived from the token server-side; no endpoint ever
 * accepts a clinic id from the request.
 *
 * Moved here from ctt_patient_intake, which now holds only the original
 * patient_intake email notification behaviour.
 */
final class IntakeInviteService {

  /**
   * Config object holding the invite settings.
   *
   * These live in this module's own config, not in ctt_patient_intake.settings,
   * so the split leaves the original module's config untouched and there is
   * nothing to migrate.
   */
  private const CONFIG_NAME = 'headless_intake.settings';

  public function __construct(
    private readonly Connection $database,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly RequestStack $requestStack,
  ) {}

  /**
   * Generates a new cryptographically random token value.
   *
   * @return string
   *   URL-safe hex token.
   */
  public function generateToken(): string {
    return bin2hex(random_bytes(18));
  }

  /**
   * Issues a token for a clinic.
   *
   * @param int $clinic_id
   *   Clinic (eck clinic entity) to grant access to.
   * @param int|null $expires_at
   *   Unix timestamp, or NULL for no expiry.
   * @param int $max_uses
   *   Successful submissions allowed. Default 1 (single patient).
   *
   * @return string
   *   The new token value.
   */
  public function issue(int $clinic_id, ?int $expires_at = NULL, int $max_uses = 1): string {
    $token = $this->generateToken();
    $this->database->insert('headless_intake_invite')
      ->fields([
        'token' => $token,
        'clinic_id' => $clinic_id,
        'status' => 'active',
        'expires_at' => $expires_at ?? 0,
        'max_uses' => max(1, $max_uses),
        'uses' => 0,
        'created' => time(),
      ])
      ->execute();
    return $token;
  }

  /**
   * Resolves a token to its current state.
   *
   * @param string $token
   *   Token value to look up.
   *
   * @return array{status: string, clinic_id?: int}
   *   status is one of: not_found, active, expired, exhausted, revoked.
   *   clinic_id is present only when the status is active.
   */
  public function resolve(string $token): array {
    $row = $this->loadRow($token);
    if ($row === NULL) {
      return ['status' => 'not_found'];
    }
    if ($row['status'] !== 'active') {
      return ['status' => $row['status']];
    }
    $now = time();
    if ((int) $row['expires_at'] > 0 && (int) $row['expires_at'] <= $now) {
      $this->setStatus($token, 'expired');
      return ['status' => 'expired'];
    }
    if ((int) $row['uses'] >= (int) $row['max_uses']) {
      $this->setStatus($token, 'exhausted');
      return ['status' => 'exhausted'];
    }
    return ['status' => 'active', 'clinic_id' => (int) $row['clinic_id']];
  }

  /**
   * Marks a token revoked.
   *
   * @param string $token
   *   Token value to revoke.
   *
   * @return bool
   *   FALSE if the token did not exist.
   */
  public function revoke(string $token): bool {
    $row = $this->loadRow($token);
    if ($row === NULL) {
      return FALSE;
    }
    $this->setStatus($token, 'revoked');
    return TRUE;
  }

  /**
   * Rows for a clinic, newest first, for the management UI.
   *
   * @param int $clinic_id
   *   Clinic whose tokens to list.
   *
   * @return array<int, array{token: string, status: string, clinic_id: int, expires_at: int, max_uses: int, uses: int, created: int}>
   *   Token rows, newest first.
   */
  public function list(int $clinic_id): array {
    $result = $this->database->select('headless_intake_invite', 'i')
      ->fields('i')
      ->condition('clinic_id', $clinic_id)
      ->orderBy('created', 'DESC')
      ->execute();
    $rows = [];
    foreach ($result as $row) {
      $rows[] = $this->castRow((array) $row);
    }
    return $rows;
  }

  /**
   * A single token row.
   *
   * @param string $token
   *   Token value to load.
   *
   * @return array{token: string, status: string, clinic_id: int, expires_at: int, max_uses: int, uses: int, created: int}|null
   *   The row, or NULL when unknown.
   */
  public function find(string $token): ?array {
    $row = $this->loadRow($token);
    return $row === NULL ? NULL : $this->castRow($row);
  }

  /**
   * Default "max submissions" for a newly generated link.
   *
   * @return int
   *   Submission limit, never below 1.
   */
  public function defaultMaxUses(): int {
    return max(1, (int) $this->config()->get('token_default_max_uses'));
  }

  /**
   * Default "expires after (days)" for a newly generated link.
   *
   * @return int
   *   Day count, where 0 means never expires.
   */
  public function defaultExpiryDays(): int {
    return max(0, (int) $this->config()->get('token_default_expiry_days'));
  }

  /**
   * Public base of the intake frontend (the Next.js app), from config.
   *
   * Falling back to this site's own host is a footgun, not a convenience: the
   * generated link would point at Drupal, which has no /intake/{token} route.
   * The settings description says as much, and the value is expected to be set.
   *
   * @return string
   *   Base URL without a trailing slash.
   */
  public function intakeBaseUrl(): string {
    $base = (string) $this->config()->get('intake_base_url');
    if ($base === '') {
      $base = $this->requestStack->getCurrentRequest()?->getSchemeAndHttpHost() ?? '';
    }
    return rtrim($base, '/');
  }

  /**
   * The share URL for a token on the intake frontend.
   *
   * @param string $token
   *   Token value to embed.
   *
   * @return string
   *   Absolute intake URL.
   */
  public function intakeUrl(string $token): string {
    return $this->intakeBaseUrl() . '/intake/' . $token;
  }

  /**
   * Atomically consumes one use of a token for a new submission.
   *
   * Uses a row lock so two concurrent submissions cannot both pass the
   * one-time check.
   *
   * @param string $token
   *   Token being spent.
   *
   * @return int|string
   *   The clinic id on success, or a rejection state (not_found, expired,
   *   exhausted, revoked) when the token cannot be used.
   */
  public function consume(string $token): int|string {
    $transaction = $this->database->startTransaction('intake_consume');
    try {
      $query = $this->database->select('headless_intake_invite', 'i')
        ->fields('i')
        ->condition('token', $token);
      $query->forUpdate();
      $row = $query->execute()->fetchAssoc();
      if ($row === NULL) {
        $transaction->rollBack();
        return 'not_found';
      }
      if ($row['status'] !== 'active') {
        $transaction->rollBack();
        return $row['status'];
      }
      $now = time();
      if ((int) $row['expires_at'] > 0 && (int) $row['expires_at'] <= $now) {
        $this->setStatus($token, 'expired');
        return 'expired';
      }
      $uses = (int) $row['uses'] + 1;
      $exhausted = $uses >= (int) $row['max_uses'];
      $this->database->update('headless_intake_invite')
        ->fields([
          'uses' => $uses,
          'status' => $exhausted ? 'exhausted' : 'active',
        ])
        ->condition('token', $token)
        ->execute();
      return (int) $row['clinic_id'];
    }
    catch (\Exception $e) {
      $transaction->rollBack();
      throw $e;
    }
  }

  /**
   * Undoes a consume() when the submission failed to save.
   *
   * Called right after a consume() in the same request, so the patient can fix
   * their input and retry with the same link instead of needing a new one.
   *
   * @param string $token
   *   Token to give a use back to.
   */
  public function release(string $token): void {
    $this->database->update('headless_intake_invite')
      ->expression('uses', 'uses - 1')
      ->fields(['status' => 'active'])
      ->condition('token', $token)
      ->condition('uses', 0, '>')
      ->execute();
  }

  /**
   * Resolves the authoritative legal/agreement text for a patient_intake field.
   *
   * Mirrors tools/export_intake_blueprint.php priority exactly:
   * field default -> EPP token template -> legacy D7 webform config.
   *
   * @param string $field_name
   *   Machine name of the field to resolve.
   * @param int $clinic_id
   *   Clinic the submission belongs to.
   *
   * @return array{text: string, source: string|null}
   *   The raw text and where it came from. source is NULL when nothing was
   *   found.
   */
  public function resolveLegalText(string $field_name, int $clinic_id): array {
    $conf = $this->configFactory->get("field.field.contact_message.patient_intake.$field_name");

    $default = $conf->get('default_value');
    if (is_array($default) && !empty($default[0]['value']) && $default[0]['value'] !== '') {
      return ['text' => $default[0]['value'], 'source' => 'default_value'];
    }

    $epp = $conf->get('third_party_settings.epp.value');
    if (is_string($epp) && $epp !== '') {
      return ['text' => $epp, 'source' => 'epp'];
    }

    if ($field_name === 'field_agreement') {
      $webform = $this->configFactory->get('webform.webform.patient_intake');
      $element = $this->findWebformElement($webform->getRawData(), 'informed_consent_and_release_of_liability');
      $value = is_array($element) ? ($element['#value'] ?? $element['value'] ?? NULL) : $element;
      if (is_string($value) && trim($value) !== '') {
        return ['text' => $value, 'source' => 'legacy_webform'];
      }
    }

    return ['text' => '', 'source' => NULL];
  }

  /**
   * Substitutes the known EPP tokens into legal text for a clinic.
   *
   * @param string $text
   *   Raw text containing EPP tokens.
   * @param int $clinic_id
   *   Clinic used to resolve the clinic brand token.
   *
   * @return string
   *   Text with known tokens replaced.
   */
  public function applyTokens(string $text, int $clinic_id): string {
    $site = $this->configFactory->get('system.site');
    $site_name = (string) $site->get('name');
    $site_url = (string) $site->get('url');
    if ($site_url === '') {
      $site_url = $this->requestStack->getCurrentRequest()?->getSchemeAndHttpHost() ?? '';
    }

    return strtr($text, [
      '[site:name]' => $site_name,
      '[site:url]' => $site_url,
      '[current-page:query:clinicbrand]' => $this->brandFor($clinic_id),
    ]);
  }

  /**
   * Human display name for a clinic.
   *
   * @param int $clinic_id
   *   Clinic entity id.
   *
   * @return string
   *   Clinic label, or an empty string when it no longer exists.
   */
  public function brandFor(int $clinic_id): string {
    $clinic = $this->entityTypeManager->getStorage('clinic')->load($clinic_id);
    return $clinic?->label() ?? '';
  }

  /**
   * Loads a raw token row.
   *
   * @param string $token
   *   Token value to load.
   *
   * @return array<string, mixed>|null
   *   Raw row, or NULL when unknown.
   */
  private function loadRow(string $token): ?array {
    $row = $this->database->select('headless_intake_invite', 'i')
      ->fields('i')
      ->condition('token', $token)
      ->execute()
      ->fetchAssoc();
    return $row === FALSE ? NULL : $row;
  }

  /**
   * Casts a raw database row to typed values.
   *
   * @param array<string, mixed> $row
   *   Raw row from the invite table.
   *
   * @return array{token: string, status: string, clinic_id: int, expires_at: int, max_uses: int, uses: int, created: int}
   *   Typed row.
   */
  private function castRow(array $row): array {
    return [
      'token' => (string) $row['token'],
      'status' => (string) $row['status'],
      'clinic_id' => (int) $row['clinic_id'],
      'expires_at' => (int) $row['expires_at'],
      'max_uses' => (int) $row['max_uses'],
      'uses' => (int) $row['uses'],
      'created' => (int) $row['created'],
    ];
  }

  /**
   * Writes a status value for a token.
   *
   * @param string $token
   *   Token to update.
   * @param string $status
   *   New status value.
   */
  private function setStatus(string $token, string $status): void {
    $this->database->update('headless_intake_invite')
      ->fields(['status' => $status])
      ->condition('token', $token)
      ->execute();
  }

  /**
   * Invite settings, in this module's own config object.
   *
   * @return \Drupal\Core\Config\ImmutableConfig
   *   The headless_intake.settings config.   */
  private function config(): ImmutableConfig {
    return $this->configFactory->get(self::CONFIG_NAME);
  }

  /**
   * Recursively locates a webform element by key.
   *
   * Parity with the blueprint generator, which walks the element tree the same
   * way.
   *
   * @param array<mixed> $data
   *   Webform element tree to search.
   * @param string $key
   *   Element key to find.
   *
   * @return mixed
   *   The matching element, or NULL when absent.
   */
  private function findWebformElement(array $data, string $key): mixed {
    foreach ($data as $name => $value) {
      if ($name === $key) {
        return $value;
      }
      if (is_array($value)) {
        $found = $this->findWebformElement($value, $key);
        if ($found !== NULL) {
          return $found;
        }
      }
    }
    return NULL;
  }

}
