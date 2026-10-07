<?php

namespace Drupal\batch_with_ajax\Form;

use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\SettingsCommand;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides Form to select the fields to be included in batch.
 */
class UpdateNodeForm extends FormBase {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The form constructor.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager service.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager) {
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'Update_node_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    // Disable form state cache to fix cache issue.
    $form_state->disableCache();
    $form['actions'] = [
      '#type' => 'container',
      '#prefix' => '<div class="node-update-actions">',
      '#suffix' => '</div>',
    ];

    // progress display while batch run by ajax.
    $form['actions']['process_data'] = [
      '#type' => 'submit',
      '#value' => $this->t('Refresh Data'),
     // '#prefix' => '<div><i class="fa-solid fa-rotate"></i>',
      '#suffix' => '<span id="updateprogress" class="refresh-progress"></span></div>',
      '#ajax' => [
        'callback' => [$this, 'nodeUpdateBatchCallback'],
        '#value' => $this->t('Submit'),
        'wrapper' => 'node-update-actions-wrap',
        'effect' => 'fade',
      ],
    ];

    $form['#attached']['library'][] = 'batch_with_ajax/node_update';
    return $form;
  }

  /**
   * AJAX callback handler that pass form values to js.
   */
  public function nodeUpdateBatchCallback($form, FormStateInterface &$form_state) {
    // Return the response to the ajax output.
    $uid = \Drupal::currentUser()->id();
    $ajaxResponse = new AjaxResponse();
    $settings = [
      'user_submit_val' => [
        'data' => $uid,
      ],
    ];
    $merge = TRUE;

    // Pass form submitted values to js context.
    return $ajaxResponse->addCommand(new SettingsCommand($settings, $merge));
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {

  }

}
