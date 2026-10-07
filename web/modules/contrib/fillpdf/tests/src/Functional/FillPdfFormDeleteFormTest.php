<?php

namespace Drupal\Tests\fillpdf\Functional;

use Drupal\Core\Url;
use Drupal\fillpdf\Entity\FillPdfForm;
use Drupal\fillpdf\Entity\FillPdfFormField;
use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\fillpdf\Traits\TestFillPdfTrait;

/**
 * @coversDefaultClass \Drupal\fillpdf\Form\FillPdfFormDeleteForm
 * @group fillpdf
 */
class FillPdfFormDeleteFormTest extends BrowserTestBase {

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
   * Tests the cancel link works.
   */
  public function testDeleteFormCancel() {
    $this->uploadTestPdf('fillpdf_test_v3.pdf');
    $fillpdf_form = FillPdfForm::load($this->getLatestFillPdfForm());

    // We're now on the edit form. Add an admin title.
    $this->assertSession()->pageTextContains('New FillPDF form has been created.');
    $admin_title = 'Test';
    $this->submitForm(['admin_title[0][value]' => $admin_title], 'Save');
    $this->assertSession()->pageTextContains("FillPDF Form $admin_title has been updated.");

    // Now click 'Delete' but come back clicking 'Cancel'.
    $this->clickLink('Delete');
    $this->assertSession()->pageTextContains("Are you sure you want to delete $admin_title?");
    $this->clickLink('Cancel');
    $this->assertSession()->addressEquals($fillpdf_form->toUrl('canonical'));

    // Go to the overview form and repeat it all to see how it works with a
    // destination added. There's only one FillPdfForm, so the first 'Delete'
    // button is the right one.
    $overview_url = Url::fromRoute('fillpdf.forms_admin');
    $this->drupalGet($overview_url);
    $this->clickLink('Delete');
    $this->assertSession()->pageTextContains("Are you sure you want to delete $admin_title?");
    $this->clickLink('Cancel');
    $this->assertSession()->addressEquals($overview_url);

    // Now take the detour via edit. The edit form removes the original
    // destination, so the cancelling user may come back.
    $this->drupalGet($overview_url);
    $this->clickLink('Edit');
    $this->clickLink('Delete');
    $this->assertSession()->pageTextContains("Are you sure you want to delete $admin_title?");
    $this->clickLink('Cancel');
    $this->assertSession()->addressEquals($fillpdf_form->toUrl('canonical'));
  }

  /**
   * Tests the cancel link works.
   */
  public function testDeleteForm() {
    $this->uploadTestPdf('fillpdf_test_v3.pdf');
    $form_id = $this->getLatestFillPdfForm();

    // Verify the FillPdfForm's fields are stored.
    $field_ids = \Drupal::entityQuery('fillpdf_form_field')->condition('fillpdf_form', $form_id)->accessCheck(TRUE)->execute();
    $this->assertCount(4, $field_ids, "4 FillPdfFormFields have been created.");

    // We're on the edit form. Click 'Delete' and confirm deletion.
    $this->clickLink('Delete');
    $this->submitForm([], 'Delete');
    $this->assertSession()->pageTextContains('FillPDF form deleted.');
    $this->assertSession()->addressEquals(Url::fromRoute('fillpdf.forms_admin'));

    // Now verify the FillPdfForm and its fields have actually been deleted.
    $this->assertNull(FillPdfForm::load($form_id), "The FillPdfForm #{$form_id} doesn't exist anymore.");
    foreach ($field_ids as $id) {
      $this->assertNull(FillPdfFormField::load($id), "The FillPdfFormField #{$id} doesn't exist anymore.");
    }
  }

}
