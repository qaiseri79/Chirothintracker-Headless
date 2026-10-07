<?php

namespace Drupal\Tests\fillpdf\Functional;

use Drupal\Core\Url;
use Drupal\file\Entity\File;
use Drupal\fillpdf\Entity\FillPdfForm;
use Drupal\user\Entity\Role;

/**
 * @coversDefaultClass \Drupal\fillpdf\Form\FillPdfFormForm
 * @group fillpdf
 */
class FillPdfFormFormTest extends FillPdfUploadTestBase {

  /**
   * The test node.
   *
   * @var \Drupal\node\NodeInterface
   */
  protected $testNode;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->testNode = $this->createNode([
      'title' => 'Hello',
      'type' => 'article',
    ]);
  }

  /**
   * Tests the FillPdfForm entity's edit form.
   */
  public function testDefaultEntityId() {
    $this->uploadTestPdf('fillpdf_test_v3.pdf');

    // Default entity type is not yet given, so the ID element should be hidden.
    $this->assertSession()->hiddenFieldExists('default_entity_id');

    // Create 25 more users to check the threshold.
    for ($i = 0; $i < 25; $i++) {
      $this->createUser();
    }

    $test_cases = [
      // Test case: no referenceable entity.
      'fillpdf_file_context' => [0, 0, ''],
      // Test case: 1 referenceable entity.
      'node' => [1, $this->testNode->id(), $this->testNode->label()],
      // Test case: 26 referenceable entities.
      'user' => [26, $this->adminUser->id(), $this->adminUser->label()],
    ];

    foreach ($test_cases as $type => [$count, $id, $label]) {
      // Set a default entity type and check if it's properly saved.
      $this->submitForm(['default_entity_type' => $type], self::OP_SAVE);
      $this->assertSession()->pageTextContains("FillPDF Form has been updated.");
      $this->assertSession()->fieldValueEquals('default_entity_type', $type);

      // Check the default entity ID field is present but empty.
      $this->assertSession()->fieldValueEquals('default_entity_id', '');

      if ($count == 0) {
        $options = $this->assertSession()->selectExists('default_entity_id')->findAll('xpath', 'option');
        $this->assertCount(1, $options);
        $this->assertEquals('', $options[0]->getValue());
        $this->assertEquals('- None -', $options[0]->getText());
        // Skip the rest and continue with the next test case.
        continue;
      }
      elseif ($count <= 25) {
        $this->assertSession()->pageTextContains("Choose a $type to test populating the PDF template.");
        // Now enter an entity title.
        $this->assertSession()->optionExists('default_entity_id', $id);
        $this->submitForm(['default_entity_id' => $id], self::OP_SAVE);
        $expected_value = $id;
      }
      else {
        $this->assertSession()->pageTextContains("Enter the title of a $type to test populating the PDF template.");
        // Now choose an entity and check the entity type is unchanged.
        $this->submitForm(
          ['default_entity_id' => $label], self::OP_SAVE);
        $expected_value = "$label ($id)";
      }

      // Check entity type, entity ID and description.
      $this->assertSession()->pageTextContains("FillPDF Form has been updated.");
      $this->assertSession()->fieldValueEquals('default_entity_type', $type);
      $this->assertSession()->fieldValueEquals('default_entity_id', $expected_value);
      $this->assertSession()->linkExistsExact("Download this PDF template populated with data from the $type $label ($id).");
    }
  }

  /**
   * Test uploading an updated PDF.
   *
   * It should also test for "These keys couldn't be found in the new PDF:" and
   * the list of missing keys, when the keys are different. However, in testing,
   * it does not actually parse the PDF, so the keys are never different. A fake
   * list of fields is in TestPdfBackend::getParseResult();
   */
  public function testFormFormUploadUpdate() {
    // Upload the initial PDF.
    $this->drupalGet('admin/structure/fillpdf');
    $this->assertSession()->statusCodeEquals(200);
    $edit = [
      'files[upload_pdf]' => $this->getTestPdfPath('fillpdf_test_v3.pdf'),
    ];
    $this->submitForm($edit, self::OP_CREATE);
    $this->assertSession()->statusCodeEquals(200);

    // Upload the updated PDF.
    $edit = [
      'files[upload_pdf]' => $this->getTestPdfPath('fillpdf_test_v3.pdf'),
    ];
    $this->submitForm($edit, self::OP_SAVE);
    $this->assertSession()->statusCodeEquals(200);

    // Status messages.
    $messages = [
      'FillPDF Form has been updated.',
      'Your previous field mappings have been transferred to the new PDF template you uploaded.',
      'You might also want to update the Filename pattern field; this has not been changed.',
    ];
    foreach ($messages as $message) {
      $this->assertSession()->elementExists('xpath', '//div[@aria-label="Status message"]/ul/li[contains(normalize-space(), "' . $message . '")]');
    }
  }

  /**
   * Tests the FillPdfForm entity's edit form.
   */
  public function testFormFormUpload() {
    $this->uploadTestPdf('fillpdf_test_v3.pdf');

    $latest_fid = $this->getLatestFillPdfForm();
    $latest_fillpdf_form = FillPdfForm::load($latest_fid);
    $max_fid_after = $latest_fillpdf_form->fid->value;
    $this->drupalGet('admin/structure/fillpdf/' . $max_fid_after);
    $this->assertSession()->statusCodeEquals(200);

    // Check if the 'accept' attribute is correctly set.
    $this->assertSession()->elementAttributeContains('css', 'input#edit-upload-pdf-upload', 'accept', 'application/pdf');

    // Run all upload tests.
    $this->assertNotUploadTextFile(self::OP_UPLOAD);
    $this->assertNotUploadTextFile(self::OP_SAVE);
    $this->assertUploadPdfFile(self::OP_UPLOAD, TRUE);
    $pdf_fields = $latest_fillpdf_form->getFormFields();
    FillPdfTestBase::mapFillPdfFieldsToEntityFields('node', $pdf_fields);
    $this->assertUploadPdfFile(self::OP_SAVE, TRUE, $latest_fillpdf_form);
  }

  /**
   * Tests the FillPdfForm entity's edit form.
   */
  public function testStorageSettings() {
    $this->uploadTestPdf('fillpdf_test_v3.pdf');
    $form_id = $this->getLatestFillPdfForm();
    $previous_file_id = $this->getLastFileId();

    $edit_form_url = Url::fromRoute('entity.fillpdf_form.edit_form', ['fillpdf_form' => $form_id]);
    $generate_url = Url::fromRoute('fillpdf.populate_pdf', [], [
      'query' => [
        'fid' => $form_id,
        'entity_id' => "node:{$this->testNode->id()}",
      ],
    ]);

    // Check the initial storage settings.
    $this->assertSession()->fieldValueEquals('scheme', '_none');
    foreach (['- None -', 'private://', 'public://'] as $option) {
      $this->assertSession()->optionExists('scheme', $option);
    }
    $this->assertSession()->fieldValueEquals('destination_path[0][value]', '');
    $this->drupalGet($edit_form_url);

    // Now hit the generation route and make sure the PDF file is *not* stored.
    $this->drupalGet($generate_url);
    $this->assertEquals($previous_file_id, $this->getLastFileId(), 'Generated file is not stored.');

    // Set the 'public' scheme and see if the 'destination_path' field appears.
    $this->drupalGet($edit_form_url);
    $this->submitForm(['scheme' => 'public'], self::OP_SAVE);
    $this->assertSession()->fieldValueEquals('scheme', 'public');
    $this->assertSession()->pageTextContains('Destination path');

    // Hit the generation route again and make sure this time the PDF file is
    // stored in the public storage.
    $this->drupalGet($generate_url);
    $this->assertEquals(++$previous_file_id, $this->getLastFileId(), 'Generated file was stored.');
    $this->assertStringStartsWith('public://', File::load($this->getLastFileId())->getFileUri());

    // Now disallow the public scheme and reload.
    $this->configureFillPdf(['allowed_schemes' => ['private']]);

    // Reload and check if the public option has disappeared now.
    $this->drupalGet($edit_form_url);
    $this->assertSession()->fieldValueEquals('scheme', '_none');
    foreach (['- None -', 'private://'] as $option) {
      $this->assertSession()->optionExists('scheme', $option);
    }
    $this->assertSession()->optionNotExists('scheme', 'public://');

    // Hit the generation route once more and make sure the scheme has been
    // unset, so the PDF file is *not* stored.
    $this->drupalGet($generate_url);
    $this->assertEquals($previous_file_id, $this->getLastFileId(), 'Generated file is not stored.');

    // Set the 'private' scheme.
    $this->drupalGet($edit_form_url);
    $this->submitForm(['scheme' => 'private'], self::OP_SAVE);
    $this->assertSession()->fieldValueEquals('scheme', 'private');

    // Hit the generation route again and make sure this time the PDF file is
    // stored in the private storage.
    $this->drupalGet($generate_url);
    $this->assertEquals(++$previous_file_id, $this->getLastFileId(), 'Generated file was stored.');
    $this->assertStringStartsWith('private://', File::load($this->getLastFileId())->getFileUri());

    // Now disallow the private scheme as well and reload.
    $this->configureFillPdf(['allowed_schemes' => []]);
    $this->drupalGet($edit_form_url);

    // Check if the whole storage settings section has disappeared now.
    $this->assertSession()->pageTextNotContains('Storage and download');

    // Hit the generation route one last time and make sure the PDF file is
    // again *not* stored.
    $this->drupalGet($generate_url);
    $this->assertEquals($previous_file_id, $this->getLastFileId(), 'Generated file is not stored.');
  }

  /**
   * Tests proper registration of managed_files.
   */
  public function testFillPdfFileUsage() {
    $this->uploadTestPdf('fillpdf_test_v3.pdf');

    // Set the administrative title and check if it has been successfully set.
    $admin_title = 'Example form';
    $this->submitForm(['admin_title[0][value]' => $admin_title], self::OP_SAVE);
    $this->assertSession()->pageTextContains("FillPDF Form $admin_title has been updated.");
    $this->assertSession()->fieldValueEquals('edit-admin-title-0-value', $admin_title);

    // Grant additional permission to the logged in user.
    $existing_user_roles = $this->loggedInUser->getRoles(TRUE);
    $role_to_modify = Role::load(end($existing_user_roles));
    $this->grantPermissions($role_to_modify, ['access files overview']);

    // Check if the uploaded test PDF file is properly registered as a permanent
    // managed_file.
    $fillpdf_form = FillPdfForm::load($this->getLatestFillPdfForm());
    $file_id = $fillpdf_form->get('file')->first()->getValue()['target_id'];
    $this->drupalGet('admin/content/files');
    $this->submitForm(['edit-filename' => 'fillpdf_test_v3.pdf'], 'Filter');
    $this->assertSession()->elementsCount('css', 'table td.views-field.views-field-filename', 1);
    $this->assertSession()->pageTextContains('Permanent');
    $file_url = File::load($file_id)->createFileUrl();
    $this->assertSession()->linkByHrefExists($file_url);

    // Now go check the File usage screen and see if the FillPdfForm is listed
    // with its canonical link.
    $this->drupalGet("admin/content/files/usage/$file_id");
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->linkByHrefExists($fillpdf_form->toUrl()->toString());
  }

}
