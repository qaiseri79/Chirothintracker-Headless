<?php

namespace Drupal\entity_bulk_clone\Plugin\Action;

use Drupal\Core\Action\ActionBase;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Entity Bulk clone of nodes.
 *
 * @Action(
 *   id = "bulk_clone_node_action",
 *   label = @Translation("Bulk Clone"),
 *   type = "node",
 *   confirm = TRUE
 * )
 */
class BulkCloneNode extends ActionBase {

  /**
   * {@inheritdoc}
   */
  public function executeMultiple(array $entities) {
    $operations = [];

    foreach ($entities as $entity) {
      $operations[] = [
        [static::class, 'processBatch'],
        [
          [
            'entity_type' => $entity->getEntityTypeId(),
            'entity_id' => $entity->id(),
          ],
        ],
      ];
    }

    if ($operations) {
      $batch = [
        'operations' => $operations,
        'finished' => [static::class, 'finishBatch'],
      ];
      batch_set($batch);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function execute(ContentEntityInterface $entity = NULL) {
    $this->executeMultiple([$entity]);
  }

  /**
   * Processes the batch item.
   *
   * @param array $data
   *   Keyed array of data to process.
   * @param array $context
   *   The batch context.
   */
  public static function processBatch(array $data, array &$context) {
    if (!isset($context['results']['processed'])) {
      $context['results']['processed'] = 0;
      $context['results']['theme'] = \Drupal::service('theme.manager')->getActiveTheme(\Drupal::routeMatch())->getName();
    }
    BulkCloneNode::replicateContent($data['entity_type'], $data['entity_id']);
    $context['results']['processed']++;
  }

  /**
   * Replicate Content.
   *
   * @param string $entity_type
   *   Entity type id.
   * @param int $entity_id
   *   Entity id.
   */
  public static function replicateContent($entity_type, int $entity_id) {
    $replicator = \Drupal::service('replicate.replicator');
    $clone_entity = $replicator->replicateByEntityId($entity_type, $entity_id);
    $title = $clone_entity->getTitle();
    $clone_entity->setTitle($title . ' - Bulk Cloned');
    $request_time = \Drupal::time()->getRequestTime();
    $clone_entity->setChangedTime($request_time);
    $clone_entity->save();
  }

  /**
   * Finish batch.
   *
   * @param bool $success
   *   Indicates whether the batch process was successful.
   * @param array $results
   *   Results information passed from the processing callback.
   */
  public static function finishBatch($success, array $results) {
    \Drupal::messenger()->addMessage(
      \Drupal::translation()->formatPlural($results['processed'], 'Bulk clone of the content has been processed.', '@count items have been processed.')
    );
    \Drupal::messenger()->addMessage($results['theme'] . ' theme used');
  }

  /**
   * {@inheritdoc}
   */
  public function access($object, AccountInterface $account = NULL, $return_as_object = FALSE) {
    return TRUE;
  }

}
