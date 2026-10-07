<?php

namespace Drupal\commerce_reports;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityListBuilder;

/**
 * Builds a listing of the order report entities.
 */
class ReportsListBuilder extends EntityListBuilder {

  /**
   * {@inheritdoc}
   */
  public function buildHeader() {
    $header['label'] = $this->t('Label');
    $header['amount'] = $this->t('Amount');
    return $header + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity) {
    /** @var \Drupal\commerce_reports\Entity\OrderReportInterface $entity */
    $row['title']['data'] = $entity->toLink(NULL, 'canonical')->toString();
    $row['amount']['data'] = [
      '#type' => 'inline_template',
      '#template' => '{{ amount|commerce_price_format }}',
      '#context' => [
        'amount' => $entity->get('amount')->first()->toPrice(),
      ],
    ];
    return $row + parent::buildRow($entity);
  }

}
