<?php

namespace Drupal\contact_storage\Plugin\migrate\source;

use Drupal\migrate\Row;
use Drupal\migrate_drupal\Plugin\migrate\source\d7\FieldableEntity;

/**
 * Entityform source from database.
 *
 * @MigrateSource(
 *   id = "d7_entityform",
     source_module = "entityform"
 * )
 */
class Entityform extends FieldableEntity {

  /**
   * {@inheritdoc}
   */
  public function query() {
    $query = $this->select('entityform', 'e')
      ->fields('e')
      ->distinct()
      ->orderBy('entityform_id');

    if (isset($this->configuration['bundle'])) {
      $query->condition('e.type', (array) $this->configuration['bundle'], 'IN');
    }

    return $query;
  }

  /**
   * {@inheritdoc}
   */
  public function fields() {
    $fields = [
      'entityform_id' => $this->t('Primary Key: Identifier for a entityform.'),
      'type' => $this->t('The [entityform_type].type of this entityform.'),
      'language' => $this->t('The language of the entityform'),
      'created' => $this->t('The Unix timestamp when the entityform was created.'),
      'changed' => $this->t('The Unix timestamp when the entityform was most recently saved.'),
      'data' => $this->t('A serialized array of additional data.'),
      'uid' => $this->t('The {users}.uid of the associated user.'),
      'draft' => $this->t('Whether this form submission is a draft.'),
    ];
    return $fields;
  }
  /**
   * {@inheritdoc}
   */
  public function prepareRow(Row $row) {
    // Get Field API field values.
    $entityform_id = $row->getSourceProperty('entityform_id');
    // Get Field API field values.
    foreach (array_keys($this->getFields('entityform', $row->getSourceProperty('type'))) as $field) {
      $tid = $row->getSourceProperty('entityform_id');
      $row->setSourceProperty($field, $this->getFieldValues('entityform', $field, $tid));
    }
	
    return parent::prepareRow($row);
  }

  /**
   * {@inheritdoc}
   */
  public function getIds() {
    $ids['entityform_id']['type'] = 'integer';

    return $ids;
	 
							
  }

}