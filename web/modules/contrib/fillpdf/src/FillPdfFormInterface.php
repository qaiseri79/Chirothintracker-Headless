<?php

namespace Drupal\fillpdf;

/**
 * Represents a fillable PDF form.
 *
 * @package Drupal\fillpdf
 */
interface FillPdfFormInterface extends ExportableContentEntityInterface {

  /**
   * Returns the default entity type ID for this FillPdfForm.
   *
   * @return string|null
   *   The entity type ID or NULL if there is none set.
   */
  public function getDefaultEntityType(): ?string;

  /**
   * Returns all FillPdfFormFields associated with this FillPdfForm.
   *
   * @return \Drupal\fillpdf\FillPdfFormFieldInterface[]
   *   Associative array of FillPdfFormFields keyed by the pdf_key.
   */
  public function getFormFields();

  /**
   * Gets this FillPdfForm's storage scheme.
   *
   * @return string
   *   The storage scheme to be used for PDF files generated from this
   *   FillPdfForm.
   */
  public function getStorageScheme();

  /**
   * Gets this FillPdfForm's storage path.
   *
   * @return string
   *   The storage path to be used for PDF files generated from this
   *   FillPdfForm.
   */
  public function getStoragePath();

}
