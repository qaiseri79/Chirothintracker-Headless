<?php
declare(strict_types=1);
namespace Drupal\headless_clinic;
final class ClinicException extends \RuntimeException {
  public function __construct(string $message, public readonly int $status = 400, public readonly array $fields = []) {
    parent::__construct($message);
  }
}
