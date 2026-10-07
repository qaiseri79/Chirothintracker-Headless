<?php

namespace Drupal\eca_webform\Event;

use Drupal\Core\Database\Query\AlterableInterface;

/**
 * Provides submission query access alter event for eca_webform.
 *
 * @package Drupal\eca_webform\Event
 */
class SubmissionQueryAccessAlter extends WebformBaseEvent {

  /**
   * The query object.
   *
   * @var \Drupal\Core\Database\Query\AlterableInterface
   */
  protected AlterableInterface $query;

  /**
   * Table aliases or conditions.
   *
   * @var array
   */
  protected array $webformSubmissionTables;

  /**
   * Constructs the SubmissionQueryAccessAlter event.
   *
   * @param \Drupal\Core\Database\Query\AlterableInterface $query
   *   An Query object describing the composite parts of a SQL query.
   * @param array $webform_submission_tables
   *   An array webform submission tables that contains the table's alias and
   *   OR conditions which are used to build the alter query.
   */
  public function __construct(AlterableInterface $query, array $webform_submission_tables) {
    $this->query = $query;
    $this->webformSubmissionTables = $webform_submission_tables;
  }

  /**
   * The query object.
   *
   * @return \Drupal\Core\Database\Query\AlterableInterface
   *   The query object.
   */
  public function getQuery(): AlterableInterface {
    return $this->query;
  }

  /**
   * Table aliases or conditions.
   *
   * @return array
   *   Table aliases or conditions.
   */
  public function getWebformSubmissionTables(): array {
    return $this->webformSubmissionTables;
  }

}
