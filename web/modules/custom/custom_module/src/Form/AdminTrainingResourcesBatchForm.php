<?php

namespace Drupal\custom_module\Form;

use Drupal\Core\Batch\BatchBuilder;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Admin form to run a batch update for Training and Resources content.
 */
class AdminTrainingResourcesBatchForm extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'custom_module_admin_training_resources_batch_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['description'] = [
      '#type' => 'markup',
      '#markup' => $this->t('<p>Run a batch update that publishes administrator-created Training and Resources content to current subscriber clinics only.</p>'),
    ];

    $form['dry_run'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Dry run'),
      '#description' => $this->t('Do not save any changes. The batch will report what would be updated.'),
      '#default_value' => TRUE,
    ];

    $form['note'] = [
      '#type' => 'markup',
      '#markup' => $this->t('<p>This action affects only <strong>Training</strong> and <strong>Resources</strong> nodes created by administrator users, and will publish them only to selected subscriber clinics.</p>'),
    ];

    $form['actions'] = [
      '#type' => 'actions',
    ];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Run batch update'),
      '#button_type' => 'primary',
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $dry_run = (bool) $form_state->getValue('dry_run');
    $clinic_ids = $this->getSubscriberClinicIds();
    if (empty($clinic_ids)) {
      $this->messenger()->addError($this->t('No subscriber clinics were found.')); 
      return;
    }

    $node_ids = $this->getAdminTrainingResourceNodeIds();
    if (empty($node_ids)) {
      $this->messenger()->addError($this->t('No administrator-created Training or Resources nodes were found.')); 
      return;
    }

    $batchBuilder = (new BatchBuilder())
      ->setTitle($this->t('Updating Training and Resources for subscriber clinics'))
      ->setInitMessage($this->t('Starting the batch update.'))
      ->setProgressMessage($this->t('Processed @current out of @total nodes.'))
      ->setFinishCallback([self::class, 'finishBatch']);

    $chunk_size = 25;
    foreach (array_chunk($node_ids, $chunk_size) as $chunk) {
      $batchBuilder->addOperation([self::class, 'processNodeChunk'], [
        $chunk,
        $clinic_ids,
        $dry_run,
      ]);
    }

    batch_set($batchBuilder->toArray());
    $form_state->setRedirect('custom_module.admin_training_resources_batch_form');
  }

  /**
   * Batch operation callback.
   */
  public static function processNodeChunk(array $node_ids, array $clinic_ids, bool $dry_run, array &$context) {
    $node_storage = \Drupal::entityTypeManager()->getStorage('node');
    $nodes = $node_storage->loadMultiple($node_ids);

    if (!isset($context['results']['updated'])) {
      $context['results']['updated'] = 0;
      $context['results']['skipped'] = 0;
    }

    foreach ($nodes as $node) {
      if (!$node) {
        continue;
      }

      if (!$node->hasField('field_published_to')) {
        $context['results']['skipped']++;
        continue;
      }

      $target_ids = array_map(function ($clinic_id) {
        return ['target_id' => $clinic_id];
      }, $clinic_ids);

      $existing = array_map(function ($item) {
        return $item['target_id'];
      }, $node->get('field_published_to')->getValue());
      sort($existing);
      $new_ids = array_column($target_ids, 'target_id');
      sort($new_ids);

      if ($existing !== $new_ids) {
        $context['results']['updated']++;
        if (!$dry_run) {
          $node->set('field_published_to', $target_ids);
          $node->save();
        }
      }
      else {
        $context['results']['skipped']++;
      }
    }

    $context['message'] = t('Processed @count nodes in this operation.', ['@count' => count($nodes)]);
  }

  /**
   * Batch finish callback.
   */
  public static function finishBatch($success, $results, array $operations) {
    if ($success) {
      $updated = $results['updated'] ?? 0;
      $skipped = $results['skipped'] ?? 0;
      $message = $updated > 0
        ? t('Batch complete. @updated nodes updated, @skipped nodes skipped.', ['@updated' => $updated, '@skipped' => $skipped])
        : t('Batch complete. No nodes required changes.');
      \Drupal::messenger()->addStatus($message);
    }
    else {
      \Drupal::messenger()->addError(t('The batch did not complete successfully.'));
    }
  }

  /**
   * Get current subscriber clinic IDs.
   *
   * This targets clinics referenced by users with subscriber or chiropractor_active_ roles.
   */
  protected function getSubscriberClinicIds(): array {
    $user_query = \Drupal::entityQuery('user')
      ->accessCheck(FALSE)
      ->condition('status', 1)
      ->condition('roles', ['subscriber', 'chiropractor_active_'], 'IN')
      ->exists('field_clinic');
    $uids = $user_query->execute();
    if (empty($uids)) {
      return [];
    }

    $users = \Drupal::entityTypeManager()->getStorage('user')->loadMultiple($uids);
    $clinic_ids = [];
    foreach ($users as $user) {
      if ($user->hasField('field_clinic') && !$user->get('field_clinic')->isEmpty()) {
        $clinic_ids[] = $user->get('field_clinic')->target_id;
      }
    }

    return array_values(array_unique(array_filter($clinic_ids)));
  }

  /**
   * Get administrator-created Training and Resources node IDs.
   */
  protected function getAdminTrainingResourceNodeIds(): array {
    $admin_uids = \Drupal::entityQuery('user')
      ->accessCheck(FALSE)
      ->condition('roles', 'administrator')
      ->execute();

    if (empty($admin_uids)) {
      return [];
    }

    $query = \Drupal::entityQuery('node')
      ->accessCheck(FALSE)
      ->condition('type', ['chirothin_resource', 'training'], 'IN')
      ->condition('uid', $admin_uids, 'IN');

    return array_values($query->execute());
  }

}
