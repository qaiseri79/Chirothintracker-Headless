<?php

declare(strict_types=1);

namespace Drupal\Tests\pathologic\Functional;

use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\node\Traits\ContentTypeCreationTrait;
use Drupal\Tests\node\Traits\NodeCreationTrait;
use Drupal\Tests\pathologic\Traits\PathologicFormatTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;

// cspell:ignore référence français página referência português referencia

/**
 * Test multilingual integration of Pathologic functionality.
 *
 * @group pathologic
 */
class PathologicLanguageTest extends BrowserTestBase {

  use ContentTypeCreationTrait;
  use NodeCreationTrait;
  use PathologicFormatTrait;
  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'content_translation',
    'field',
    'language',
    'locale',
    'node',
    'pathologic',
    'text',
    'user',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->createContentType([
      'type' => 'page',
      'name' => 'Basic page',
    ]);

    // Add more languages.
    ConfigurableLanguage::createFromLangcode('fr')->save();
    ConfigurableLanguage::createFromLangcode('pt-br')->save();

    // Enable URL language detection and selection.
    \Drupal::configFactory()->getEditable('language.negotiation')
      ->set('url.prefixes.fr', 'fr')
      ->set('url.prefixes.pt-br', 'pt-br')
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

    $this->drupalLogin($this->drupalCreateUser(['administer filters', 'create page content']));
  }

  /**
   * Tests how links to nodes and files are handled with translations.
   */
  public function testContentTranslation(): void {

    // Make sure that the 'Keep language prefix' setting is visible.
    $this->drupalGet('admin/config/content/pathologic');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Keep language prefix');

    // Create a node that will be referenced in a link inside another node.
    $node_to_reference = $this->createNode([
      'type' => 'page',
      'title' => 'Reference page',
    ]);

    // Add translations for the reference node, and try a whole
    // series of possible input texts to see how they are handled.
    $node_to_reference->addTranslation('fr', [
      'title' => 'Page de référence en français',
    ])->save();
    $node_to_reference->addTranslation('pt-br', [
      'title' => 'Página de referência em Português',
    ])->save();

    global $base_path;
    $nid = $node_to_reference->id();

    // The link replacement shouldn't change for any of these based on the
    // language the filter runs with.
    foreach (['en', 'fr', 'pt-br'] as $langcode) {
      $this->assertSame(
        '<a href="' . $base_path . 'sites/default/files/test.png">Test file link</a>',
        $this->runFilter('<a href="/sites/default/files/test.png">Test file link</a>', $langcode),
        "$langcode: file links do not get a language prefix",
      );
      $this->assertSame(
        '<a href="' . $base_path . 'node/' . $nid . '">Test node link</a>',
        $this->runFilter('<a href="/node/' . $nid . '">Test node link</a>', $langcode),
        "$langcode: node/N link should be unchanged"
      );
      $this->assertSame(
        '<a href="' . $base_path . 'fr/node/' . $nid . '">Test node link</a>',
        $this->runFilter('<a href="/fr/node/' . $nid . '">Test node link</a>', $langcode),
        "$langcode: fr/node/N link should be unchanged",
      );
      $this->assertSame(
        '<a href="' . $base_path . 'pt-br/node/' . $nid . '">Test node link</a>',
        $this->runFilter('<a href="/pt-br/node/' . $nid . '">Test node link</a>', $langcode),
        "$langcode: pt-br/node/N link should be unchanged",
      );
      $this->assertSame(
        '<a href="' . $base_path . 'reference-en">Test node link</a>',
        $this->runFilter('<a href="/reference-en">Test node link</a>', $langcode),
        "$langcode: /reference-en link uses EN alias",
      );
      $this->assertSame(
        '<a href="' . $base_path . 'fr/reference-fr">Test node link</a>',
        $this->runFilter('<a href="/fr/reference-fr">Test node link</a>', $langcode),
        "$langcode: fr/reference-fr link uses the FR alias",
      );
      $this->assertSame(
        '<a href="' . $base_path . 'pt-br/referencia-pt">Test node link</a>',
        $this->runFilter('<a href="/pt-br/referencia-pt">Test node link</a>', $langcode),
        "$langcode: pt-br/referencia-pt uses the PT-BR alias",
      );
    }

    // Try again with language code stripping configured.
    $this->buildFormat([
      'settings_source' => 'local',
      'local_settings' => [
        'protocol_style' => 'path',
        'keep_language_prefix' => FALSE,
      ],
    ]);
    foreach (['en', 'fr', 'pt-br'] as $langcode) {
      $this->assertSame(
        '<a href="' . $base_path . 'sites/default/files/test.png">Test file link</a>',
        $this->runFilter('<a href="/sites/default/files/test.png">Test file link</a>', $langcode),
        "$langcode: file links do not get a language prefix",
      );
      $this->assertSame(
        '<a href="' . $base_path . 'node/' . $nid . '">Test node link</a>',
        $this->runFilter('<a href="/node/' . $nid . '">Test node link</a>', $langcode),
        "$langcode: node/N link is unchanged"
      );
      $this->assertSame(
        '<a href="' . $base_path . 'node/' . $nid . '">Test node link</a>',
        $this->runFilter('<a href="/fr/node/' . $nid . '">Test node link</a>', $langcode),
        "$langcode: fr/node/N link should have no langcode prefix"
      );
      $this->assertSame(
        '<a href="' . $base_path . 'node/' . $nid . '">Test node link</a>',
        $this->runFilter('<a href="/pt-br/node/' . $nid . '">Test node link</a>', $langcode),
        "$langcode: pt-br/node/N link should have no langcode prefix"
      );
      $this->assertSame(
        '<a href="' . $base_path . 'reference-en">Test node link</a>',
        $this->runFilter('<a href="/reference-en">Test node link</a>', $langcode),
        "$langcode: /reference-en link uses EN alias",
      );
      $this->assertSame(
        '<a href="' . $base_path . 'reference-fr">Test node link</a>',
        $this->runFilter('<a href="/fr/reference-fr">Test node link</a>', $langcode),
        "$langcode: fr/reference-fr link should have no langcode prefix",
      );
      $this->assertSame(
        '<a href="' . $base_path . 'referencia-pt">Test node link</a>',
        $this->runFilter('<a href="/pt-br/referencia-pt">Test node link</a>', $langcode),
        "$langcode: pt-br/referencia-pt link should have no langcode prefix",
      );
    }
  }

}
