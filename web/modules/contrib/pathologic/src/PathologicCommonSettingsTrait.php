<?php

declare(strict_types=1);

namespace Drupal\pathologic;

use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Generates form elements shared across Pathologic settings forms.
 */
trait PathologicCommonSettingsTrait {

  use StringTranslationTrait;

  /**
   * The module handler service.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface|null
   */
  protected $moduleHandler = NULL;

  /**
   * Common elements for Pathologic configuration forms.
   *
   * This reduces redundancy in code for form elements that will appear on both
   * the global settings form and the per-format filter settings form.
   *
   * @param array $defaults
   *   An array of default values for the configuration form fields.
   *
   * @return array
   *   The common form elements.
   */
  protected function commonPathologicSettingsForm(array $defaults) {
    $form = [
      'protocol_style' => [
        '#type' => 'radios',
        '#title' => $this->t('Processed URL format'),
        '#default_value' => $defaults['protocol_style'],
        '#options' => [
          'full' => $this->t('Full URL (<code>http://example.com/foo/bar</code>)'),
          'proto-rel' => $this->t('Protocol relative URL (<code>//example.com/foo/bar</code>)'),
          'path' => $this->t('Path relative to server root (<code>/foo/bar</code>)'),
        ],
        '#description' => $this->t('The <em>Full URL</em> option is best for stopping broken images and links in syndicated content (such as in RSS feeds), but will likely lead to problems if your site is accessible by both HTTP and HTTPS. Paths output with the <em>Protocol relative URL</em> option will avoid such problems, but feed readers and other software not using up-to-date standards may be confused by the paths. The <em>Path relative to server root</em> option will avoid problems with sites accessible by both HTTP and HTTPS with no compatibility concerns, but will absolutely not fix broken images and links in syndicated content.'),
        '#weight' => 10,
      ],
      'local_paths' => [
        '#type' => 'textarea',
        '#title' => $this->t('All base paths for this site'),
        '#default_value' => $defaults['local_paths'],
        '#description' => $this->t('If this site is or was available at more than one base path or URL, enter them here, separated by line breaks. For example, if this site is live at <code>http://example.com/</code> but has a staging version at <code>http://dev.example.org/staging/</code>, you would enter both those URLs here. If confused, please read <a href=":docs" target="_blank">Pathologic&rsquo;s documentation</a> for more information about this option and what it affects.', [':docs' => 'https://www.drupal.org/node/257026']),
        '#weight' => 20,
      ],
    ];
    if ($this->getModuleHandler()->moduleExists('language')) {
      $form['keep_language_prefix'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Keep language prefix'),
        '#description' => $this->t('Controls whether to render URL language prefixes, such as the %language in %url. When this setting is not checked, language prefixes will be removed.', [
          '%language' => '/fr',
          '%url' => '/fr/node/3',
        ]),
        '#default_value' => $defaults['keep_language_prefix'] ?? TRUE,
        '#weight' => 20,
      ];
    }
    return $form;
  }

  /**
   * Gets the module handler service.
   *
   * @return \Drupal\Core\Extension\ModuleHandlerInterface
   *   The module handler service.
   */
  protected function getModuleHandler() {
    if (!$this->moduleHandler) {
      $this->moduleHandler = \Drupal::service('module_handler');
    }
    return $this->moduleHandler;
  }

  /**
   * Sets the module handler service to use.
   *
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler service.
   *
   * @return $this
   */
  public function setModuleHandler(ModuleHandlerInterface $module_handler) {
    $this->moduleHandler = $module_handler;
    return $this;
  }

}
