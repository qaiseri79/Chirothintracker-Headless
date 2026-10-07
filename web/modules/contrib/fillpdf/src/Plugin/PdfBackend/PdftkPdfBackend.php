<?php

namespace Drupal\fillpdf\Plugin\PdfBackend;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\file\FileInterface;
use Drupal\file\FileRepositoryInterface;
use Drupal\fillpdf\Component\Utility\FillPdf;
use Drupal\fillpdf\Component\Utility\Xfdf;
use Drupal\fillpdf\FieldMapping\TextFieldMapping;
use Drupal\fillpdf\Plugin\PdfBackendBase;
use Drupal\fillpdf\ShellManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Pdftk PdfBackend plugin.
 *
 * @PdfBackend(
 *   id = "pdftk",
 *   label = @Translation("pdftk"),
 *   description = @Translation("Locally installed pdftk. You will need a VPS or a dedicated server to install pdftk, see <a href='https://www.drupal.org/docs/contributed-modules/fillpdf'>documentation</a>."),
 *   weight = -5
 * )
 */
class PdftkPdfBackend extends PdfBackendBase implements ContainerFactoryPluginInterface {

  /**
   * Constructs a PdftkPdfBackend plugin object.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param array $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\File\FileSystemInterface $fileSystem
   *   The file system.
   * @param \Drupal\file\FileRepositoryInterface $fileRepository
   *   The file.repository service.
   * @param \Drupal\fillpdf\ShellManager $shellManager
   *   The FillPDF shell manager.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    array $plugin_definition,
    protected FileSystemInterface $fileSystem,
    protected FileRepositoryInterface $fileRepository,
    protected ShellManager $shellManager,
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('file_system'),
      $container->get('file.repository'),
      $container->get('fillpdf.shell_manager'),
      $container->get('entity_type.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function parseStream($pdf_content) {
    $template_file = $this->fileRepository->writeData($pdf_content);
    return $this->parseFile($template_file);
  }

  /**
   * {@inheritdoc}
   */
  public function parseFile(FileInterface $template_file) {
    $template_uri = $template_file->getFileUri();

    $pdftk_path = $this->getPdftkPath();
    $status = FillPdf::checkPdftkPath($pdftk_path);
    if ($status === FALSE) {
      $this->messenger()->addError($this->t('pdftk not properly installed.'));
      return [];
    }

    // Escape the template's realpath.
    $template_path = $this->shellManager->escapeShellArg($this->fileSystem->realpath($template_uri));

    // Use exec() to call pdftk (because it will be easier to go line-by-line
    // parsing the output) and pass $content via stdin. Retrieve the fields with
    // dump_data_fields_utf8().
    $output = [];
    exec("{$pdftk_path} {$template_path} dump_data_fields_utf8", $output, $status);
    if (count($output) === 0) {
      return [];
    }

    // Build a simple map of dump_data_fields_utf8 keys to our own array keys.
    $data_fields_map = [
      'FieldType' => 'type',
      'FieldName' => 'name',
      'FieldFlags' => 'flags',
      'FieldValue' => 'value',
      'FieldJustification' => 'justification',
    ];

    // Build the fields array.
    $fields = [];
    $field_index = -1;
    foreach ($output as $line_item) {
      if ($line_item == '---') {
        $field_index++;
        continue;
      }
      // Separate the data key from the data value.
      [$key, $value] = explode(':', $line_item);
      if (in_array($key, array_keys($data_fields_map))) {
        // Trim spaces.
        $fields[$field_index][$data_fields_map[$key]] = trim($value);
      }
    }

    return $fields;
  }

  /**
   * Returns the configured path to the local pdftk installation.
   *
   * @return string
   *   The configured path to the local pdftk installation.
   *
   * @internal
   */
  protected function getPdftkPath() {
    return $this->configuration['pdftk_path'] ?? 'pdftk';
  }

  /**
   * {@inheritdoc}
   */
  public function mergeStream($pdf_content, array $field_mappings, array $context) {
    $template_file = $this->fileRepository->writeData($pdf_content);
    return $this->mergeFile($template_file, $field_mappings, $context);
  }

