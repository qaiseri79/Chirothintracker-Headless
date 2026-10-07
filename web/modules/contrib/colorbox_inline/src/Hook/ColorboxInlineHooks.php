<?php

namespace Drupal\colorbox_inline\Hook;

use Drupal\colorbox\ColorboxAttachment;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Hook implementations for colorbox_inline.
 */
class ColorboxInlineHooks {

  use StringTranslationTrait;

  /**
   * Constructs a new ColorboxInlineHooks object.
   *
   * @param \Drupal\colorbox\ColorboxAttachment $colorboxAttachment
   *   The colorbox attachment service.
   */
  public function __construct(
    private readonly ColorboxAttachment $colorboxAttachment,
  ) {}

  /**
   * Implements hook_page_attachments().
   */
  #[Hook('page_attachments')]
  public function pageAttachments(array &$page): void {
    $this->colorboxAttachment->attach($page);
    $page['#attached']['library'][] = 'colorbox_inline/colorbox_inline';
  }

  /**
   * Implements hook_help().
   */
  #[Hook('help')]
  public function help($route_name, RouteMatchInterface $route_match): string|\Stringable|array|null {
    switch ($route_name) {
      case 'help.page.colorbox_inline':
        return (string) $this->t('<p>The Colorbox Inline module allows you to open content already on the page within a colorbox.</p><p>See the <a href=":project_page">project page on Drupal.org</a> for more details.</p>', [
          ':project_page' => 'https://www.drupal.org/project/colorbox_inline',
        ]);
    }
    return NULL;
  }

}
