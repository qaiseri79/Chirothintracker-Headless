<?php

namespace Drupal\fillpdf\Service;

use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Url;
use Drupal\fillpdf\Entity\FillPdfForm;
use Drupal\fillpdf\FillPdfLinkManipulatorInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * {@inheritdoc}
 */
class FillPdfLinkManipulator implements FillPdfLinkManipulatorInterface {

  /**
   * {@inheritdoc}
   *
   * @todo Maybe this should return a FillPdfLinkContext object or something?
   *   Guess it depends on how much I end up needing to change it.
   */
  public function parseRequest(Request $request) {
    // @todo Use Url::fromRequest when/if it lands in core. See https://www.drupal.org/node/2605530
    $path = $request->getUri();
    $request_url = $this->createUrlFromString($path);

    return $this->parseLink($request_url);
  }

  /**
   * {@inheritdoc}
   */
  public function parseUrlString($url) {
    $link = $this->createUrlFromString($url);
    return $this->parseLink($link);
  }

  /**
   * Creates a URL object from an internal path or external URL.
   *
   * @param string $url
   *   The internal path or external URL string.
   *
   * @return \Drupal\Core\Url
   *   A Url object representing the URL string.
   *
   * @see FillPdfLinkManipulatorInterface::parseUrlString()
   */
  protected function createUrlFromString($url) {
    $url_parts = UrlHelper::parse($url);
    $path = $url_parts['path'];
    $query = $url_parts['query'];

    $link = Url::fromUri($path, ['query' => $query]);
    return $link;
  }

  /**
   * {@inheritdoc}
   */
  public function parseLink(Url $link) {
    $query = $link->getOption('query');

    if (empty($query['fid'])) {
      throw new \InvalidArgumentException('No FillPDF Form was specified in the query string, so failing.');
    }

    $fillpdf_form = FillPdfForm::load($query['fid']);
    if (!$fillpdf_form) {
      throw new \InvalidArgumentException("The requested FillPDF Form doesn't exist, so failing.");
    }

    // Set the fid, merging in evaluated boolean flags.
    $context = [
      'fid' => $query['fid'],
    ] + static::parseBooleanFlags($query);

    // Early return if PDF is just to be populated with sample data.
    if ($context['sample'] === TRUE) {
      $context['entity_ids'] = [];
      return $context;
    }

    // No sample and no entities given, so try enriching with defaults.
    if (empty($query['entity_id']) && empty($query['entity_ids'])) {
      $default_entity_id = $fillpdf_form->default_entity_id->value;
      if (!empty($default_entity_id)) {
        $query['entity_id'] = $default_entity_id;
        $query['entity_type'] = $fillpdf_form->default_entity_type->value;
      }
    }

    // Merge in parsed entities.
    $context += static::parseEntityIds($query);

    return $context;
  }

  /**
   * Helper method parsing boolean flags.
   *
   * @param array $query
   *   Array of query parameters.
   *
   * @return array
   *   An associative array representing the request context.
   *
   * @internal
   */
  public static function parseBooleanFlags(array $query) {
    $context = [
      'force_download' => FALSE,
      'flatten' => TRUE,
      'sample' => FALSE,
    ];

    if (isset($query['download']) && filter_var($query['download'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) === TRUE) {
      $context['force_download'] = TRUE;
    }

    if (isset($query['flatten']) && $query['flatten'] !== '' && filter_var($query['flatten'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) === FALSE) {
      $context['flatten'] = FALSE;
    }

    if (isset($query['sample']) && filter_var($query['sample'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) === TRUE) {
      $context['sample'] = TRUE;
    }

    return $context;
  }

  /**
   * Helper method parsing entities.
   *
   * @param array $query
   *   Array of query parameters.
   *
   * @return array
   *   An associative array representing the request context.
   *
   * @internal
   */
  public static function parseEntityIds(array $query) {
    $context = [
      'entity_ids' => [],
    ];

    // Convert single entity ID into array notation.
    if (!empty($query['entity_id'])) {
      $query['entity_ids'] = (array) $query['entity_id'];
    }
    // Early return if no entity IDs given.
    elseif (empty($query['entity_ids'])) {
      return $context;
    }

    // Re-key entity IDs so they can be loaded easily with loadMultiple().
    foreach ($query['entity_ids'] as $entity_id) {
      [$entity_type, $entity_id] = array_pad(explode(':', $entity_id), -2, '');
      if (empty($entity_type)) {
        $entity_type = !empty($query['entity_type']) ? $query['entity_type'] : 'node';
      }
      $context['entity_ids'][$entity_type][$entity_id] = $entity_id;
    }

    return $context;
  }

  /**
   * {@inheritdoc}
   */
  public function generateLink(array $parameters) {
    if (!isset($parameters['fid'])) {
      throw new \InvalidArgumentException("The \$parameters argument must contain the fid key (the FillPDF Form's ID).");
    }

    $query = [
      'fid' => $parameters['fid'],
    ];

    $query += static::prepareBooleanFlags($parameters);

    $query += static::prepareEntityIds($parameters);

    $fillpdf_link = Url::fromRoute('fillpdf.populate_pdf',
      [],
      ['query' => $query]);

    return $fillpdf_link;
  }

  /**
   * Helper method preparing boolean flags for link generation.
   *
   * @param array $parameters
   *   Array of parameters.
   *
   * @return array
   *   An associative array of sanitized boolean flags.
   *
   * @internal
   */
  public static function prepareBooleanFlags(array $parameters) {
    // @todo Create a value object for FillPdfMergeContext and get the defaults
    // here from that.
    $query = [];

    if (!empty($parameters['force_download'])) {
      $query['download'] = TRUE;
    }

    if (isset($parameters['flatten']) && $parameters['flatten'] == FALSE) {
      $query['flatten'] = FALSE;
    }

    if (!empty($parameters['sample'])) {
      $query['sample'] = TRUE;
    }

    return $query;
  }

  /**
   * Helper method preparing entity IDs for link generation.
   *
   * @param array $parameters
   *   Array of parameters.
   *
   * @return array
   *   An associative array of entity IDs.
   *
   * @internal
   */
  public static function prepareEntityIds(array $parameters) {
    $query = [];

    if (empty($parameters['entity_ids'])) {
      return $query;
    }

    // $parameters['entity_ids'] contains entity IDs indexed by entity type.
    // Collapse these into the entity_type:entity_id format.
    $entity_ids = [];
    foreach ($parameters['entity_ids'] as $entity_type => $type_ids) {
      foreach ($type_ids as $entity_id) {
        $entity_ids[] = "{$entity_type}:{$entity_id}";
      }
    }

    switch (count($entity_ids)) {
      case 0:
        break;

      case 1:
        $query['entity_id'] = reset($entity_ids);
        break;

      default:
        $query['entity_ids'] = $entity_ids;
    }

    return $query;
  }

}
