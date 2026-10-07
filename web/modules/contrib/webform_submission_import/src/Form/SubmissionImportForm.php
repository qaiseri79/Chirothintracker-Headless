<?php

namespace Drupal\webform_submission_import\Form;

use Drupal\webform\Utility\WebformElementHelper;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\webform\WebformRequestInterface;
use Drupal\webform\WebformSubmissionForm;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Component\Utility\Environment;

/**
 * Webform Submission Import Form Class.
 */
class SubmissionImportForm extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'webform_submission_import';
  }

  /**
   * The webform entity.
   *
   * @var \Drupal\webform\WebformInterface
   */
  protected $webform;

  /**
   * The webform source entity.
   *
   * @var \Drupal\Core\Entity\EntityInterface
   */
  protected $sourceEntity;

  /**
   * The webform submission storage.
   *
   * @var \Drupal\webform\WebformSubmissionStorageInterface
   */
  protected $submissionStorage;

  /**
   * The webform request handler.
   *
   * @var \Drupal\webform\WebformRequestInterface
   */
  protected $requestHandler;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Constructs a WebformResultsCustomForm object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\webform\WebformRequestInterface $request_handler
   *   The webform request handler.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, WebformRequestInterface $request_handler) {
    $this->submissionStorage = $entity_type_manager->getStorage('webform_submission');
    $this->requestHandler = $request_handler;
    [$this->webform, $this->sourceEntity] = $this->requestHandler->getWebformEntities();
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('webform.request')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form = [
      '#attributes' => ['enctype' => 'multipart/form-data'],
    ];

    $form['file_upload_details'] = [
      '#markup' => '<p>' . $this->t('Upload a CSV file. First row should contain headers matching field names from the form. Extra columns will be ignored.') . '</p>',
    ];

    $elements = $this->webform->getElementsDecoded();
    $elementsFlattened = WebformElementHelper::getFlattened($elements);
    $fields = array_filter($elementsFlattened, function ($e) {
      return $e['#type'] != 'webform_wizard_page';
    });
    $requiredFields = array_filter($fields, function ($e) {
      return isset($e['#required']) && $e['#required'] == 1;
    });

    $form['help_required_fields'] = [
      '#markup' => '<strong>Required Fields:</strong> ' . implode(', ', array_keys($requiredFields)),
    ];
    $form['help_additional_fields'] = [
      '#markup' => '<br /><strong>Additional Fields:</strong> ' . implode(', ', array_keys(array_diff_key($fields, $requiredFields))),
    ];

    $validators = [
      'file_validate_extensions' => ['csv'],
    ];
    $form['sub_file'] = [
      '#type' => 'managed_file',
      '#name' => 'sub_file',
      '#title' => $this->t('Submissions File'),
      '#size' => 20,
      '#description' => $this->t('CSV format only'),
      '#upload_validators' => $validators,
      '#upload_location' => 'public://webform_submission_imports/',
    ];

    $form['actions']['#type'] = 'actions';
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Import'),
      '#button_type' => 'primary',
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    if ($form_state->getValue('sub_file') == NULL) {
      $form_state->setErrorByName('sub_file', $this->t('File.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $file = $this->entityTypeManager->getStorage('file')->load($form_state->getValue('sub_file')[0]);
    $fileuri = $file->get('uri')->value;
    $handle = fopen($fileuri, 'r');
    $fields = [];
    $importCount = 0;

    // @todo This doesn't seem right.
    ini_set('memory_limit', '8192M');
    Environment::setTimeLimit(1800);

    while (($rowData = fgetcsv($handle)) !== FALSE) {
      if (empty($fields)) {
        $fields = $rowData;
        continue;
      }
      $fieldData = array_combine($fields, $rowData);
      if ($fieldData !== FALSE) {
        $import = $this->importRow(array_combine($fields, $rowData));
        if ($import) {
          $importCount++;
        }
      }
    }

    fclose($handle);
    Environment::setTimeLimit(120);

    $this->messenger()
      ->addMessage(t('Successfully imported %importCount submission(s).', ['%importCount' => $importCount]));
  }

  /**
   * Import row.
   *
   * @param array $rowData
   *   The row data.
   *
   * @return bool|void
   *   Returns status if row was imported or not.
   */
  private function importRow(array $rowData = []) {
    $values = [
      'webform_id' => $this->webform->id(),
      'current_page' => 'webform_submission_import',
      'data' => $rowData,
    ];

    if (WebformSubmissionForm::isOpen($this->webform) === TRUE) {
      $errors = WebformSubmissionForm::validateFormValues($values);
      if (!empty($errors)) {
        $this->getLogger('webform_submission_import')->error('Error importing record ::: <br /><pre>' . print_r($values, TRUE) . '</pre><hr /><pre>' . print_r($errors, TRUE) . '</pre>');
        return FALSE;
      }
      else {
        $webform_submission = WebformSubmissionForm::submitFormValues($values);
        if (is_numeric($webform_submission->id()) && $webform_submission->id() > 0) {
          return TRUE;
        }
      }
    }
  }

}
