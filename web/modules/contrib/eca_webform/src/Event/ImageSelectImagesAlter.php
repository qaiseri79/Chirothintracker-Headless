<?php

namespace Drupal\eca_webform\Event;

/**
 * Provides image select images alter event for eca_webform.
 *
 * @package Drupal\eca_webform\Event
 */
class ImageSelectImagesAlter extends WebformBaseEvent {

  /**
   * An associative array of images.
   *
   * @var array
   */
  protected array $images;

  /**
   * The webform element that the images is for.
   *
   * @var array
   */
  protected array $element;

  /**
   * The webform image select images id. Set to NULL if the images are custom.
   *
   * @var string|null
   */
  protected ?string $imagesId;

  /**
   * Constructs the ImageSelectImagesAlter event.
   *
   * @param array $images
   *   An associative array of images.
   * @param array $element
   *   The webform element that the images is for.
   * @param string|null $images_id
   *   The webform image select images id. Set to NULL if the images are custom.
   */
  public function __construct(array &$images, array &$element, string $images_id = NULL) {
    $this->images = &$images;
    $this->element = &$element;
    $this->imagesId = $images_id;
  }

  /**
   * An associative array of images.
   *
   * @return array
   *   An associative array of images.
   */
  public function &getImages(): array {
    return $this->images;
  }

  /**
   * The webform element that the images is for.
   *
   * @return array
   *   The webform element that the images is for.
   */
  public function &getElement(): array {
    return $this->element;
  }

  /**
   * The webform image select images id. Set to NULL if the images are custom.
   *
   * @return string|null
   *   The webform image select images id. Set to NULL if the images are custom.
   */
  public function getImagesId(): ?string {
    return $this->imagesId;
  }

}
