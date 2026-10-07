<?php

/**
 * @file
 * Hooks related to FillPDF module.
 */

use Drupal\fillpdf\Form\FillPdfFormForm;

/**
 * @addtogroup hooks
 * @{
 */

/**
 * Alter a FillPdfFormForm prior to building its edit form.
 *
 * This is triggered in FillPdfFormForm::form().
 *
 * @param Drupal\fillpdf\Form\FillPdfFormForm $fillpdf_form_form
 *   The FillPdfFormForm object to alter.
 */
function hook_fillpdf_form_form_pre_form_build_alter(FillPdfFormForm $fillpdf_form_form): void {
  // Set the default entity type for any FillPDF form to 'webform'.
  $fillpdf_form = $fillpdf_form_form->getEntity();
  $default_entity_type = $fillpdf_form->getDefaultEntityType();
  if (!$default_entity_type) {
    $fillpdf_form->set('default_entity_type', 'webform')->save();
  }
}

/**
 * Alter the $context in HandlePdfController::populatePdf().
 *
 * @param array $context
 *   The context array with keys 'fid', 'force_download', 'flatten', 'sample',
 *   and 'entity_ids'.
 */
function hook_fillpdf_populate_pdf_context_alter(array &$context): void {
  // If there are no webform_submission entities but there is at least one
  // webform entity, add the most recent submission for each webform.
  // Only do this for authenticated users and when webform_submission storage
  // exists.
  $current_uid = (int) \Drupal::currentUser()->id();
  if ($current_uid && empty($context['entity_ids']['webform_submission']) && !empty($context['entity_ids']['webform']) && \Drupal::entityTypeManager()->hasDefinition('webform_submission')) {
    $webform_submission_storage = \Drupal::entityTypeManager()->getStorage('webform_submission');
    foreach ($context['entity_ids']['webform'] as $webform_id) {
      // Load submission IDs from webform_submission storage.
      $query = $webform_submission_storage->getQuery()->accessCheck(TRUE)->condition('webform_id', $webform_id);
      $query->condition('uid', $uid);
      $query->condition('in_draft', 0);
      $query->sort('created', 'ASC');
      $entity_id = $query->execute();
      // If there is at least one, return the last as integer, otherwise NULL.
      $entity_id = $entity_id ? (int) end($entity_id) : NULL;
      if ($entity_id) {
        $context['entity_ids']['webform_submission'][$entity_id] = $entity_id;
      }
    }
  }
}

/**
 * @} End of "addtogroup hooks".
 */
