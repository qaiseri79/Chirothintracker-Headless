<?php

namespace Drupal\fillpdf;

/**
 * Handles exporting and importing FillPDF forms.
 *
 * @package Drupal\fillpdf
 */
interface SerializerInterface {

  /**
   * Serializes a FillPDF form for export.
   *
   * @param \Drupal\fillpdf\FillPdfFormInterface $fillpdf_form
   *   The FillPDF Form to serialize.
   *
   * @return string
   *   The serialized FillPDF form.
   */
  public function getFormExportCode(FillPdfFormInterface $fillpdf_form);

  /**
   * Deserializes a serialized FillPDF form for import.
   *
   * @param string $code
   *   The serialized FillPDF form.
   *
   * @return array
   *   Associative array containing the deserialized FillPDF form object keyed
   *   with 'form' and an array of deserialized FillPDF form objects keyed
   *   with 'fields'.
   */
  public function deserializeForm($code);

  /**
   * Imports a FillPDF form..
   *
   * @param \Drupal\fillpdf\FillPdfFormInterface $fillpdf_form
   *   The existing FillPDF form.
   * @param \Drupal\fillpdf\FillPdfFormInterface $imported_form
   *   The FillPDF form being imported, usually from import code.
   * @param \Drupal\fillpdf\FillPdfFormFieldInterface[] $imported_fields
   *   Array of FillPDF form objects to import.
   *
   * @return string[]
   *   Array of unmatched PDF keys.
   */
  public function importForm(FillPdfFormInterface $fillpdf_form, FillPdfFormInterface $imported_form, array $imported_fields);

  /**
   * Imports FillPDF form fields.
   *
   * Overwrites empty field values with previous existing field values.
   *
   * @param \Drupal\fillpdf\FillPdfFormFieldInterface[] $keyed_fields
   *   Associative array of unsaved FillPDF Form objects keyed by PDF key.
   * @param \Drupal\fillpdf\FillPdfFormFieldInterface[] $existing_fields
   *   (optional) Array of existing PDF keys.
   * @param bool $save_existing_fields
   *   Whether to save the form fields in $existing_fields after updating them.
   *   If you pass FALSE, you will have to save them yourself.
   *
   * @return string[]
   *   Array of unmatched PDF keys.
   */
  public function importFormFields(array $keyed_fields, array &$existing_fields = [], $save_existing_fields = TRUE): array;

}
