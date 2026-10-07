<?php

namespace Drupal\fillpdf;

use Drupal\Component\Render\PlainTextOutput;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Render\BubbleableMetadata;
use Drupal\Core\Utility\Token;
use Drupal\file\Entity\File;
use Drupal\fillpdf\FieldMapping\ImageFieldMapping;
use Drupal\fillpdf\FieldMapping\TextFieldMapping;
use Drupal\image\Plugin\Field\FieldType\ImageItem;
use Drupal\token\TokenEntityMapperInterface;

/**
 * {@inheritdoc}
 *
 * @package Drupal\fillpdf
 */
class TokenResolver implements TokenResolverInterface {

  /**
   * The token service.
   *
   * @var \Drupal\token\Token
   */
  protected $tokenService;

  /**
   * The token entity mapper.
   *
   * @var \Drupal\token\TokenEntityMapperInterface
   */
  protected $tokenEntityMapper;

  /**
   * The module handler.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * Constructs a TokenResolver object.
   *
   * @param \Drupal\Core\Utility\Token $token_service
   *   The token service.
   * @param \Drupal\token\TokenEntityMapperInterface $token_entity_mapper
   *   The token entity mapper.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler.
   */
  public function __construct(Token $token_service, TokenEntityMapperInterface $token_entity_mapper, ModuleHandlerInterface $module_handler) {
    $this->tokenService = $token_service;
    $this->tokenEntityMapper = $token_entity_mapper;
    $this->moduleHandler = $module_handler;
  }

  /**
   * {@inheritdoc}
   */
  public function replace($text, array $data = [], array $options = []) {
    // Initialize with defaults.
    $options += [
      'content' => '',
      'replacements' => [],
      'prefix' => '',
      'suffix' => '',
    ];

    $tokens = $this->tokenService->scan($text);
    if (empty($tokens)) {
      return new TextFieldMapping(PlainTextOutput::renderFromHtml($text));
    }

    // Content may be marked as either 'text', 'image' or '' (= unknown).
    // @todo Revisit when enforcing FillPdfFields to be one or the other.
    // @see https://www.drupal.org/project/fillpdf/issues/3049368
    $maybe_image = ($options['content'] !== 'text');
    $maybe_text = ($options['content'] !== 'image');

    // Loop through the token types.
    $bubbleable_metadata = new BubbleableMetadata();
    $replacements = [];
    foreach ($tokens as $token_type => $type_tokens) {
      $token_entity_type = $this->tokenEntityMapper->getEntityTypeForTokenType($token_type, FALSE);
      if ($token_entity_type && isset($data[$token_entity_type])) {
        // At least one provided entity matches this token type. If there's
        // more than one entity of this type, make sure the last one matching
        // this token wins.
        foreach (array_reverse($data[$token_entity_type]) as $entity) {
          // Only fieldable entities may supply image tokens.
          if ($maybe_image && $entity instanceof FieldableEntityInterface) {
            if ($token_entity_type === 'webform_submission' && $this->moduleHandler->moduleExists('webform')) {
              $image_mapping = static::parseImageWebformElementTokens(array_keys($type_tokens), $entity);
            }
            elseif ($this->moduleHandler->moduleExists('image')) {
              $image_mapping = static::parseImageFieldTokens(array_keys($type_tokens), $entity);
            }
            if (!empty($image_mapping)) {
              // Early return if we matched an image token.
              return $image_mapping;
            }
          }
          if ($maybe_text) {
            $replacements += $this->tokenService->generate($token_type, $type_tokens, [$token_type => $entity], $options, $bubbleable_metadata);
          }
        }
      }
      elseif ($maybe_text) {
        // None of the provided entities matches this token type. It may however
        // still be a global token.
        $replacements += $this->tokenService->generate($token_type, $type_tokens, $data, $options, $bubbleable_metadata);
      }
      // Clear any unresolved tokens of this type from the string.
      $replacements += array_fill_keys($tokens[$token_type], '');
    }

    // Apply token replacements.
    $resolved_string = str_replace(array_keys($replacements), array_values($replacements), $text);

    // Replace <br /> occurrences with newlines.
    $resolved_string = preg_replace('|<br />|', "\n", $resolved_string);

    // Apply transformation replacements.
    if (isset($options['replacements'][$resolved_string])) {
      $resolved_string = $options['replacements'][$resolved_string];
    }

    // Apply prefix and suffix, unless empty.
    if (!empty($resolved_string)) {
      $resolved_string = $options['prefix'] . $resolved_string . $options['suffix'];
    }

    return new TextFieldMapping(PlainTextOutput::renderFromHtml($resolved_string));
  }

