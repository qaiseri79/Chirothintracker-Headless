<?php

namespace Drupal\headless_progress\Exception;

/** Validation failures that can be shown next to progress form fields. */
final class ProgressValidationException extends \InvalidArgumentException {

  public function __construct(private readonly array $issues) {
    parent::__construct('Please check the progress fields.');
  }

  /** Returns field names and their validation messages. */
  public function getIssues(): array {
    return $this->issues;
  }

}
