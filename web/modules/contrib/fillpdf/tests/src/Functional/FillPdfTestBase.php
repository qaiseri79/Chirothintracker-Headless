<?php

namespace Drupal\Tests\fillpdf\Functional;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Tests\file\Functional\FileFieldTestBase;
use Drupal\Tests\fillpdf\Traits\TestFillPdfTrait;
use Drupal\Tests\fillpdf\Traits\TestImageFieldTrait;
use Drupal\user\Entity\Role;

/**
 * Base class that can be inherited by FillPDF tests.
 */
abstract class FillPdfTestBase extends FileFieldTestBase {

  use StringTranslationTrait;
  use TestFillPdfTrait;
  use TestImageFieldTrait;

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

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
  protected static $modules = ['image', 'fillpdf_test'];

  /**
   * The FillPDF link manipulator service.
   *
   * @var \Drupal\fillpdf\Service\FillPdfLinkManipulator
   */
  protected $linkManipulator;

  /**
   * A test image.
   *
   * @var \Drupal\file\Entity\File
   */
  protected $testImage;

  /**
   * Maps FillPdf fields to entity fields.
   *
   * @param string $entity_type
   *   The entity type.
   * @param \Drupal\fillpdf\Entity\FillPdfFormField[] $fields
   *   Array of FillPdfFormFields.
   */
  public static function mapFillPdfFieldsToEntityFields($entity_type, array $fields) {
    /** @var \Drupal\token\TokenEntityMapperInterface $token_entity_mapper */
    $token_entity_mapper = \Drupal::service('token.entity_mapper');
    $token_type = $token_entity_mapper->getTokenTypeForEntityType($entity_type);

    foreach ($fields as $pdf_key => $field) {
      switch ($pdf_key) {
        case 'ImageField':
        case 'Button2':
        case 'TestButton':
          $field->value = "[$token_type:field_fillpdf_test_image]";
          break;

        case 'TextField1':
        case 'Text1':
          $label_key = \Drupal::entityTypeManager()
            ->getDefinition($entity_type)
            ->getKey('label');
          $field->value = "[$token_type:$label_key]";
          $field->replacements = 'Hello & how are you?|Hello & how are you doing?';
          break;

        case 'TextField2':
        case 'Text2':
          if ($token_type == 'node') {
            $field->value = '[node:body]';
          }
          elseif ($token_type == 'term') {
            $field->value = '[term:description]';
          }
          break;
      }
      $field->save();
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Grant additional permissions to the logged-in admin user.
    $existing_user_roles = $this->adminUser->getRoles(TRUE);
    $role_to_modify = Role::load(end($existing_user_roles));
    $this->grantPermissions($role_to_modify, [
      'administer pdfs',
      'create article content',
      'edit any article content',
      'delete any article content',
    ]);

    $this->testImage = $this->getTestFile('image');

    $this->configureFillPdf();

    $this->linkManipulator = $this->container->get('fillpdf.link_manipulator');
  }

}
