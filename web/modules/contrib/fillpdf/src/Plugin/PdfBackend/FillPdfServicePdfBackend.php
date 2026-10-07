<?php

namespace Drupal\fillpdf\Plugin\PdfBackend;

use Drupal\file\FileInterface;
use Drupal\fillpdf\FieldMapping\ImageFieldMapping;
use Drupal\fillpdf\FieldMapping\TextFieldMapping;
use Drupal\fillpdf\Plugin\PdfBackendBase;

/**
 * FillPDF Service PdfBackend plugin.
 *
 * @PdfBackend(
 *   id = "fillpdf_service",
 *   label = @Translation("FillPDF Service"),
 *   description = @Translation("A remote API service."),
 *   weight = 10
 * )
 */
class FillPdfServicePdfBackend extends PdfBackendBase {

  /**
   * {@inheritdoc}
   */
  public function parseFile(FileInterface $template_file) {
    $pdf_content = file_get_contents($template_file->getFileUri());
    return $this->parseStream($pdf_content);
  }

  /**
   * {@inheritdoc}
   */
  public function parseStream($pdf_content) {
    $result = $this->xmlRpcRequest('parse_pdf_fields', base64_encode($pdf_content));

    if ($result->error == TRUE) {
      // @todo Throw an exception, log a message etc.
      return [];
    }

    $fields = $result->data;

    return $fields;
  }

  /**
   * Make an XML-RPC request.
   *
   * @param string $method
   *   The method to call. Additional arguments are the parameters to the
   *   xmlrpc() call.
   *
   * @return object
   *   Object with properties 'error' and 'data' representing the result of the
   *   request.
   */
  protected function xmlRpcRequest($method /* $args */) {
    $url = $this->configuration['remote_protocol'] . '://' . $this->configuration['remote_endpoint'];
    $args = func_get_args();

    // Fix up the array for Drupal 7 xmlrpc() function style.
    $args = [$args[0] => array_slice($args, 1)];
    $result = xmlrpc($url, $args);

    $ret = new \stdClass();

    if (isset($result['error'])) {
      $this->messenger()->addError($result['error']);
      $ret->error = TRUE;
    }
    // @phpstan-ignore function.notFound (xmlrpc_error() is defined when xmlrpc() is executed)
    elseif ($result == FALSE || xmlrpc_error()) {
      // @phpstan-ignore function.notFound
      $error = xmlrpc_error();
      $ret->error = TRUE;
      $this->messenger()->addError($this->t('There was a problem contacting the FillPDF service.
      It may be down, or you may not have internet access. [ERROR @code: @message]',
        ['@code' => $error->code, '@message' => $error->message]));
    }
    else {
      $ret->data = $result['data'];
      $ret->error = FALSE;
    }
    return $ret;
  }

  /**
   * {@inheritdoc}
   */
  public function mergeFile(FileInterface $template_file, array $field_mappings, array $context) {
    $pdf_content = file_get_contents($template_file->getFileUri());
    return $this->mergeStream($pdf_content, $field_mappings, $context);
  }

  /**
   * {@inheritdoc}
   */
  public function mergeStream($pdf_content, array $field_mappings, array $context) {
    $api_key = $this->configuration['fillpdf_service_api_key'];

    $fields = $images = [];
    foreach ($field_mappings as $pdf_key => $mapping) {
      if ($mapping instanceof TextFieldMapping) {
        $fields[$pdf_key] = (string) $mapping;
      }
      elseif ($mapping instanceof ImageFieldMapping) {
        // Anonymize image data from the fields array; we should not send the
        // real filename to FillPDF Service. We do this in the specific backend
        // because other plugin types may need the filename on the local system.
        $field_path_info = pathinfo($mapping->getUri());
        $fields[$pdf_key] = '{image}' . md5($field_path_info['filename']) . '.' . $field_path_info['extension'];
        $images[$pdf_key] = [
          'data' => base64_encode($mapping->getData()),
          'filenamehash' => md5($field_path_info['filename']) . '.' . $field_path_info['extension'],
        ];
      }
    }

    $result = $this->xmlRpcRequest('merge_pdf_v3', base64_encode($pdf_content), $fields, $api_key, $context['flatten'], $images);

    if ($result->error === FALSE && $result->data) {
      $populated_pdf = base64_decode($result->data);
      return $populated_pdf;
    }
  }

}
