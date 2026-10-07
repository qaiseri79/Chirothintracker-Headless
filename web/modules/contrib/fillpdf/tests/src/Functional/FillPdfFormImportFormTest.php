<?php

namespace Drupal\Tests\fillpdf\Functional;

use Drupal\Core\Url;
use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\fillpdf\Traits\TestFillPdfTrait;

/**
 * @coversDefaultClass \Drupal\fillpdf\Form\FillPdfFormImportForm
 * @group fillpdf
 */
class FillPdfFormImportFormTest extends BrowserTestBase {

  use TestFillPdfTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['fillpdf_test'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->configureFillPdf();
    $this->initializeUser();
  }

  /**
   * Tests export and import functions.
   */
  public function testExportDuplicateImport() {
    $this->uploadTestPdf('fillpdf_test_v3.pdf');
    $form1_id = $this->getLatestFillPdfForm();

    // We're now on the edit form. Add some configuration.
    $this->assertSession()->pageTextContains('New FillPDF form has been created.');
    $edit = [
      'admin_title[0][value]' => 'Test',
      'replacements[0][value]' => 'y|Yes',
    ];
    $this->submitForm($edit, 'Save');
    $this->assertSession()->pageTextContains("FillPDF Form Test has been updated.");

    // Export.
    $this->drupalGet(Url::fromRoute('entity.fillpdf_form.export_form', ['fillpdf_form' => $form1_id]));
    $code = $this->assertSession()->fieldExists('export')->getValue();

    // Duplicate the form.
    $duplicate_url = Url::fromRoute('entity.fillpdf_form.duplicate_form', ['fillpdf_form' => $form1_id]);
    $this->drupalGet($duplicate_url);
    $this->submitForm([], 'Save');
    $form2_id = $this->getLatestFillPdfForm();

    // Change some configuration.
    // @todo Even without a destination set, we should always return somewhere
    // instead of remaining on the duplicate confirm page.
    $edit = [
      'replacements[0][value]' => 'n|No',
    ];
    $this->drupalGet(Url::fromRoute('entity.fillpdf_form.edit_form', ['fillpdf_form' => $form2_id]));
    $this->submitForm($edit, 'Save');
    $this->assertSession()->pageTextContains("FillPDF Form Duplicate of Test has been updated.");

    // Import again.
    $import_url = Url::fromRoute('entity.fillpdf_form.import_form', ['fillpdf_form' => $form2_id]);
    $this->drupalGet($import_url);
    $this->submitForm(['code' => $code], 'Import');
    // Check none of the mappings failed.
    $this->assertSession()->pageTextContains('Successfully imported FillPDF form configuration and matching PDF field keys.');
    $this->assertSession()->pageTextNotContains('but it does not exist on this form. Therefore, it was ignored.');
    // Check the admin_title has been imported as well.
    $this->assertSession()->fieldValueEquals('admin_title[0][value]', 'Test');
    $this->assertSession()->fieldValueEquals('replacements[0][value]', 'y|Yes');
  }

}
