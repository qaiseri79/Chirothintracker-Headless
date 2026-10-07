<?php

namespace Drupal\single_content_sync\EventSubscriber;

use Drupal\single_content_sync\Event\BulkExportRoutesEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Adds taxonomy routes to the bulk export local action.
 */
class TaxonomyBulkExportRoutesSubscriber implements EventSubscriberInterface {

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      BulkExportRoutesEvent::EVENT_NAME => 'onBulkExportRoutes',
    ];
  }

  /**
   * Adds taxonomy routes to the bulk export local action.
   *
   * @param \Drupal\single_content_sync\Event\BulkExportRoutesEvent $event
   *   The bulk export routes event.
   */
  public function onBulkExportRoutes(BulkExportRoutesEvent $event): void {
    $event->addRoute('entity.taxonomy_vocabulary.collection', 'taxonomy_term');
    $event->addRoute('entity.taxonomy_vocabulary.overview_form', 'taxonomy_term', NULL, 'taxonomy_vocabulary');
  }

}
