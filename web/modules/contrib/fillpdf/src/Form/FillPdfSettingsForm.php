<?php

namespace Drupal\fillpdf\Form;

use Drupal\Component\Render\FormattableMarkup;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Link;
use Drupal\Core\Render\Element\Select;
use Drupal\Core\Render\RendererInterface;
use Drupal\fillpdf\Component\Utility\FillPdf;
use Drupal\fillpdf\Entity\FillPdfForm;
use Drupal\fillpdf\Plugin\PdfBackendManager;
use Drupal\fillpdf\Service\FillPdfAdminFormHelper;
use Drupal\fillpdf\ShellManager;
use GuzzleHttp\Client;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configure FillPDF settings form.
 */
class FillPdfSettingsForm extends ConfigFormBase {

  /**
   * The file system service.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected $fileSystem;

  /**
   * Definitions of all backend plugins.
   *
   * @var array[]
   *   Associative array of all backend plugin definitions, keyed by plugin ID
   *   and sorted by weight.
   */
  protected $definitions = [];

  /**
   * The FillPDF admin form helper service.
   *
   * @var \Drupal\fillpdf\Service\FillPdfAdminFormHelper
   */
  protected $adminFormHelper;

  /**
   * The Guzzle HTTP client service.
   *
   * @var \GuzzleHttp\Client
   */
  protected $httpClient;

  /**
   * The FillPDF shell manager.
   *
   * @var \Drupal\fillpdf\ShellManager
   */
  protected $shellManager;

  /**
   * The Renderer service.
   *
   * @var \Drupal\Core\Render\RendererInterface
   */
  protected $renderer;

