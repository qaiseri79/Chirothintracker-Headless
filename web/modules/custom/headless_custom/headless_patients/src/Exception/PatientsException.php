<?php

declare(strict_types=1);

namespace Drupal\headless_patients\Exception;

/**
 * A failure that is the caller's problem, not the server's.
 *
 * Every expected refusal in this module raises one of these: no clinic on the
 * account, a record that is not in the caller's clinic, a payload that fails
 * validation, an email that already exists. The controller turns them into JSON
 * with the status code carried here, so the rules for what is an error live in
 * the services and the rules for how errors are reported live in one method.
 */
class PatientsException extends \RuntimeException {

  /**
   * Constructs a PatientsException.
   *
   * @param int $status
   *   HTTP status to return. 403 for a scope failure, 404 for a record outside
   *   the scope, 409 for a conflict, 422 for a bad payload.
   * @param array<int, string> $errors
   *   Per-field messages for the payload errors, keyed by payload field name.
   */
  public function __construct(
    string $message,
    private readonly int $status = 422,
    private readonly array $errors = [],
  ) {
    parent::__construct($message);
  }

  /**
   * The HTTP status this failure should be reported as.
   */
  public function getStatusCode(): int {
    return $this->status;
  }

  /**
   * Per-field messages, empty for failures that are not about the payload.
   *
   * @return array<int, string>
   */
  public function getErrors(): array {
    return $this->errors;
  }

  /**
   * The account has no clinic, so it has no roster to see.
   */
  public static function noClinic(): self {
    return new self('This account is not attached to a clinic.', 403);
  }

  /**
   * The record does not exist, or exists and belongs to another clinic.
   *
   * Deliberately the same answer for both. A caller that can distinguish "this
   * patient is not in my clinic" from "this patient does not exist" can probe
   * for the existence of patients belonging to other clinics, and the uid space
   * is shared.
   */
  public static function notFound(string $what): self {
    return new self(sprintf('No %s in this clinic.', $what), 404);
  }

  /**
   * A payload field failed validation.
   *
   * @param array<int, string> $errors
   *   Messages keyed by payload field name.
   */
  public static function invalid(array $errors): self {
    return new self('The submitted details are not valid.', 422, $errors);
  }
}
