<?php

declare(strict_types=1);

namespace Drupal\headless_content;

/**
 * The answer to "which rows of this bundle may this caller read".
 *
 * Immutable, and produced only by {@see \Drupal\headless_content\ContentScope},
 * because this is the value that has to be threaded from the controller into the
 * query and back out again in the single-item check. Passing the account around
 * instead would mean resolving the clinic and reading the clinic's toggle twice
 * per request, on two different code paths that could disagree.
 *
 * Two modes, and they are not interchangeable:
 *
 * - `clinic`: rows whose `field_published_to` names this clinic. This is what the
 *   recipes and training Views filter on in both their block displays.
 * - `shared`: every published row in the bundle, whatever it names. Only the
 *   resources View does this, and only when the clinic has not set
 *   `field_hide_shared_resources`.
 *
 * @see \Drupal\headless_content\ContentScope for where the mode comes from.
 */
final class ContentVisibility {

  /**
   * Constructs a ContentVisibility.
   *
   * @param string $bundle
   *   Bundle machine name the mode applies to.
   * @param int $clinicId
   *   The caller's clinic. Always known, even in `shared` mode: the mode itself is
   *   derived from a field on that clinic, so a caller with no clinic has no
   *   visibility decision at all and is rejected upstream.
   * @param bool $clinicOnly
   *   TRUE to require `field_published_to` to contain this clinic, FALSE to
   *   accept the whole bundle.
   */
  private function __construct(
    public readonly string $bundle,
    public readonly int $clinicId,
    public readonly bool $clinicOnly,
  ) {}

  /**
   * Rows published to this clinic only.
   */
  public static function clinicScoped(string $bundle, int $clinicId): self {
    return new self($bundle, $clinicId, TRUE);
  }

  /**
   * Every published row in the bundle.
   */
  public static function wholeLibrary(string $bundle, int $clinicId): self {
    return new self($bundle, $clinicId, FALSE);
  }

  /**
   * A short label for the mode, for the response body and the frontend.
   *
   * @return string
   *   Either 'clinic' or 'shared'.
   */
  public function mode(): string {
    return $this->clinicOnly ? 'clinic' : 'shared';
  }

}
