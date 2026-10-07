<?php

namespace Drupal\eca_cm\Form;

use Drupal\Core\Config\Entity\DraggableListBuilder;
use Drupal\Core\Entity\EntityInterface;

/**
 * Form listing ECA entities using the Classic modeler.
 */
class EcaListForm extends DraggableListBuilder {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'eca_cm_list';
  }

  /**
   * {@inheritdoc}
   */
  public function buildHeader() {
    $header = [
      'model' => $this->t('Model'),
      'events' => $this->t('Events'),
      'version' => $this->t('Version'),
      'enabled' => $this->t('Enabled'),
    ] + parent::buildHeader();
    return $header;
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity) {
    $row = [];

    /** @var \Drupal\eca\Entity\Eca $eca */
    $eca = $entity;

    $row['model'] = ['#markup' => $eca->label()];
    $row['events'] = [
      '#theme' => 'item_list',
      '#items' => $eca->getEventInfos(),
      '#attributes' => [
        'class' => ['eca-event-list'],
      ],
    ];
    $row['version'] = ['#markup' => $eca->getThirdPartySetting('modeler_api', 'version')];
    $row['enabled'] = ['#markup' => $eca->status() ? $this->t('Yes') : $this->t('No')];

    $row += parent::buildRow($entity);

    return $row;
  }

}
