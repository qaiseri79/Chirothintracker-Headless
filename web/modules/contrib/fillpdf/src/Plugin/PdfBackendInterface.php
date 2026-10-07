<?php

namespace Drupal\fillpdf\Plugin;

use Drupal\file\FileInterface;

/**
 * Defines an interface for FillPDF PdfBackend plugins.
 */
interface PdfBackendInterface {

  /**
   * Parse a PDF and return a list of its fields.
   *
   * @param \Drupal\file\FileInterface $template_file
   *   The PDF template whose fields are to be parsed.
   *
   * @return string[][]
   *   An array of associative arrays. Each sub-array contains a 'name' key with
   *   the name of the field and a 'type' key with the type. These can be
   *   iterated over and saved by the caller.
   *
   * @see \Drupal\fillpdf\Plugin\PdfBackendInterface::parseStream()
   *
   * @todo Replace output array by a value object.
   */
  public function parseFile(FileInterface $template_file);

  /**
   * Parse a PDF and return a list of its fields.
   *
   * @param string $pdf_content
   *   The PDF template whose fields are to be parsed. This should be the
   *   contents of a PDF loaded with something like file_get_contents() or
   *   equivalent.
   *
   * @return string[][]
   *   An array of associative arrays. Each sub-array contains a 'name' key with
   *   the name of the field and a 'type' key with the type. These can be
   *   iterated over and saved by the caller.
   *
   * @see \Drupal\fillpdf\Plugin\PdfBackendInterface::parseFile()
   *
   * @todo Replace output array by a value object.
   */
  public function parseStream($pdf_content);

  /**
   * Populate a PDF file with field data.
   *
   * @param \Drupal\file\FileInterface $template_file
   *   The PDF template the field values specified in the mapping should be
   *   merged into.
   * @param \Drupal\fillpdf\FieldMapping[] $field_mappings
   *   An array of FieldMapping objects mapping PDF field keys to the values
   *   they should be replaced with. Example:
   *   @code
   *   [
   *     'Foo' => new TextFieldMapping('bar'),
   *     'Foo2' => new TextFieldMapping('bar2'),
   *     'Image1' => new ImageFieldMapping(base64_encode(file_get_contents($image)), 'jpg'),
   *   ]
   *   @endcode
   * @param array $context
   *   The request context as returned by FillPdfLinkManipulator::parseLink().
   *
   * @return string|null
   *   The raw file contents of the new PDF, or NULL if merging failed. The
   *   caller has to handle saving or serving the file accordingly.
   *
   * @see \Drupal\fillpdf\Plugin\PdfBackendInterface::mergeStream()
   */
  public function mergeFile(FileInterface $template_file, array $field_mappings, array $context);

  /**
   * Populate a PDF file with field data.
   *
   * @param string $pdf_content
   *   The PDF template the field values specified in the mapping should be
   *   merged into.
   * @param \Drupal\fillpdf\FieldMapping[] $field_mappings
   *   An array of FieldMapping objects mapping PDF field keys to the values
   *   they should be replaced with. Example:
   *   @code
   *   [
   *     'Foo' => new TextFieldMapping('bar'),
   *     'Foo2' => new TextFieldMapping('bar2'),
   *     'Image1' => new ImageFieldMapping(base64_encode(file_get_contents($image)), 'jpg'),
   *   ]
   *   @endcode
   * @param array $context
   *   The request context as returned by FillPdfLinkManipulator::parseLink().
   *
   * @return string|null
   *   The raw file contents of the new PDF, or NULL if merging failed. The
   *   caller has to handle saving or serving the file accordingly.
   *
   * @see \Drupal\fillpdf\Plugin\PdfBackendInterface::mergeFile()
   */
  public function mergeStream($pdf_content, array $field_mappings, array $context);

}
