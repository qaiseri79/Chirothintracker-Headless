<?php

namespace Drupal\Tests\entity_redirect\Functional;

use Drupal\Tests\BrowserTestBase;

/**
 * Provide basic setup for all color field functional tests.
 *
 * @group color_field
 */
class EntityRedirectTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'node',
    'entity_redirect',
    'layout_builder',
    'layout_discovery',
  ];

  /**
   * The node type.
   *
   * @var \Drupal\node\Entity\NodeType
   */
  protected $nodeType;

  /**
   * The entity redirect settings.
   *
   * @var array
   */
  protected $settings;

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected function setUp() :void {
    parent::setUp();

    $this->nodeType = $this->drupalCreateContentType(['type' => 'article']);
    $this->settings = [
      'edit' => [
        'active' => TRUE,
        'destination' => 'url',
        'url' => '/user/2',
      ],
      'add' => [
        'active' => FALSE,
        'destination' => 'external',
        'external' => 'https://google.ca',
        'url' => '/user/2/',
      ],
    ];
    $this->nodeType->setThirdPartySetting('entity_redirect',
      'redirect',
      $this->settings
    )->save();
    $this->drupalLogin($this->drupalCreateUser([
      'create article content',
      'edit own article content',
    ]));
  }

  /**
   * Testing redirect routes.
   */
  public function testBasicRedirect() {
    $edit = [
      'title[0][value]' => $this->randomMachineName(),
    ];
    $this->drupalGet('/node/add/article');
    $this->submitForm($edit, 'Save');
    $session = $this->assertSession();
    $session->addressEquals('/node/1');
    $session->statusCodeEquals(200);

    $this->drupalGet('/node/1/edit');
    $this->submitForm($edit, 'Save');
    $session->addressEquals('/user/2');
    $session->statusCodeEquals(200);

    $this->settings['add']['active'] = TRUE;
    $this->nodeType->setThirdPartySetting('entity_redirect',
      'redirect',
      $this->settings
    )->save();
    $this->drupalGet('/node/add/article/');
    $this->submitForm($edit, 'Save');
    $this->assertEquals('https://www.google.ca/', $this->getUrl());

    $this->settings['edit']['destination'] = 'add_form';
    $this->nodeType->setThirdPartySetting('entity_redirect',
      'redirect',
      $this->settings
    )->save();
    $this->drupalGet('/node/2/edit');
    $this->submitForm($edit, 'Save');
    $session->addressEquals('/node/add/article');
    $session->statusCodeEquals(200);

    $this->settings['edit']['destination'] = 'created';
    $this->nodeType->setThirdPartySetting('entity_redirect',
      'redirect',
      $this->settings
    )->save();
    $this->drupalGet('/node/2/edit');
    $this->submitForm($edit, 'Save');
    $session->addressEquals('/node/2');
    $session->statusCodeEquals(200);

    $this->settings['edit']['destination'] = 'edit_form';
    $this->nodeType->setThirdPartySetting('entity_redirect',
      'redirect',
      $this->settings
    )->save();
    $this->drupalGet('/node/2/edit');
    $this->submitForm($edit, 'Save');
    $session->addressEquals('/node/2/edit');
    $session->statusCodeEquals(200);

    $this->settings['edit']['destination'] = 'previous_page';
    $this->nodeType->setThirdPartySetting('entity_redirect',
      'redirect',
      $this->settings
    )->save();
    $this->drupalGet('/node/2/edit');
    $this->submitForm($edit, 'Save');
    $this->assertStringContainsString('/node/2/edit', $this->getSession()->getCurrentUrl());
    $session->statusCodeEquals(200);

    \Drupal::entityTypeManager()
      ->getStorage('entity_view_display')
      ->create([
        'targetEntityType' => 'node',
        'bundle' => 'article',
        'mode' => 'full',
      ])
      ->enable()
      ->setThirdPartySetting('layout_builder', 'enabled', TRUE)
      ->setThirdPartySetting('layout_builder', 'allow_custom', TRUE)
      ->save();
    $settings = [
      'add' => [
        'active' => TRUE,
        'destination' => 'layout_builder',
      ],
    ];
    $this->nodeType->setThirdPartySetting('entity_redirect',
      'redirect',
      $settings
    )->save();
    $this->drupalLogin($this->drupalCreateUser([
      'configure any layout',
      'create article content',
    ]));
    $this->drupalGet('/node/add/article');
    $this->submitForm($edit, 'Save');
    $session = $this->assertSession();
    $this->assertStringContainsString('/node/3/layout', $this->getSession()->getCurrentUrl());
    $session->statusCodeEquals(200);

  }

}
