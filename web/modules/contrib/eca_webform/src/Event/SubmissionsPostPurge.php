<?php

namespace Drupal\eca_webform\Event;

/**
 * Provides submissions post purge event for eca_webform.
 *
 * @package Drupal\eca_webform\Event
 */
class SubmissionsPostPurge extends WebformBaseEvent {

  /**
   * The webform submissions being purged.
   *
   * @var \Drupal\webform\WebformSubmissionInterface[]
   */
  protected array $webformSubmissions;

  /**
   * Constructs the SubmissionsPostPurge event.
   *
   * @param \Drupal\webform\WebformSubmissionInterface[] $webform_submissions
   *   The webform submissions being purged.
   */
  public function __construct(array $webform_submissions) {
    $this->webformSubmissions = $webform_submissions;
  }

  /**
   * The webform submissions being purged.
   *
   * @return array
   *   The webform submissions being purged.
   */
  public function getWebformSubmissions(): array {
    return $this->webformSubmissions;
  }

}
