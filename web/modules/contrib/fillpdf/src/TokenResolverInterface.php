<?php

namespace Drupal\fillpdf;

/**
 * Provides consistent token replacement for one or multiple entity sources.
 *
 * @package Drupal\fillpdf
 */
interface TokenResolverInterface {

  /**
   * Replaces all tokens in a given string with appropriate values.
   *
   * This is basically a replacement for \Drupal\Core\Utility\Token::replace(),
   * only that it resolves image tokens, applies form and field replacements
   * after token replacement, and returns FieldMapping objects.
   *
   * @param string $text
   *   An plain text string containing replaceable tokens.
   * @param \Drupal\Core\Entity\EntityInterface[][] $data
   *   (optional) Multidimensional array of entities, keyed by entity ID and
   *   grouped by entity type.
   * @param array $options
   *   (optional) A keyed array of settings and flags to control the token
   *   replacement process. Supported options are:
   *   - langcode: A language code to be used when generating locale-sensitive
   *     tokens.
   *   - callback: A callback function that will be used to post-process the
   *     array of token replacements after they are generated.
   *
   * @return \Drupal\fillpdf\FieldMapping
   *   An instance of a FieldMapping.
   *
   * @see \Drupal\Core\Utility\Token::replace()
   */
  public function replace($text, array $data = [], array $options = []);

  /**
   * Returns the token service.
   *
   * @return \Drupal\token\Token
   *   The token service.
   */
  public function getTokenService();

  /**
   * Returns the token entity mapper.
   *
   * @return \Drupal\token\TokenEntityMapperInterface
   *   The token entity mapper.
   */
  public function getEntityMapper();

}