  /**
   * Constructs a FillPdfSettingsForm object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The factory for configuration objects.
   * @param \Drupal\Core\Config\TypedConfigManagerInterface $typedConfigManager
   *   The typed config manager.
   * @param \Drupal\Core\File\FileSystemInterface $file_system
   *   Helpers to operate on files and stream wrappers.
   * @param \Drupal\fillpdf\Service\FillPdfAdminFormHelper $admin_form_helper
   *   The FillPDF admin form helper service.
   * @param \GuzzleHttp\Client $http_client
   *   The Guzzle HTTP client service.
   * @param \Drupal\fillpdf\ShellManager $shell_manager
   *   The FillPDF shell manager.
   * @param \Drupal\fillpdf\Plugin\PdfBackendManager $backend_manager
   *   The FillPDF backend manager.
   * @param \Drupal\Core\Render\RendererInterface $renderer
   *   The Renderer service.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    TypedConfigManagerInterface $typedConfigManager,
    FileSystemInterface $file_system,
    FillPdfAdminFormHelper $admin_form_helper,
    Client $http_client,
    ShellManager $shell_manager,
    PdfBackendManager $backend_manager,
    RendererInterface $renderer,
  ) {
    parent::__construct($config_factory, $typedConfigManager);

    $this->fileSystem = $file_system;
    $this->adminFormHelper = $admin_form_helper;
    $this->httpClient = $http_client;
    $this->shellManager = $shell_manager;
    $this->definitions = $backend_manager->getDefinitions();
    $this->renderer = $renderer;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('config.typed'),
      $container->get('file_system'),
      $container->get('fillpdf.admin_form_helper'),
      $container->get('http_client'),
      $container->get('fillpdf.shell_manager'),
      $container->get('plugin.manager.fillpdf.pdf_backend'),
      $container->get('renderer')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'fillpdf_settings';
  }

  /**
   * Gets the configuration names that will be editable.
   *
   * @return array
   *   An array of configuration object names that are editable if called in
   *   conjunction with the trait's config() method.
   */
  protected function getEditableConfigNames() {
    return ['fillpdf.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildForm($form, $form_state);

    $config = $this->config('fillpdf.settings');

    // Get available scheme options.
    $scheme_options = $this->adminFormHelper->schemeOptions([
      'public' => $this->t('@scheme (discouraged)'),
      'private' => $this->t('@scheme (recommended)'),
    ]);
    $form['allowed_schemes'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Allowed file storages'),
      '#default_value' => array_intersect(array_keys($scheme_options), $config->get('allowed_schemes')),
      '#options' => $scheme_options,
      '#description' => $this->t("You may choose one or more file storages to be available for storing generated PDF files with actual entity data; note that %public does not provide any access control.<br />If you don't choose any file storage, generated PDFs may only be sent to the browser instead of being stored.", [
        '%public' => $this->t('Public files'),
      ]),
    ];

    $form['advanced_storage'] = [
      '#type' => 'details',
      '#title' => $this->t('Advanced storage settings'),
    ];
    $file_default_scheme = $this->config('system.file')->get('default_scheme');
    $template_scheme_options = $this->adminFormHelper->schemeOptions([
      $file_default_scheme => $this->t('@scheme (site default)'),
    ]);
    $template_scheme = $config->get('template_scheme');
    // Set an error if the previously configured scheme doesn't exist anymore.
    if ($template_scheme && !array_key_exists($template_scheme, $template_scheme_options)) {
      $this->messenger()->addError($this->t('Your previously used file storage %previous_scheme is no longer available on this Drupal site, see the %system_settings. Please reset your default to an existing file storage.', [
        '%previous_scheme' => $template_scheme . '://',
        '%system_settings' => Link::createFromRoute($this->t('File system settings'), 'system.file_system_settings')->toString(),
      ]));

      // @todo It would be helpful if we could use EntityQuery instead, see
      // https://www.drupal.org/project/fillpdf/issues/3043508.
      $map = $this->adminFormHelper->getFormsByTemplateScheme($template_scheme);
      if ($count = count($map)) {
        $forms = FillPdfForm::loadMultiple(array_keys($map));
        $items = [];
        foreach ($map as $form_id => $file_uri) {
          $fillpdf_form = $forms[$form_id];
          $admin_title = current($fillpdf_form->get('admin_title')->getValue());
          // @todo We can simplify this once an admin_title is #required,
          // see https://www.drupal.org/project/fillpdf/issues/3040776.
          $link = Link::fromTextAndUrl($admin_title ?: "FillPDF form {$fillpdf_form->id()}", $fillpdf_form->toUrl());
          $items[$form_id] = new FormattableMarkup("@fillpdf_form: {$file_uri}", ['@fillpdf_form' => $link->toString()]);
        }
        $error_message = [
          '#prefix' => $this->t('Nevertheless, the following FillPDF forms will not work until their respective PDF templates have been moved to an existing file scheme:'),
          [
            '#theme' => 'item_list',
            '#items' => $items,
          ],
        ];
        $this->messenger()->addError($this->renderer->renderInIsolation($error_message));
      }

      $this->logger('fillpdf')->critical('File storage %previous_scheme is no longer available. %count FillPDF forms are defunct.', [
        '%previous_scheme' => $template_scheme . '://',
        '%count' => $count,
      ]);
    }

    $form['advanced_storage']['template_scheme'] = [
      '#type' => 'radios',
      '#title' => $this->t('Template storage'),
      '#default_value' => array_key_exists($template_scheme, $template_scheme_options) ? $template_scheme : $file_default_scheme,
      '#options' => $template_scheme_options,
      '#description' => $this->t('This setting is used as the storage for uploaded templates; note that the use of %public is more efficient, but does not provide any access control.<br />Changing this setting will require you to migrate associated files and data yourself and is not recommended after you have uploaded a template.', [
        '%public' => $this->t('Public files'),
      ]),
    ];

    $form['backend'] = [
      '#type' => 'radios',
      '#title' => $this->t('PDF-filling service'),
      '#description' => $this->t('This module requires the use of one of several external PDF manipulation tools. Choose the service you would like to use.'),
      '#default_value' => $config->get('backend'),
      '#options' => [],
    ];

    foreach ($this->definitions as $id => $definition) {
      // Add a radio option for every backend plugin.
      $label = $definition['label'];
      $description = $definition['description'];
      $form['backend']['#options'][$id] = ("<strong>{$label}</strong>") . ($description ? ": {$description}" : '');

    }

    $form['fillpdf_service'] = [
      '#type' => 'details',
      '#title' => $this->t('Configure %label', ['%label' => $this->definitions['fillpdf_service']['label']]),
      '#open' => TRUE,
      '#states' => [
        'visible' => [
          ':input[name="backend"]' => ['value' => 'fillpdf_service'],
        ],
      ],
    ];
    $form['fillpdf_service']['remote_endpoint'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Server endpoint'),
      '#default_value' => $config->get('remote_endpoint'),
      '#description' => $this->t('The endpoint for the FillPDF Service instance. Do not include the protocol, as this is determined by the <em>Use HTTPS?</em> setting below.'),
      '#states' => [
        'required' => [
          ':input[name="backend"]' => ['value' => 'fillpdf_service'],
        ],
      ],
    ];
    $form['fillpdf_service']['fillpdf_service_api_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('API Key'),
      '#default_value' => $config->get('fillpdf_service_api_key'),
      '#description' => $this->t('You need to get an API key from your service.'),
      '#states' => [
        'required' => [
          ':input[name="backend"]' => ['value' => 'fillpdf_service'],
        ],
      ],
    ];
    $form['fillpdf_service']['remote_protocol'] = [
      '#type' => 'radios',
      '#title' => $this->t('Use HTTPS?'),
      '#description' => $this->t('It is recommended to select <em>Use HTTPS</em> for this option. Doing so will help prevent
      sensitive information in your PDFs from being intercepted in transit between your server and the remote service.'),
      '#default_value' => $config->get('remote_protocol'),
      '#options' => [
        'https' => $this->t('Use HTTPS'),
        'http' => $this->t('Do not use HTTPS'),
      ],
    ];

    $form['local_server'] = [
      '#type' => 'details',
      '#title' => $this->t('Configure %label', ['%label' => $this->definitions['local_server']['label']]),
      '#open' => TRUE,
      '#states' => [
        'visible' => [
          ':input[name="backend"]' => ['value' => 'local_server'],
        ],
      ],
    ];
    $form['local_service_endpoint'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Configure FillPdf LocalServer endpoint (address)'),
      '#default_value' => $config->get('local_service_endpoint'),
      '#description' => $this->t("Enter the network address of your FillPDF LocalServer installation. If you are running the Docker container on port 8085 locally, then the address is <em>http://127.0.0.1:8085</em>."),
      '#group' => 'local_server',
      '#states' => [
        'required' => [
          ':input[name="backend"]' => ['value' => 'local_server'],
        ],
      ],
    ];

    $form['pdftk'] = [
      '#type' => 'details',
      '#title' => $this->t('Configure %label', ['%label' => $this->definitions['pdftk']['label']]),
      '#open' => TRUE,
      '#states' => [
        'visible' => [
          ':input[name="backend"]' => ['value' => 'pdftk'],
        ],
      ],
    ];
    $form['pdftk_path'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Configure path to pdftk'),
      '#description' => $this->t("If FillPDF is not detecting your pdftk installation, you can specify the full path to the program here. Include the program name as well. On many systems, <em>/usr/bin/pdftk</em> is a valid value. You can almost always leave this field blank. If you should set it, you'll probably know."),
      '#default_value' => $config->get('pdftk_path') ?: 'pdftk',
      '#group' => 'pdftk',
    ];

    $form['shell_locale'] = [
      '#title' => $this->t('Server locale'),
      '#group' => 'pdftk',
    ];
    if ($this->shellManager->isWindows()) {
      $form['shell_locale'] += [
        '#type' => 'textfield',
        '#description' => $this->t("The locale to be used to prepare the command passed to executables. The default, <kbd>'@default'</kbd>, should work in most cases. If that is not available on the server, @op.", [
          '@default' => '',
          '@op' => $this->t('enter another locale'),
        ]),
        '#default_value' => $config->get('shell_locale') ?: '',
      ];
    }
    else {
      $locales = $this->shellManager->getInstalledLocales();
      // Locale names are unfortunately not standardized. 'locale -a' will give
      // 'en_US.UTF-8' on Mac OS systems, 'en_US.utf8' on most/all Unix systems.
      $default = isset($locales['en_US.UTF-8']) ? 'en_US.UTF-8' : 'en_US.utf8';
      $form['shell_locale'] += [
        '#type' => 'select',
        '#description' => $this->t("The locale to be used to prepare the command passed to executables. The default, <kbd>'@default'</kbd>, should work in most cases. If that is not available on the server, @op.", [
          '@default' => $default,
          '@op' => $this->t('choose another locale'),
        ]),
        '#options' => $locales,
        '#default_value' => $config->get('shell_locale') ?: 'en_US.utf8',
      ];
      // @todo We're working around Core issue #2190333, resp. #2854166.
      // Remove once one of these landed.
      // @see https://www.drupal.org/project/drupal/issues/2854166
      $form['shell_locale']['#process'][] = [
        Select::class,
        'processGroup',
      ];
      $form['shell_locale']['#pre_render'][] = [
        Select::class,
        'preRenderGroup',
      ];
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $values = $form_state->getValues();

    switch ($values['backend']) {
      case 'fillpdf_service':
        // @todo Add validation for FillPDF Service.
        // @see https://www.drupal.org/project/fillpdf/issues/3040899
        break;

      case 'local_server':
        // Set the form_state value to the Config object without saving.
        $config = $this->config('fillpdf.settings')->set('local_service_endpoint', $values['local_service_endpoint']);
        // Check for FillPDF LocalServer.
        $status = FillPdf::checkLocalServiceEndpoint($this->httpClient, $config);
        if ($status === FALSE) {
          $error_message = $this->t('FillPDF LocalService is not properly installed. Was unable to contact %endpoint', [
            '%endpoint' => $values['local_service_endpoint'],
          ]);
          $form_state->setErrorByName('local_service_endpoint', $error_message);
        }
        break;

      case 'pdftk':
        // Check for pdftk.
        $status = FillPdf::checkPdftkPath($values['pdftk_path']);
        if ($status === FALSE) {
          $error_message = $this->t('The path you have entered for <em>pdftk</em> is invalid. Please enter a valid path.');
          $form_state->setErrorByName('pdftk_path', $error_message);
        }
        break;
    }

    $template_scheme = $values['template_scheme'];
    $schemes_to_prepare = array_filter($values['allowed_schemes']) + [$template_scheme => $template_scheme];
    foreach ($schemes_to_prepare as $scheme) {
      $uri = FillPdf::buildFileUri($scheme, 'fillpdf');
      if (!$this->fileSystem->prepareDirectory($uri, FileSystemInterface::CREATE_DIRECTORY + FileSystemInterface::MODIFY_PERMISSIONS)) {
        $error_message = $this->t('Could not automatically create the subdirectory %directory. Please check permissions before trying again.', [
          '%directory' => $this->fileSystem->realpath($uri),
        ]);
        $form_state->setErrorByName('template_scheme', $error_message);
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // Save form values.
    $values = $form_state->getValues();
    $config = $this->config('fillpdf.settings');

    $config->set('allowed_schemes', array_keys(array_filter($values['allowed_schemes'])))
      ->set('template_scheme', $values['template_scheme'])
      ->set('backend', $values['backend']);

    switch ($values['backend']) {
      case 'fillpdf_service':
        $config->set('remote_endpoint', $values['remote_endpoint'])
          ->set('fillpdf_service_api_key', $values['fillpdf_service_api_key'])
          ->set('remote_protocol', $values['remote_protocol']);
        break;

      case 'local_server':
        $config->set('local_service_endpoint', $values['local_service_endpoint']);
        break;

      case 'pdftk':
        $config->set('pdftk_path', $form_state->getValue('pdftk_path'))
          ->set('shell_locale', $values['shell_locale']);
        break;
    }

    $config->save();

    parent::submitForm($form, $form_state);
  }

}
