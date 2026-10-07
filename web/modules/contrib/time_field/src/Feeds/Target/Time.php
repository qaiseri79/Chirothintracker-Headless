<?php

namespace Drupal\time_field\Feeds\Target;

use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\feeds\FieldTargetDefinition;
use Drupal\feeds\Plugin\Type\Target\FieldTargetBase;
use Drupal\time_field\Time as TimeService;

/**
 * Defines a time field mapper.
 *
 * @FeedsTarget(
 *   id = "time_feeds_target",
 *   field_types = {"time"}
 * )
 */
class Time extends FieldTargetBase {

  /**
   * {@inheritdoc}
   */
  protected static function prepareTarget(FieldDefinitionInterface $field_definition) {
    return FieldTargetDefinition::createFromFieldDefinition($field_definition)
      ->addProperty('value');
  }

  /**
   * {@inheritdoc}
   */
  protected function prepareValue($delta, array &$values) {
    if (is_string($values['value'])) {
      $values['value'] = TimeService::createFromHtml5Format($values['value']);
    }

    if (is_object($values['value'])) {
      $values['value'] = $values['value']->getTimestamp();
    }
  }

}
