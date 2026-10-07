<?php

namespace Drupal\batch_with_ajax;

use Drupal\Core\Ajax\AjaxResponse;
use Drupal\node\Entity\Node;

/**
 * Noda Data class to process node data in batch.
 */
class NodeData {

  /**
   * Function to procecss the node data in batch.
   */
  public static function processNodeData($nids, &$context) {
    $message = 'Updating Node...';
        $storage = \Drupal::entityTypeManager()->getStorage('contact_message')->loadMultiple($nids);
        foreach ($storage as $msg) {
            $message_entity = \Drupal\contact\Entity\Message::load($msg->id());
            $owner_id = $message_entity->get('uid')->getValue()[0]['target_id'];
            $message_entity->set('field_author', $owner_id);
            $message_entity->save();
            $results[] = $msg->id();
        }
    $context['message'] = $message;
    $context['results'] = $results;
  }

  /**
   * Function that executes post batch processing.
   */
  public static function exportFinished($success, $results, $operations) {
    if ($success) {
      $message = \Drupal::translation()->formatPlural(
        count($results),
        'Data processing completed successfully.','Data processing completed successfully.'
      );
    }
    else {
      $message = t('Finished with an error.');
    }
    \Drupal::messenger()->addMessage($message);
    $ajaxResponse = new AjaxResponse();
    return $ajaxResponse;

  }

}
