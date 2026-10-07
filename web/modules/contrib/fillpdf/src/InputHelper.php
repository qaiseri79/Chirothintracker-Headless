<?php

namespace Drupal\fillpdf;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\file\FileInterface;
use Drupal\fillpdf\Entity\FillPdfForm;
use Drupal\fillpdf\Entity\FillPdfFormField;
use Drupal\fillpdf\Plugin\PdfBackendManager;

/**
 * {@inheritdoc}
 *
 * @package Drupal\fillpdf
 */
class InputHelper implements InputHelperInterface {

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configManager;

  /**
   * The FillPDF backend manager.
   *
   * @var \Drupal\fillpdf\Plugin\PdfBackendManager
   */
  protected $backendManager;

  /**
   * The Entity Type Manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Constructs an InputHelper object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory service.
   * @param \Drupal\fillpdf\Plugin\PdfBackendManager $backend_manager
   *   The FillPDF backend manager.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The Entity type manager service.
   */
  public function __construct(ConfigFactoryInterface $config_factory, PdfBackendManager $backend_manager, EntityTypeManagerInterface $entity_type_manager) {
    $this->configManager = $config_factory;
    $this->backendManager = $backend_manager;
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public function attachPdfToForm(FileInterface $file, ?FillPdfFormInterface $existing_form = NULL) {
    // Save the file so we can get an fid.
    $file->setPermanent();
    $file->save();

    if ($existing_form) {
      $fillpdf_form = $existing_form;
      $fillpdf_form->file = $file;
    }
    else {
      $fillpdf_form = FillPdfForm::create([
        'file' => $file,
        'title' => $file->filename,
      ]);
    }

    // Save PDF configuration before parsing.
    $fillpdf_form->save();

    // Parse and save fields.
    $form_fields = $this->parseFields($fillpdf_form);
    foreach ($form_fields as $field) {
      $field->save();
    }

    return ['form' => $fillpdf_form, 'fields' => $form_fields];
  }

  /**
   * Parses fields of a FillPDF form.
   *
   * @param \Drupal\fillpdf\FillPdfFormInterface $fillpdf_form
   *   The FillPdfForm the PDF template file should be attached to.
   *
   * @return \Drupal\fillpdf\FillPdfFormFieldInterface[]
   *   Associative array of FillPdfFormField objects keyed by the PDF key.
   */
  public function parseFields(FillPdfFormInterface $fillpdf_form) {
    $config = $this->configManager->get('fillpdf.settings');
    /** @var \Drupal\fillpdf\Plugin\PdfBackendInterface $backend */
    $backend = $this->backendManager->createInstance($config->get('backend'), $config->get());

    // Attempt to parse the fields in the PDF.
    $parsed_fields = $backend->parseFile($this->entityTypeManager->getStorage('file')->load($fillpdf_form->file->target_id));

    $unique_fields = [];
    foreach ((array) $parsed_fields as $field) {
      // Don't store "container" fields.
      if ($field['type']) {
        // Use the field name as key, so to consolidate duplicate fields.
        $unique_fields[$field['name']] = TRUE;
      }
    }

    // Create a FillPdfFormField object for each unique field.
    $form_fields = [];
    foreach (array_keys($unique_fields) as $pdf_key) {
      $form_fields[$pdf_key] = FillPdfFormField::create([
        'fillpdf_form' => $fillpdf_form,
        'pdf_key' => $pdf_key,
        'value' => '',
      ]);
    }

    return $form_fields;
  }

}
