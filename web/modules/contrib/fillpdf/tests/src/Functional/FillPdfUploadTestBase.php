<?php

namespace Drupal\Tests\fillpdf\Functional;

use Drupal\Component\Render\FormattableMarkup;
use Drupal\file\Entity\File;
use Drupal\fillpdf\Component\Utility\FillPdf;
use Drupal\fillpdf\FillPdfFormInterface;

/**
 * Allows testing everything around uploading PDF template files.
 *
 * @group fillpdf
 */
abstract class FillPdfUploadTestBase extends FillPdfTestBase {

  /**
   * Upload a file in the managed file widget.
   *
   * @var string
   */
  const OP_UPLOAD = 'Upload';

  /**
   * Remove a file from the managed file widget.
   *
   * @var string
   */
  const OP_REMOVE = 'Remove';

  /**
   * Create a new FillPdfForm. Submit button on FillPdfOverviewForm.
   *
   * @var string
   */
  const OP_CREATE = 'Create';

  /**
   * Save and update the FillPdfForm. Submit button on FillPdfFormForm.
   *
   * @var string
   */
  const OP_SAVE = 'Save';

  /**
   * Asserts that a text file may not be uploaded.
   *
   * @param string $op
   *   (optional) Operation to perform. May be either of:
   *   - ::OP_UPLOAD (default),
   *   - ::OP_CREATE, or
   *   - ::OP_SAVE.
   */
  protected function assertNotUploadTextFile($op = self::OP_UPLOAD) {
    $previous_file_id = $this->getLastFileId();

    // Try uploading a text file in the managed file widget.
    $edit = ['files[upload_pdf]' => $this->getTestFile('text')->getFileUri()];
    $this->submitForm($edit, $op);

    // Whether submitted or just uploaded, the validation should set an error
    // and the file shouldn't end up being uploaded.
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Only files with the following extensions are allowed: pdf.');
    $this->assertEquals($previous_file_id, $this->getLastFileId());

    // Make sure FillPdf Forms were not affected.
    $this->assertSession()->pageTextNotContains('New FillPDF form has been created.');
    $this->assertSession()->pageTextNotContains('Your previous field mappings have been transferred to the new PDF template you uploaded.');

  }

