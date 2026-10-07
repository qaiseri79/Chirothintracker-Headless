<?php

namespace Drupal\Tests\fillpdf\Functional;

use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Url;
use Drupal\file\Entity\File;
use Drupal\fillpdf\Entity\FillPdfForm;
use Drupal\fillpdf\TokenResolver;
use Drupal\user\Entity\Role;
use Drupal\webform\Entity\Webform;
use Drupal\webform\Entity\WebformSubmission;
use Drupal\webform\WebformInterface;

/**
 * Tests Webform population and image stamping.
 *
 * @group fillpdf
 */
class PdfWebformPopulationTest extends FillPdfTestBase {

  /**
   * Modules to enable.
   *
   * The test runner will merge the $modules lists from this class, the class
   * it extends, and so on up the class hierarchy. It is not necessary to
   * include modules in your list that a parent class has already declared.
   *
   * @var string[]
   *
   * @see \Drupal\Tests\BrowserTestBase::installDrupal()
   */
  protected static $modules = ['webform', 'fillpdf_webform_test'];

  /**
   * A test webform submission.
   *
   * @var \Drupal\webform\WebformSubmissionInterface
   */
  protected $testSubmission;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Add some roles to this user.
    $existing_user_roles = $this->adminUser->getRoles(TRUE);
    $role_to_modify = Role::load(end($existing_user_roles));

    // Grant additional permissions to this user.
    $this->grantPermissions($role_to_modify, [
      'administer webform',
      'create webform',
    ]);

    // Create a test submission for our Contact form.
    $contact_form = Webform::load('fillpdf_contact');
    $contact_form_test_route = Url::fromRoute('entity.webform.test_form', ['webform' => $contact_form->id()]);
    $this->drupalGet($contact_form_test_route);
    $this->submitForm([], (string) $this->t('Send message'));

    // Load the submission.
    $this->testSubmission = WebformSubmission::load($this->getLastSubmissionId($contact_form));
  }

  /**
   * Tests Webform population and image stamping.
   */
  public function testPdfPopulation() {
    $this->uploadTestPdf('fillpdf_test_v3.pdf');
    $this->assertSession()->pageTextContains('New FillPDF form has been created.');
    $fillpdf_form = FillPdfForm::load($this->getLatestFillPdfForm());

    // Get the field definitions for the form that was created and configure
    // them.
    $this->mapFillPdfFieldsToWebformFields($fillpdf_form->getFormFields());

    // Hit the generation route, check the results from the test backend plugin.
    $fillpdf_route = Url::fromRoute('fillpdf.populate_pdf', [], [
      'query' => [
        'fid' => $fillpdf_form->id(),
        'entity_id' => "webform_submission:{$this->testSubmission->id()}",
      ],
    ]);
    $this->drupalGet($fillpdf_route);

    // We don't actually care about downloading the fake PDF. We just want to
    // check what happened in the backend.
    $populate_result = $this->container->get('state')
      ->get('fillpdf_test.last_populated_metadata');

    $submission_values = $this->testSubmission->getData();
    self::assertEquals(
      $populate_result['field_mapping']['TextField1']->getData(),
      $this->testSubmission->getWebform()->label(),
      'PDF is populated with the title of the Webform Submission.'
    );

    $submission_file = File::load($submission_values['image'][0]);
    /** @var \Drupal\fillpdf\FieldMapping\ImageFieldMapping $image_field_mapping */
    $image_field_mapping = $populate_result['field_mapping']['ImageField'];
    self::assertEquals(
      base64_encode($image_field_mapping->getData()),
      base64_encode(file_get_contents($submission_file->getFileUri())),
      'Encoded image matches known image.'
    );

    $path_info = pathinfo($submission_file->getFileUri());
    $expected_file_hash = md5($path_info['filename']) . '.' . $path_info['extension'];
    $actual_path_info = pathinfo($image_field_mapping->getUri());
    $actual_file_hash = md5($actual_path_info['filename']) . '.' . $actual_path_info['extension'];
    self::assertEquals(
      $actual_file_hash,
      $expected_file_hash,
      'Hashed filename matches known hash.'
    );

    self::assertEquals(
      $image_field_mapping->getUri(),
      $submission_file->getFileUri(),
      'URI in metadata matches expected URI.'
    );

    $signature_image = TokenResolver::getSignatureImage($submission_values['test_signature']);
    self::assertEquals(
      base64_encode($signature_image),
      base64_encode($populate_result['field_mapping']['TestButton']->getData()),
      'Signature matches signature from Webform.'
    );
  }

  /**
   * Get the last submission id.
   *
   * @param \Drupal\webform\WebformInterface $webform
   *   A webform.
   *
   * @return int
   *   The last submission id.
   */
  protected function getLastSubmissionId(WebformInterface $webform) {
    // Get submission sid.
    $url = UrlHelper::parse($this->getUrl());
    if (isset($url['query']['sid'])) {
      return $url['query']['sid'];
    }

    $entity_ids = $this->container->get('entity_type.manager')
      ->getStorage('webform_submission')
      ->getQuery()
      ->accessCheck(TRUE)
      ->sort('sid', 'DESC')
      ->condition('webform_id', $webform->id())
      ->execute();
    return reset($entity_ids);
  }

  /**
   * Maps FillPdf fields to node fields.
   *
   * @param \Drupal\fillpdf\Entity\FillPdfFormField[] $fields
   *   Array of FillPdfFormFields.
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  protected function mapFillPdfFieldsToWebformFields(array $fields) {
    foreach ($fields as $pdf_key => $field) {
      switch ($pdf_key) {
        case 'ImageField':
          $field->value = '[webform_submission:values:image]';
          break;

        case 'TextField1':
          $field->value = '[webform_submission:webform:title]';
          break;

        case 'TestButton':
          $field->value = '[webform_submission:values:test_signature]';
          break;
      }
      $field->save();
    }

  }

}
