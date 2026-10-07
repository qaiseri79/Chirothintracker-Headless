<?php

declare(strict_types=1);

namespace Drupal\Tests\pathologic\Kernel;

use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\Tests\node\Traits\ContentTypeCreationTrait;
use Drupal\Tests\node\Traits\NodeCreationTrait;

/**
 * Test multilingual integration of Pathologic functionality.
 *
 * @group pathologic
 */
class PathologicLanguageTest extends PathologicKernelTestBase {

  use ContentTypeCreationTrait;
  use NodeCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'content_translation',
    'field',
    'language',
    'locale',
    'node',
    'text',
    'user',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('node');
    $this->installEntitySchema('user');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['node']);

    $this->createContentType([
      'type' => 'page',
      'name' => 'Basic page',
    ]);

    // Add a second language.
    ConfigurableLanguage::createFromLangcode('fr')->save();

    // Enable URL language detection and selection.
    \Drupal::configFactory()->getEditable('language.negotiation')
      ->set('url.prefixes.fr', 'fr')
      ->save();

    // Configure Pathologic on a text format.
    $this->buildFormat([
      'settings_source' => 'local',
      'local_settings' => [
        'protocol_style' => 'path',
        'keep_language_prefix' => TRUE,
      ],
    ]);

    // To reflect the changes for a multilingual site, rebuild the container.
    $this->container->get('kernel')->rebuildContainer();
  }

  /**
   * Pathologic should not prefix URLs in content translations.
   */
  public function testContentTranslation(): void {
    $node_to_reference = $this->createNode([
      'type' => 'page',
      'title' => 'Reference node',
    ]);
    // Create a default-language node with a node link and a file link.
    $nodeValues = [
      'type' => 'page',
      'title' => 'Lost in translation',
      'body' => [
        'value' => '<a href="/node/' . $node_to_reference->id() . '">Test node link</a><a href="/sites/default/files/test.png">Test file link</a>',
        'format' => $this->formatId,
      ],
    ];
    $default_language_node = $this->createNode($nodeValues);

    // Verify the text field formatter's render array.
    $build = $default_language_node->get('body')->view();
    \Drupal::service('renderer')->renderRoot($build[0]);

    // Links on the default language node should not contain a language prefix.
    $this->assertSame(
      $nodeValues['body']['value'],
      (string) $build[0]['#markup']
    );

    // Create a translation of the node, same content.
    $default_language_node->addTranslation('fr', $nodeValues)->save();
    $fr_node = $default_language_node->getTranslation('fr');
    $build = $fr_node->get('body')->view();
    \Drupal::service('renderer')->renderRoot($build[0]);

    // Links on the translation also should *not* contain a language prefix.
    $this->assertSame(
      $nodeValues['body']['value'],
      (string) $build[0]['#markup']
    );
  }

}
