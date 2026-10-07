<?php

namespace Drupal\fillpdf;

use Drupal\file\FileInterface;

/**
 * Helper methods for PDF uploads.
 *
 * @package Drupal\fillpdf
 */
interface InputHelperInterface {

  /**
   * Attaches a PDF template file to a FillPdfForm.
   *
   * @param \Drupal\file\FileInterface $file
   *   The PDF template file to attach.
   * @param \Drupal\fillpdf\FillPdfFormInterface $existing_form
   *   The FillPdfForm the PDF template file should be attached to.
   *
   * @return array
   *   Associative array with the following keys:
   *   - 'form': The updated FillPdfForm entity.
   *   - 'fields': Associative array of the FillPdfForm entity's saved
   *     FillPdfFormFields.
   */
  public function attachPdfToForm(FileInterface $file, ?FillPdfFormInterface $existing_form = NULL);

}
