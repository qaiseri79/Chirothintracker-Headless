<?php

declare(strict_types=1);

namespace Drupal\headless_intake;

/**
 * The caller may not act on the clinic they asked for.
 *
 * Separate from a missing resource on purpose: a 403 here means the request was
 * understood and refused, which is a different thing from the clinic not being
 * there, and a caller debugging their integration needs to tell them apart.
 */
final class ClinicLinkAccessDeniedException extends \RuntimeException {

  public function getStatusCode(): int {
    return 403;
  }

}