  /**
   * Asserts that a PDF file may be properly uploaded as a template.
   *
   * @param string $op
   *   (optional) Operation to perform. May be either of:
   *   - FillPdfUploadTestBase::OP_UPLOAD (default),
   *   - FillPdfUploadTestBase::OP_CREATE, or
   *   - FillPdfUploadTestBase::OP_SAVE.
   * @param bool $filename_preexists
   *   (optional) Whether the test file has previously been uploaded, so a file
   *   with the same filename preexists. Defaults to FALSE.
   * @param \Drupal\fillpdf\FillPdfFormInterface $form
   *   The FillPDF Form that is being updated. Needed so we can make some
   *   assertions against the fields.
   *
   * @throws \Behat\Mink\Exception\ExpectationException
   * @throws \Behat\Mink\Exception\ResponseTextException
   */
  protected function assertUploadPdfFile($op = self::OP_UPLOAD, $filename_preexists = FALSE, ?FillPdfFormInterface $form = NULL) {
    $previous_file_id = $this->getLastFileId();

    $existing_mappings = [];
    if ($op === self::OP_SAVE) {
      // Record the mappings in the FillPdfFormFields before overwriting the
      // file. We may need to compare them later.
      $existing_fields = $form->getFormFields();
      foreach ($existing_fields as $existing_field) {
        $existing_mappings[$existing_field->pdf_key->value] = $existing_field->value->value;
      }
    }

    // Upload PDF test file.
    $edit = ['files[upload_pdf]' => $this->getTestPdfPath('fillpdf_test_v3.pdf')];
    $this->submitForm($edit, $op);

    // Whether submitted or just uploaded, at least temporarily the file should
    // exist now both as an object and physically on the disk.
    /** @var \Drupal\file\FileInterface $new_file */
    $new_file = File::load($this->getLastFileId());
    $new_filename = $new_file->getFilename();
    $this->assertFileExists($new_file->getFileUri());
    $this->assertLessThan((int) $new_file->id(), $previous_file_id);

    // If the same file was previously uploaded, it should have a "_0" appendix.
    $this->assertEquals($new_filename, $filename_preexists ? 'fillpdf_test_v3_0.pdf' : 'fillpdf_test_v3.pdf');

    switch ($op) {
      case self::OP_UPLOAD:
        // We only uploaded, so make sure FillPdf Forms were not affected.
        $this->assertSession()->pageTextNotContains('New FillPDF form has been created.');
        $this->assertSession()->pageTextNotContains('Your previous field mappings have been transferred to the new PDF template you uploaded.');

        // Make sure the file is temporary only.
        // @todo Simplify once there is an assertFileIsTemporary().
        //   See: https://www.drupal.org/project/drupal/issues/3043129.
        $this->assertTrue($new_file->isTemporary(), new FormattableMarkup('File %file is temporary.', ['%file' => $new_file->getFileUri()]));

        // Now remove the PDF file again. The temporary file should now be
        // removed both from the disk and the database.
        $this->submitForm([], self::OP_REMOVE);
        $this->assertFileDoesNotExist($new_file->getFileUri());
        // @todo Remove $message when resolved:
        // @see https://www.drupal.org/project/drupal/issues/3043127
        $message = (string) new FormattableMarkup('File %file exists in database at the correct path.', [
          '%file' => $new_file->getFileUri(),
        ]);
        $this->assertFileEntryNotExists($new_file, $message);
        break;

      case self::OP_CREATE:
        // A new FillPdfForm should be created.
        $this->assertSession()->pageTextContains('New FillPDF form has been created.');
        $this->assertSession()->pageTextNotContains('Your previous field mappings have been transferred to the new PDF template you uploaded.');

        // There should be four fields in the correct order.
        // @todo Add some CSS markup to the view so we can test the order.
        $this->assertSession()->pageTextContainsOnce('ImageField');
        $this->assertSession()->pageTextContainsOnce('TestButton');
        $this->assertSession()->pageTextContainsOnce('TextField1');
        $this->assertSession()->pageTextContainsOnce('TextField2');
        $this->assertSession()->elementsCount('css', 'tbody > tr', 4);

        // Make sure the file is permanent and correctly placed.
        // @todo Remove $message when resolved:
        // @see https://www.drupal.org/project/drupal/issues/3043127
        $message = (string) new FormattableMarkup('File %file is permanent.', [
          '%file' => $new_file->getFileUri(),
        ]);
        $this->assertFileIsPermanent($new_file, $message);
        $expected_file_uri = FillPdf::buildFileUri($this->config('fillpdf.settings')->get('template_scheme'), "fillpdf/{$new_filename}");
        $this->assertEquals($new_file->getFileUri(), $expected_file_uri);
        break;

      case self::OP_SAVE:
        // The current FillPdfForm should be updated with the new file.
        $this->assertSession()->pageTextNotContains('New FillPDF form has been created.');
        $this->assertSession()->pageTextContains('Your previous field mappings have been transferred to the new PDF template you uploaded.');

        // Make sure the file is permanent and correctly placed.
        // @todo Remove $message when resolved:
        // @see https://www.drupal.org/project/drupal/issues/3043127
        $message = (string) new FormattableMarkup('File %file is permanent.', [
          '%file' => $new_file->getFileUri(),
        ]);
        $this->assertFileIsPermanent($new_file, $message);
        $expected_file_uri = FillPdf::buildFileUri($this->config('fillpdf.settings')->get('template_scheme'), "fillpdf/{$new_filename}");
        $this->assertEquals($new_file->getFileUri(), $expected_file_uri);

        $new_fields = $form->getFormFields();
        $new_mappings = [];
        foreach ($new_fields as $new_field) {
          $new_mappings[$new_field->pdf_key->value] = $new_field->value->value;
        }

        /** @var array $existing_mappings */
        foreach ($existing_mappings as $field_name => $existing_mapping) {
          $this->assertEquals($existing_mapping, $new_mappings[$field_name], 'New mapping value matches old mapping value.');
        }
        break;
    }
  }

}
