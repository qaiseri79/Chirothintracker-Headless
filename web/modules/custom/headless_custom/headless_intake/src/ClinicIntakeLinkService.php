<?php

declare(strict_types=1);

namespace Drupal\headless_intake;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\eck\Entity\EckEntity;

/**
 * Owns the one permanent intake token each clinic has.
 *
 * Replaces the per-patient invite model. A clinic's token lives on the clinic
 * entity itself (`field_intake_token`), so there is exactly one link per clinic
 * for the life of the clinic, it never expires and it has no use counter. A
 * NULL value is the whole "not generated yet" state: no row anywhere means no
 * link, so a clinic that has never generated one costs nothing.
 *
 * Two properties are worth stating because the rest of the module depends on
 * them:
 *
 * - The token is the only tenant selector. Nothing accepts a clinic id from a
 *   request; the clinic is always looked up from the token, or from the
 *   signed-in doctor's own `field_clinic` for the authenticated endpoints.
 *
 * - `createIfMissing()` is idempotent at the database, not just in PHP. It
 *   relies on the unique index on the field table, so two doctors who
 *   double-click at the same moment cannot end up with two different links for
 *   one clinic, and cannot orphan whichever write lost the race.
 */
final class ClinicIntakeLinkService {

  /**
   * Field holding the clinic's token.
   */
  public const TOKEN_FIELD = 'field_intake_token';

