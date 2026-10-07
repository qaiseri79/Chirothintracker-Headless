<?php

namespace Drupal\contact_storage\Plugin\migrate\source;

use Drupal\migrate\Row;
use Drupal\migrate_drupal\Plugin\migrate\source\DrupalSqlBase;

/**
 * Drupal 7 Entityform types source from database.
 *
 * @MigrateSource(
 *   id = "d7_entityform_type",
 *   source_module = "entityform"
 * )
 */
class EntityformType extends DrupalSqlBase {

  /**
   * {@inheritdoc}
   */
  public function query() {
    $query = $this->select('entityform_type', 'et')
      ->fields('et', [
        'id',
        'type',
        'label',
        'weight',
        'data',
        'status',
        'module',
      ]);
    return $query;					  
  }

  /**
   * {@inheritdoc}
   */
  public function fields() {
    $fields = [
      'id' => $this->t('Primary Key: Unique entityform type identifier..'),
      'type' => $this->t('The machine-readable name of this entityform type.'),
      'label' => $this->t('The human-readable name of this entityform type.'),
      'weight' => $this->t('The weight of this entityform type in relation to others.'),
      'data' => $this->t('A serialized array of additional data related to this entityform type.'),
      'status' => $this->t('The exportable status of the entity.'),
      'module' => $this->t('The name of the providing module if the entity has been defined in code.'),
    ];
    if ($this->moduleExists('comment')) {
      $fields += $this->getCommentFields();
    }
    return $fields;
  }

  /**
   * {@inheritdoc}
   */
  public function prepareRow(Row $row) {
    $data = unserialize($row->getSourceProperty('data'));
    $row->setSourceProperty('instruction_pre', $data['instruction_pre']);
    $type = $row->getSourceProperty('type');
    $id = $row->getSourceProperty('id');
    $label = $row->getSourceProperty('label');
    $weight = $row->getSourceProperty('weight');
    $status = $row->getSourceProperty('status');
    $module = $row->getSourceProperty('module');

    return parent::prepareRow($row);
  }

  /**
   * {@inheritdoc}
   */
  public function getIds() {
    $ids['id']['type'] = 'integer';

    return $ids;
  }

}
