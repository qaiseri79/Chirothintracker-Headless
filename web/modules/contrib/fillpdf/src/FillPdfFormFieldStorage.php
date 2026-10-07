<?php

namespace Drupal\fillpdf;

use Drupal\Core\Entity\Sql\SqlContentEntityStorage;

/**
 * Defines a custom storage handler for FillPdfFormFields.
 *
 * The default storage is overridden to avoid having to delete FillPdfFormField
 * entities separately from their parent FillPdfForms.
 */
class FillPdfFormFieldStorage extends SqlContentEntityStorage {

  /**
   * {@inheritdoc}
   */
  public function hasData() {
    // Announce having data only if there are orphan FillPdfFormFields after
    // all FillPdfForms are deleted.
    return $this->entityTypeManager->getStorage('fillpdf_form')->hasData() ? FALSE : parent::hasData();
  }

}