  /**
   * Bytes of entropy behind the token.
   *
   * 18 bytes is 36 hex characters, which is what the existing invite tokens
   * use and what the frontend's token shape check already accepts. 144 bits is
   * far more than brute force can reach, and the field is a 64-character
   * column so the token is stored verbatim.
   */
  private const TOKEN_BYTES = 18;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly IntakeInviteService $inviteService,
  ) {}

  /**
   * Generates a new opaque token value.
   *
   * @return string
   *   36-character lowercase hex token.
   */
  public function generateToken(): string {
    return bin2hex(random_bytes(self::TOKEN_BYTES));
  }

  /**
   * The clinic's current token.
   *
   * @param int $clinicId
   *   Clinic entity id.
   *
   * @return string|null
   *   The stored token, or NULL when the clinic has never generated one.
   */
  public function tokenFor(int $clinicId): ?string {
    $token = $this->readToken($clinicId);

    return ($token === NULL || $token === '') ? NULL : $token;
  }

  /**
   * The clinic's intake URL.
   *
   * @param int $clinicId
   *   Clinic entity id.
   *
   * @return string|null
   *   Absolute intake URL, or NULL when the clinic has no token.
   */
  public function urlFor(int $clinicId): ?string {
    $token = $this->tokenFor($clinicId);

    return $token === NULL ? NULL : $this->inviteService->intakeUrl($token);
  }

  /**
   * The clinic's token, generating one only if there is none.
   *
   * Idempotent by design: a second call returns the first call's token and
   * writes nothing, so a double-clicked button or a retried request can never
   * invalidate a link that patients already have.
   *
   * @param int $clinicId
   *   Clinic entity id.
   *
   * @return string
   *   The clinic's token, existing or newly created.
   */
  public function createIfMissing(int $clinicId): string {
    $existing = $this->tokenFor($clinicId);
    if ($existing !== NULL) {
      return $existing;
    }

    $clinic = $this->loadClinic($clinicId);
    if ($clinic === NULL) {
      throw new \RuntimeException("Clinic $clinicId does not exist.");
    }

    // A random collision with another clinic's token is not a security event,
    // but storing it would silently point one clinic's patients at another
    // clinic's dashboard, so it is retried rather than accepted. The unique
    // index makes the retry path reachable only on a genuine collision.
    for ($attempt = 0; $attempt < 5; $attempt++) {
      $token = $this->generateToken();
      if ($this->tokenInUseByOtherClinic($token, $clinicId)) {
        continue;
      }
      try {
        $clinic->set(self::TOKEN_FIELD, $token);
        $clinic->save();
        $this->logger()->notice(
          'Generated a permanent intake link for clinic @clinic.',
          ['@clinic' => $clinicId],
        );
        return $token;
      }
      catch (\Exception $e) {
        // Lost a race against a concurrent create for this same clinic. The
        // winner's token is the correct answer, so re-read and return it.
        $winner = $this->tokenFor($clinicId);
        if ($winner !== NULL) {
          return $winner;
        }
        throw $e;
      }
    }

    throw new \RuntimeException('Could not find an unused intake token after 5 attempts.');
  }

  /**
   * Replaces the clinic's token with a new one.
   *
   * The old token stops resolving the moment this returns, which is the
   * documented behaviour: a regenerated link is not a second link, it is a
   * different link and the previous one dies.
   *
   * @param int $clinicId
   *   Clinic entity id.
   *
   * @return string
   *   The new token.
   *
   * @throws \Exception
   *   When the clinic has no token yet. Regenerating a link that does not exist
   *   is a caller mistake, not a way to create one: creation is
   *   createIfMissing(), which is idempotent, and mixing the two would make a
   *   double-click on the wrong button destroy a working link.
   */
  public function regenerate(int $clinicId): string {
    if ($this->tokenFor($clinicId) === NULL) {
      throw new \Exception('This clinic has no intake link yet.');
    }

    $clinic = $this->loadClinic($clinicId);
    if ($clinic === NULL) {
      throw new \Exception("Clinic $clinicId does not exist.");
    }

    for ($attempt = 0; $attempt < 5; $attempt++) {
      $token = $this->generateToken();
      if ($this->tokenInUseByOtherClinic($token, $clinicId)) {
        continue;
      }
      $clinic->set(self::TOKEN_FIELD, $token);
      $clinic->save();
      return $token;
    }

    throw new \Exception('Could not find an unused intake token after 5 attempts.');
  }

  /**
   * Resolves a public intake token to its clinic.
   *
   * @param string $token
   *   Token from the URL.
   *
   * @return int|null
   *   Clinic id, or NULL when the token is unknown, malformed, or points at a
   *   clinic that has since been deleted.
   */
  public function clinicIdForToken(string $token): ?int {
    // Same shape the frontend enforces before it ever calls Drupal. Checked
    // here too so a malformed token is a cheap miss rather than a query, and so
    // the public path and the browser agree on what a token looks like.
    if ($token === '' || preg_match('/^[A-Za-z0-9_-]{16,128}$/', $token) !== 1) {
      return NULL;
    }

    $ids = $this->entityTypeManager->getStorage('clinic')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition(self::TOKEN_FIELD, $token)
      ->execute();

    if ($ids === []) {
      return NULL;
    }

    $clinicId = (int) reset($ids);

    return $this->loadClinic($clinicId) === NULL ? NULL : $clinicId;
  }

  /**
   * Whether a clinic has an intake link.
   *
   * @param int $clinicId
   *   Clinic entity id.
   *
   * @return bool
   *   TRUE when a token is stored.
   */
  public function hasLink(int $clinicId): bool {
    return $this->tokenFor($clinicId) !== NULL;
  }

  /**
   * Reads the raw stored value.
   *
   * @param int $clinicId
   *   Clinic entity id.
   *
   * @return string|null
   *   Raw field value, NULL when absent or empty.
   */
  private function readToken(int $clinicId): ?string {
    $clinic = $this->loadClinic($clinicId);
    if ($clinic === NULL || !$clinic->hasField(self::TOKEN_FIELD)) {
      return NULL;
    }
    $value = $clinic->get(self::TOKEN_FIELD)->value;

    return is_string($value) && $value !== '' ? $value : NULL;
  }

  /**
   * Loads a clinic entity.
   *
   * @param int $clinicId
   *   Clinic entity id.
   *
   * @return \Drupal\eck\Entity\EckEntity|null
   *   The clinic, or NULL when it does not exist.
   */
  private function loadClinic(int $clinicId): ?EckEntity {
    if ($clinicId <= 0) {
      return NULL;
    }
    $clinic = $this->entityTypeManager->getStorage('clinic')->load($clinicId);

    return $clinic instanceof EckEntity ? $clinic : NULL;
  }

  /**
   * Whether a candidate token already belongs to a different clinic.
   *
   * @param string $token
   *   Candidate token.
   * @param int $clinicId
   *   Clinic that wants it.
   *
   * @return bool
   *   TRUE when taken by another clinic.
   */
  private function tokenInUseByOtherClinic(string $token, int $clinicId): bool {
    $ids = $this->entityTypeManager->getStorage('clinic')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition(self::TOKEN_FIELD, $token)
      ->execute();

    foreach ($ids as $id) {
      if ((int) $id !== $clinicId) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Module logger.
   *
   * @return \Psr\Log\LoggerInterface
   *   The headless_intake channel.
   */
  private function logger(): \Psr\Log\LoggerInterface {
    return \Drupal::logger('headless_intake');
  }

}