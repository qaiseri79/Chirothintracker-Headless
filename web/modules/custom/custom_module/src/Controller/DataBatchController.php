<?php

namespace Drupal\custom_module\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Batch\BatchBuilder;
use ZipArchive;
use Drupal\node\Entity\Node;

/**
 * Controller for the image batch export process.
 */
class DataBatchController extends ControllerBase {


  public function startBatch() {
    $batch_builder = (new BatchBuilder())
      ->setTitle($this->t('Exporting images to ZIP'))
      ->setInitMessage($this->t('Initializing batch process...'))
      ->setProgressMessage($this->t('Processing @current out of @total'))
      ->setErrorMessage($this->t('An error occurred during the batch processing.'));
    $state = \Drupal::request()->query->get('state');
    if ($state) {
      $nids = \Drupal::entityQuery('node')
          ->accessCheck(TRUE)
          ->condition('type', 'entries')
          ->condition('field_state', $state)
          ->execute();
        }else{
            $nids = \Drupal::entityQuery('node')
                ->accessCheck(TRUE)
                ->condition('type', 'entries')
                ->execute();
        }
    // $nids = \Drupal::entityQuery('node')
    //   ->accessCheck(TRUE)
    //   ->condition('type', 'entries') 
    //   ->execute();
    $nids = array_chunk($nids, 500);
    foreach ($nids as $nid_chunk) {
      $batch_builder->addOperation([$this, 'processNode'], [$nid_chunk]);
    }
    $batch_builder->setFinishCallback([$this, 'finishBatch']);
    batch_set($batch_builder->toArray());
    return batch_process('/admin/content/export/data');
  }

  /**
   * Processes each node to add its images to the ZIP archive.
   *
   * @param int $nids
   *   The node ID to process.
   * @param array $context
   *   The batch context array.
   */
  public function processNode($nids, &$context) {
    if (!isset($context['sandbox']['zip'])) {
      $zip_path = 'public://tmp/images_batch_export_' . time() . '.zip';
      $context['sandbox']['zip'] = new ZipArchive();
      $real_zip_path = \Drupal::service('file_system')->realpath($zip_path);
      if ($context['sandbox']['zip']->open($real_zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== TRUE) {
        throw new \Exception('Could not create ZIP file.');
      }
      $context['results']['zip_paths'][] = $zip_path;
    }

    foreach ($nids as $nid) {
      $node = Node::load($nid);
      if ($node && $node->hasField('field_field_media_image') && !$node->get('field_field_media_image')->isEmpty()) {
        $this->addImageToZip($node, 'field_field_media_image', $context);
      }
      if ($node && $node->hasField('field_field_original_image') && !$node->get('field_field_original_image')->isEmpty()) {
        $this->addImageToZip($node, 'field_field_original_image', $context);
      }
    }
    $context['sandbox']['progress'] += count($nids);
    $context['sandbox']['total'] = count(\Drupal::entityQuery('node')->accessCheck(TRUE)->condition('type', 'entries')->execute());
    $context['finished'] = $context['sandbox']['progress'] / $context['sandbox']['total'];
  }

  /**
   * Adds an image file from a field to the ZIP archive.
   *
   * @param \Drupal\node\Entity\Node $node
   *   The node entity.
   * @param string $field_name
   *   The name of the field containing the image.
   * @param array $context
   *   The batch context array.
   */
  protected function addImageToZip($node, $field_name, &$context) {
    $image_field = $node->get($field_name)->getValue()[0];
    if (!is_null($image_field)) {
      $file_id = $image_field['target_id'];
      $file = \Drupal\file\Entity\File::load($file_id);
      if ($file) {
        $file_realpath = \Drupal::service('file_system')->realpath($file->getFileUri());
        $file_name = basename($file_realpath);
        if (file_exists($file_realpath)) {
          \Drupal::logger('impact_module')->info('Adding file to ZIP: @path', ['@path' => $file_realpath]);
          $context['sandbox']['zip']->addFile($file_realpath, $file_name);
        }
      }
    }
  }

  /**
   * Completes the batch process and closes the ZIP file.
   *
   * @param bool $success
   *   Indicates whether the batch was successful.
   * @param array $results
   *   The results of the batch processing.
   * @param array $operations
   *   The operations that were performed.
   */
public function finishBatch($success, $results, $operations) {
  if ($success) {
    if (!empty($results['zip_paths'])) {
      $messages = [];
      foreach ($results['zip_paths'] as $zip_path) {
        $real_zip_path = \Drupal::service('file_system')->realpath($zip_path);
        $zip = new ZipArchive();
        if ($zip->open($real_zip_path) === TRUE) {
          $zip->close();
        }

        $file_url = \Drupal::service('file_url_generator')->generateAbsoluteString($zip_path);
        $messages[] = $this->t('<a href=":link">Download ZIP: @file</a>', [
          ':link' => $file_url,
          '@file' => basename($zip_path),
        ]);
      }

      foreach ($messages as $message) {
        \Drupal::messenger()->addMessage($message);
      }
    } else {
      \Drupal::messenger()->addError($this->t('No ZIP files were created.'));
    }
  } else {
    \Drupal::messenger()->addError($this->t('Batch process encountered an error.'));
  }
}

}
