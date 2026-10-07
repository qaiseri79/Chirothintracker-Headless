<?php

namespace Drupal\Tests\fillpdf\Functional;

use Drupal\file\Entity\File;
use Drupal\fillpdf\Component\Utility\FillPdf;
use Drupal\fillpdf\Entity\FillPdfForm;
use Drupal\fillpdf_test\Plugin\PdfBackend\TestPdfBackend;
use Drupal\Tests\taxonomy\Traits\TaxonomyTestTrait;
use Drupal\user\Entity\Role;

/**
 * Tests Core entity population and image stamping.
 *
 * @group fillpdf
 */
class PdfPopulationTest extends FillPdfTestBase {

  use TaxonomyTestTrait;
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
  protected static $modules = ['filter', 'taxonomy'];

  /**
   * A test vocabulary.
   *
   * @var \Drupal\taxonomy\Entity\Vocabulary
   */
  protected $testVocabulary;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Grant additional permissions to the logged-in admin user.
    $existing_user_roles = $this->adminUser->getRoles(TRUE);
    $role_to_modify = Role::load(end($existing_user_roles));
    $this->grantPermissions($role_to_modify, [
      'administer image styles',
      'use text format restricted_html',
    ]);

    $this->testVocabulary = $this->createVocabulary();

    $this->configureFillPdf();
  }

  /**
   * Tests Core entity population and image stamping.
   */
  public function testPdfPopulation() {
    $this->uploadTestPdf('fillpdf_test_v3.pdf');
    $this->assertSession()->pageTextContains('New FillPDF form has been created.');

    // Load the FillPdf Form and add a form-level replacement.
    $fillpdf_form = FillPdfForm::load($this->getLatestFillPdfForm());
    $fillpdf_form->replacements = 'Hello & how are you?|Hello & how is it going?';
    $fillpdf_form->save();

    // Get the field definitions for the form that was created and configure
    // them.
    FillPdfTestBase::mapFillPdfFieldsToEntityFields('node', $fillpdf_form->getFormFields());

    // Create a node to populate the FillPdf Form.
    $node = $this->createNode([
      'title' => 'Hello & how are you?',
      'type' => 'article',
      'body' => [
        [
          'value' => "<p>PDF form fields don't accept <em>any</em> HTML.</p>",
          'format' => 'restricted_html',
        ],
      ],
    ]);

    // Hit the generation route, check the results from the test backend plugin.
    $url = $this->linkManipulator->generateLink([
      'fid' => $fillpdf_form->id(),
      'entity_ids' => ['node' => [$node->id()]],
    ]);
    $this->drupalGet($url);

    // We don't actually care about downloading the fake PDF. We just want to
    // check what happened in the backend.
    $populate_result = $this->container->get('state')
      ->get('fillpdf_test.last_populated_metadata');

    self::assertEquals(
      'Hello & how are you doing?',
      $populate_result['field_mapping']['TextField1']->getData(),
      'PDF is populated with the title of the node with all HTML stripped.'
    );

    self::assertEquals(
      "PDF form fields don't accept any HTML.\n",
      $populate_result['field_mapping']['TextField2']->getData(),
      'PDF is populated with the node body. HTML is stripped but a newline
       replaces the <p> tags.'
    );
  }

  /**
   * Tests sample mapping.
   */
  public function testSamplePdf() {
    $this->uploadTestPdf('fillpdf_test_v3.pdf');

    // Load the FillPdf Form.
    $fillpdf_form = FillPdfForm::load($this->getLatestFillPdfForm());

    // Hit the generation route, check the results from the test backend plugin.
    $url = $this->linkManipulator->generateLink([
      'fid' => $fillpdf_form->id(),
      'sample' => 1,
    ]);
    $this->drupalGet($url);

    // We don't actually care about downloading the fake PDF. We just want to
    // check what happened in the backend.
    $populate_result = $this->container->get('state')
      ->get('fillpdf_test.last_populated_metadata');

    self::assertEquals(
      '<TextField1>',
      $populate_result['field_mapping']['TextField1']->getData(),
      'Sample field mapped properly.'
    );
  }

  /**
   * Tests Core image stamping.
   */
  public function testImageStamping() {
    $this->uploadTestPdf('fillpdf_test_v3.pdf');
    $this->assertSession()->pageTextContains('New FillPDF form has been created.');
    $fillpdf_form = FillPdfForm::load($this->getLatestFillPdfForm());

    $testCases = [
      'node' => 'article',
      'taxonomy_term' => $this->testVocabulary->id(),
      'user' => 'user',
    ];
    foreach ($testCases as $entity_type => $bundle) {
      $this->createImageField('field_fillpdf_test_image', $entity_type, $bundle);

      $storage = \Drupal::entityTypeManager()->getStorage($entity_type);
      $entity = $storage->load($this->createImageFieldEntity(
        $this->testImage,
        'field_fillpdf_test_image',
        $entity_type,
        $bundle,
        'FillPDF Test image'
      ));

      // Get the field definitions for the form that was created and configure
      // them.
      FillPdfTestBase::mapFillPdfFieldsToEntityFields($entity_type, $fillpdf_form->getFormFields());

      // Hit the generation route, check results from the test backend plugin.
      $url = $this->linkManipulator->generateLink([
        'fid' => $fillpdf_form->id(),
        'entity_ids' => [$entity_type => [$entity->id()]],
      ]);
      $this->drupalGet($url);

      // We don't actually care about downloading the fake PDF. We just want to
      // check what happened in the backend.
      $populate_result = $this->container->get('state')
        ->get('fillpdf_test.last_populated_metadata');

      $file = File::load($entity->field_fillpdf_test_image->target_id);

      self::assertArrayHasKey('ImageField', $populate_result['field_mapping'], "$entity_type isn't populated with an image.");
      $image_field_mapping = $populate_result['field_mapping']['ImageField'];
      self::assertEquals(
        base64_encode($image_field_mapping->getData()),
        base64_encode(file_get_contents($file->getFileUri())),
        'Encoded image matches known image.'
      );

      $path_info = pathinfo($file->getFileUri());
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
        $file->getFileUri(),
        'URI in metadata matches expected URI.'
      );
    }
  }

  /**
   * Test that duplicate fields get filtered out.
   */
  public function testDuplicateFieldHandling() {
    $this->uploadTestPdf('fillpdf_test_v3.pdf');
    $fillpdf_form = FillPdfForm::load($this->getLatestFillPdfForm());

    // Get the field definitions from the actually created form and sort.
    $actual_keys = [];
    foreach (array_keys($fillpdf_form->getFormFields()) as $pdf_key) {
      $actual_keys[] = $pdf_key;
    }
    sort($actual_keys);

    // Get the fields from the fixture and sort.
    $expected_keys = [];
    foreach (TestPdfBackend::getParseResult() as $expected_field) {
      $expected_keys[] = $expected_field['name'];
    }
    sort($expected_keys);

    // Now compare. InputHelper::attachPdfToForm() filtered out the duplicate,
    // so the count differs, but not the actual values.
    $this->assertCount(5, $expected_keys);
    $this->assertCount(4, $actual_keys);
    $differences = array_diff($expected_keys, $actual_keys);
    self::assertEmpty($differences, 'Parsed fields are in fixture match.');
  }

  /**
   * Tests that merging with the backend proxy works.
   */
  public function testProxyMerge() {
    $this->uploadTestPdf('fillpdf_test_v3.pdf');
    $fillpdf_form = FillPdfForm::load($this->getLatestFillPdfForm());

    // Instantiate the backend proxy (which uses the configured backend).
    /** @var \Drupal\fillpdf\Service\BackendProxyInterface $merge_proxy */
    $merge_proxy = $this->container->get('fillpdf.backend_proxy');

    $original_pdf = file_get_contents($this->getTestPdfPath('fillpdf_test_v3.pdf'));

    FillPdfTestBase::mapFillPdfFieldsToEntityFields('node', $fillpdf_form->getFormFields());

    // Create a node to populate the FillPdf Form.
    // The content of this node is not important; we just need an entity to
    // pass.
    $node = $this->createNode([
      'title' => 'Hello & how are you?',
      'type' => 'article',
      'body' => [
        [
          'value' => "<p>PDF form fields don't accept <em>any</em> HTML.</p>",
          'format' => 'restricted_html',
        ],
      ],
    ]);
    $entities['node'] = [$node->id() => $node];

    // Test merging via the proxy.
    $merged_pdf = $merge_proxy->merge($fillpdf_form, $entities);
    self::assertEquals($original_pdf, $merged_pdf);

    $merge_state = $this->container->get('state')
      ->get('fillpdf_test.last_populated_metadata');
    self::assertIsArray($merge_state, 'Test backend was used.');
    self::assertArrayHasKey('field_mapping', $merge_state, 'field_mapping key from test backend is present.');
    self::assertArrayHasKey('context', $merge_state, 'context key from test backend is present.');

    // These are not that important. They just work because of other tests.
    // We're just testing that token replacement works in general, not the
    // details of it. We have other tests for that.
    self::assertEquals('Hello & how are you doing?', $merge_state['field_mapping']['TextField1']->getData());
    self::assertEquals("PDF form fields don't accept any HTML.\n", $merge_state['field_mapping']['TextField2']->getData());
  }

  /**
   * Tests PDF population using local service.
   *
   * @throws \Behat\Mink\Exception\ResponseTextException
   *   Thrown when test had to be skipped as FillPDF LocalServer is not
   *   available.
   *
   * @see \Drupal\Tests\fillpdf\Traits\TestFillPdfTrait::configureLocalServiceBackend()
   */
  public function testMergeLocalService() {
    // For local container testing, we require the Docker container to be
    // running. If LocalServer's /ping endpoint does not return a 200, we assume
    // that we're not in an environment where we can run this
    // test.
    $this->configureLocalServiceBackend();
    $config = $this->container->get('config.factory')->get('fillpdf.settings');
    if (!FillPdf::checkLocalServiceEndpoint($this->container->get('http_client'), $config)) {
      $this->markTestSkipped('FillPDF LocalServer unavailable, so skipping test.');
    }
    $this->backendTest();
  }

  /**
   * Tests PDF population using a local install of pdftk.
   *
   * @throws \Behat\Mink\Exception\ResponseTextException
   *   Thrown when test had to be skipped as local pdftk install is not
   *   available.
   */
  public function testMergePdftk() {
    $this->configureFillPdf(['backend' => 'pdftk']);
    if (!FillPdf::checkPdftkPath()) {
      $this->markTestSkipped('pdftk not available, so skipping test.');
    }
    $this->backendTest();
  }

  /**
   * Tests a backend.
   *
   * @throws \Behat\Mink\Exception\ResponseTextException
   */
  protected function backendTest() {
    // If we can upload a PDF, parsing is working.
    // Test with a node.
    $this->uploadTestPdf('fillpdf_Ŧäßð_v3â.pdf');
    $fillpdf_form = FillPdfForm::load($this->getLatestFillPdfForm());

    // Get the field definitions for the form that was created and configure
    // them.
    $fields = $fillpdf_form->getFormFields();
    FillPdfTestBase::mapFillPdfFieldsToEntityFields('node', $fields);

    // Set up a test node.
    $node = $this->createNode([
      'title' => 'Test',
      'type' => 'article',
    ]);

    // Hit the generation route, check the results from the test backend plugin.
    $url = $this->linkManipulator->generateLink([
      'fid' => $fillpdf_form->id(),
      'entity_ids' => ['node' => [$node->id()]],
    ]);
    $this->drupalGet($url);

    // Check if what we're seeing really is a PDF file.
    $maybe_pdf = $this->getSession()->getPage()->getContent();
    static::assertEquals('application/pdf', $this->getMimeType($maybe_pdf));

    $this->drupalGet('<front>');
    $this->assertSession()->pageTextNotContains('Merging the FillPDF Form failed');
  }

}
