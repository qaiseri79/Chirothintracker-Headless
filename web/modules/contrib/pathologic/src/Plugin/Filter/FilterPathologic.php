<?php

declare(strict_types=1);

namespace Drupal\pathologic\Plugin\Filter;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\filter\FilterProcessResult;
use Drupal\filter\Plugin\FilterBase;
use Drupal\Component\Utility\Crypt;
use Drupal\Core\Form\FormStateInterface;
use Drupal\pathologic\PathologicCommonSettingsTrait;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Attempts to correct broken paths in content.
 *
 * We give the filter a weight of 50 in the annotation below because in almost
 * all cases Pathologic should be the last filter in the filter list. Is it
 * possible to put a comment inside an annotation? Man, annotations are such a
 * stupid idea.
 *
 * @Filter(
 *   id = "filter_pathologic",
 *   title = @Translation("Correct URLs with Pathologic"),
 *   type = Drupal\filter\Plugin\FilterInterface::TYPE_TRANSFORM_IRREVERSIBLE,
 *   settings = {
 *     "settings_source" = "global",
 *     "local_settings" = {
 *       "protocol_style" = "full",
 *       "local_paths" = ""
 *     }
 *   },
 *   weight = 50
 * )
 */
class FilterPathologic extends FilterBase implements ContainerFactoryPluginInterface {

  use PathologicCommonSettingsTrait;

  /**
   * The config factory service.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected ConfigFactoryInterface $configFactory;

  /**
   * {@inheritdoc}
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, ConfigFactoryInterface $configFactory) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);

    $this->configFactory = $configFactory;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): FilterPathologic {
    return new static($configuration, $plugin_id, $plugin_definition, $container->get('config.factory'));
  }

  /**
   * {@inheritdoc}
   */
  public function settingsForm(array $form, FormStateInterface $form_state) {
    $form['reminder'] = [
      '#type' => 'markup',
      '#markup' => $this->t('In most cases, Pathologic should be the <em>last</em> filter in the &ldquo;Filter processing order&rdquo; list.'),
      '#weight' => 0,
    ];
    $form['settings_source'] = [
      '#type' => 'radios',
      '#title' => $this->t('Settings source'),
      '#description' => $this->t('Select whether Pathologic should use the <a href=":config">global Pathologic settings</a> or custom &ldquo;local&rdquo; settings when filtering text in this text format.', [':config' => Url::fromRoute('pathologic.config_form')->toString()]),
      '#weight' => 10,
      '#default_value' => $this->settings['settings_source'],
      '#options' => [
        'global' => $this->t('Use global Pathologic settings'),
        'local' => $this->t('Use custom settings for this text format'),
      ],
    ];
    // Fields in fieldsets are… awkward to implement.
    // @see https://www.drupal.org/node/2378437
    $form['local_settings'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Custom settings for this text format'),
      '#weight' => 20,
      '#collapsible' => FALSE,
      '#collapsed' => FALSE,
      '#description' => $this->t('These settings are ignored if &ldquo;Use global Pathologic settings&rdquo; is selected above.'),
      // @todo Fix the #states magic (or see if it's a core D8 bug)
      '#states' => [
        'visible' => [
          ':input[name="filters[filter_pathologic][settings][settings_source]"]' => ['value' => 'local'],
        ],
      ],
    ];

    $form['local_settings'] += $this->commonPathologicSettingsForm($this->settings['local_settings']);

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function process($text, $langcode) {
    $settings = $this->settings;
    if ($settings['settings_source'] === 'global') {
      $config = $this->configFactory->get('pathologic.settings');
      $settings['protocol_style'] = $config->get('protocol_style');
      $settings['local_paths'] = $config->get('local_paths');
      $settings['keep_language_prefix'] = $config->get('keep_language_prefix');
    }
    else {
      $settings = $settings['local_settings'];
    }
    // @todo Move code from .module file to inside here.
    return new FilterProcessResult(_pathologic_filter($text, $settings, Crypt::hashBase64(serialize($settings))));
  }

}
