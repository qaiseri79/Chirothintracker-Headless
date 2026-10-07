<?php

namespace Drupal\fillpdf\Service;

use Drupal\fillpdf\FillPdfFormInterface;

/**
 * The backend proxy allows backend-agnostic PDF operations.
 *
 * It uses the backend configured on the site to operate on the PDF. Currently,
 * only merging is supported. Other modules can use this to integrate with
 * FillPDF more simply and without having to make sub-requests.
 */
interface BackendProxyInterface {

  /**
   * Merge data into a PDF using the supplied form configuration and entities.
   *
   * @param \Drupal\fillpdf\FillPdfFormInterface $fillPdfForm
   *   The form configuration to use. Will be processed the same way as the
   *   fillpdf.populate_pdf route, including replacements, token mappings, etc.
   * @param \Drupal\Core\Entity\EntityInterface[][] $entities
   *   The entity data to use. The entities should be keyed by entity type.
   *   Under each key, there should be an array of entities keyed by their IDs.
   * @param array $mergeOptions
   *   Configure how the merge should work. Valid keys are:
   *     - sample: (boolean, default: FALSE) whether to output a sample PDF
   *     - flatten: (boolean, default: TRUE) whether the merged PDF should have
   *       its fields made permanent and no longer editable.
   *   It is safe to pass in a FillPDF $context array. Merge options are a
   *   subset of that.
   *
   * @return string
   *   The merged PDF data.
   */
  public function merge(FillPdfFormInterface $fillPdfForm, array $entities, array $mergeOptions = []): string;

}