  /**
   * Scans a potential webform image element token.
   *
   * This is only called if webform module is installed and the backend supports
   * image stamping.
   *
   * @param string[] $tokens
   *   List of non-fully qualified webform_submission tokens. These may be
   *   image element tokens such as 'values:image' or other tokens.
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   Webform submission entity.
   *
   * @return \Drupal\fillpdf\FieldMapping\ImageFieldMapping|null
   *   An ImageFieldMapping, or NULL if the tokens were no image element tokens.
   */
  protected static function parseImageWebformElementTokens(array $tokens, ContentEntityInterface $entity) {
    // Get all non-empty elements.
    /** @var \Drupal\webform\WebformSubmissionInterface $entity */
    $elements = $entity->getWebform()->getElementsInitializedFlattenedAndHasValue();

    // Loop through the tokens, starting with the last one.
    foreach (array_reverse($tokens) as $token) {
      $name = strtr($token, ['values:' => '']);
      if (!array_key_exists($name, $elements) || !isset($elements[$name]['#type'])) {
        continue;
      }
      if ($elements[$name]['#type'] === 'webform_image_file') {
        $file = File::load($entity->getElementData($name));
        if ($file) {
          $uri = $file->getFileUri();
          return new ImageFieldMapping(file_get_contents($uri), NULL, $uri);
        }
      }
      elseif ($elements[$name]['#type'] === 'webform_signature') {
        $signature_data = $entity->getElementData($name);
        $signature_image = static::getSignatureImage($signature_data);
        if (!$signature_image) {
          continue;
        }
        return new ImageFieldMapping($signature_image, NULL, 'webform_signature.png');
      }
    }

    return NULL;
  }

  /**
   * Scans a potential image field token.
   *
   * This is only called if image module is installed and the backend supports
   * image stamping.
   *
   * @param string[] $tokens
   *   List of non-fully qualified tokens for a given entity type. These may be
   *   image field tokens such as 'field_image' or 'field_image:thumbnail' or
   *   other tokens such as 'field_name'.
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   Fieldable entity.
   *
   * @return \Drupal\fillpdf\FieldMapping\ImageFieldMapping|null
   *   An ImageFieldMapping, or NULL if the tokens were no image field tokens.
   *
   * @throws \Drupal\Core\TypedData\Exception\MissingDataException
   */
  protected static function parseImageFieldTokens(array $tokens, FieldableEntityInterface $entity) {
    // Loop through the tokens, starting with the last one.
    foreach (array_reverse($tokens) as $token) {
      // Explode token into its field_name and property parts.
      [$field_name] = explode(':', $token, 2);

      if (!$entity->hasField($field_name)) {
        continue;
      }

      $item = $entity->get($field_name)->first();
      if (!empty($item) && $item instanceof ImageItem) {
        $value = $item->getValue();
        $file = File::load($value['target_id']);
        if ($file) {
          $uri = $file->getFileUri();
          return new ImageFieldMapping(file_get_contents($uri), NULL, $uri);
        }
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getTokenService() {
    return $this->tokenService;
  }

  /**
   * {@inheritdoc}
   */
  public function getEntityMapper() {
    return $this->tokenEntityMapper;
  }

  /**
   * Convert the base64-encoded signature image into regular image data.
   *
   * The result can be fed into an ImageFieldMapping.
   *
   * @param string $webform_element_value
   *   The value from the Webform submission, retrieved using
   *   the getElementData() method.
   *
   * @see \Drupal\fillpdf\FieldMapping\ImageFieldMapping
   *
   * @return string
   *   The signature image as a string to save to a file or stream.
   */
  public static function getSignatureImage($webform_element_value) {
    return base64_decode(preg_replace('#^data:image/\w+;base64,#i', '', $webform_element_value));
  }

}