  /**
   * {@inheritdoc}
   */
  public function mergeFile(FileInterface $template_file, array $field_mappings, array $context) {
    $template_uri = $template_file->getFileUri();
    $fields = [];
    foreach ($field_mappings as $pdf_key => $mapping) {
      if ($mapping instanceof TextFieldMapping) {
        $fields[$pdf_key] = (string) $mapping;
      }
    }

    $xfdf_name = $template_uri . '.xfdf';
    $xfdf = Xfdf::createString($fields, basename($xfdf_name));
    // Generate the file.
    $xfdf_file = $this->fileRepository->writeData($xfdf, $xfdf_name, FileExists::Rename);

    // @todo Improve this approach when we turn $context into a value object.
    if (!isset($context['fid'])) {
      throw new \InvalidArgumentException("pdftk requires \$context['fid'] to be set to the ID of the FillPDF Form so that it can check if encryption is configured. The merge was aborted because it was not set.");
    }
    $fillpdf_form = $this->entityTypeManager->getStorage('fillpdf_form')->load($context['fid']);

    // Configure PDF security.
    $arg_permissions = $arg_owner_password = $arg_user_password = '';
    $arg_pdftk_encryption = $fillpdf_form->pdftk_encryption->value ? " {$fillpdf_form->pdftk_encryption->value}" : '';
    $permissions = $fillpdf_form->permissions->getString();
    if ($permissions) {
      // ItemList::getString() returns "Item1, Item2", but we don't want commas.
      $arg_permissions = ' allow ' . str_replace(',', '', $permissions);
    }
    $owner_password = $fillpdf_form->owner_password->value;
    if ($owner_password) {
      $arg_owner_password = ' owner_pw ' . $this->shellManager->escapeShellArg($owner_password);
    }
    $user_password = $fillpdf_form->user_password->value;
    if ($user_password) {
      $arg_user_password = ' user_pw ' . $this->shellManager->escapeShellArg($user_password);
    }

    // Escape the template's and the XFDF file's realpath.
    $template_path = $this->shellManager->escapeShellArg($this->fileSystem->realpath($template_uri));
    $xfdf_path = $this->shellManager->escapeShellArg($this->fileSystem->realpath($xfdf_file->getFileUri()));

    // Now feed this to pdftk and save the result to a variable.
    $pdftk_path = $this->getPdftkPath();
    ob_start();
    $command = "{$pdftk_path} {$template_path} fill_form {$xfdf_path} output - " . ($context['flatten'] ? 'flatten drop_xfa' : '') . "{$arg_pdftk_encryption}{$arg_permissions}{$arg_owner_password}{$arg_user_password}";
    passthru($command);
    $data = ob_get_clean();
    if ($data === FALSE) {
      $this->messenger()->addError($this->t('pdftk not properly installed. No PDF generated.'));
    }
    $xfdf_file->delete();

    if ($data) {
      return $data;
    }

    return NULL;
  }

  /**
   * Get valid PDFtk encryption options.
   *
   * @return array
   *   The valid encryption options.
   */
  public static function getEncryptionOptions(): array {
    return [
      '' => t('No encryption (Default)'),
      'encrypt_128bit' => t('128-bit encryption (Recommended)'),
      'encrypt_40bit' => t('40-bit encryption'),
    ];
  }

  /**
   * Return a list of available user permissions for configuring PDF security.
   *
   * @return array
   *   The permission list.
   */
  public static function getUserPermissionList(): array {
    return [
      'Printing' => t('Printing (Top Quality Printing)'),
      'DegradedPrinting' => t('DegradedPrinting (Lower Quality Printing)'),
      'ModifyContents' => t('ModifyContents (Also allows <em>Assembly</em>)'),
      'Assembly' => t('Assembly'),
      'CopyContents' => t('CopyContents (Also allows <em>ScreenReaders</em>)'),
      'ScreenReaders' => t('ScreenReaders'),
      'ModifyAnnotations' => t('ModifyAnnotations (Also allows <em>FillIn</em>)'),
      'FillIn' => t('FillIn'),
      'AllFeatures' => t('AllFeatures (Allows the user to perform all of the above, and top quality printing.)'),
    ];
  }

}
