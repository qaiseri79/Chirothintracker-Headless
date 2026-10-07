<?php

declare(strict_types=1);

namespace Drupal\headless_intake;

/**
 * The operation needs a link that does not exist yet.
 *
 * Reported as 409 rather than 404: the clinic and the request both exist, they
 * are simply in the wrong order. Regenerating before generating is the only way
 * to hit this, and it is a client bug rather than a missing resource.
 */
final class ClinicLinkNotFoundException extends \RuntimeException {

  public function getStatusCode(): int {
    return 409;
  }

}