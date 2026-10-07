<?php

namespace Drupal\fillpdf\Form;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Link;
use Drupal\Core\Render\RendererInterface;
use Drupal\fillpdf\Component\Utility\FillPdf;
use Drupal\fillpdf\FillPdfAdminFormHelperInterface;
use Drupal\fillpdf\FillPdfLinkManipulatorInterface;
use Drupal\fillpdf\InputHelperInterface;
use Drupal\fillpdf\SerializerInterface;
use Drupal\fillpdf\TokenResolverInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form controller for the FillPDFForm edit form.
 */
class FillPdfFormForm extends ContentEntityForm {

  /**
   * Maximum number of entities to be listed in a select.
   */
  const SELECT_MAX = 25;

  /**
   * The FillPdf admin form helper.
   *
   * @var \Drupal\fillpdf\FillPdfAdminFormHelperInterface
   */
  protected $adminFormHelper;

  /**
   * The FillPdf link manipulator.
   *
   * @var \Drupal\fillpdf\FillPdfLinkManipulatorInterface
   */
  protected $linkManipulator;

  /**
   * The FillPdf link manipulator.
   *
   * @var \Drupal\fillpdf\InputHelperInterface
   */
  protected $inputHelper;

  /**
   * The module handler service.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * The FillPdf serializer.
   *
   * @var \Drupal\fillpdf\SerializerInterface
   */
  protected $serializer;

  /**
   * The file system service.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected $fileSystem;

  /**
   * The FillPDF token resolver.
   *
   * @var \Drupal\fillpdf\TokenResolverInterface
   */
  protected $tokenResolver;

  /**
   * The Renderer service.
   *
   * @var \Drupal\Core\Render\RendererInterface
   */
  protected $renderer;

  /**
   * {@inheritdoc}
   */
  public function __construct(
    EntityRepositoryInterface $entity_repository,
    FillPdfAdminFormHelperInterface $admin_form_helper,
    FillPdfLinkManipulatorInterface $link_manipulator,
    InputHelperInterface $input_helper,
    SerializerInterface $fillpdf_serializer,
    FileSystemInterface $file_system,
    EntityTypeManagerInterface $entity_type_manager,
    TokenResolverInterface $token_resolver,
    EntityTypeBundleInfoInterface $entity_type_bundle_info,
    RendererInterface $renderer,
    TimeInterface $time,
    ModuleHandlerInterface $module_handler,
  ) {
    parent::__construct(
      $entity_repository,
      $entity_type_bundle_info,
      $time
    );
    $this->adminFormHelper = $admin_form_helper;
    $this->linkManipulator = $link_manipulator;
    $this->inputHelper = $input_helper;
    $this->serializer = $fillpdf_serializer;
    $this->fileSystem = $file_system;
    $this->entityTypeManager = $entity_type_manager;
    $this->renderer = $renderer;
    $this->tokenResolver = $token_resolver;
    $this->moduleHandler = $module_handler;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity.repository'),
      $container->get('fillpdf.admin_form_helper'),
      $container->get('fillpdf.link_manipulator'),
      $container->get('fillpdf.input_helper'),
      $container->get('fillpdf.serializer'),
      $container->get('file_system'),
      $container->get('entity_type.manager'),
      $container->get('fillpdf.token_resolver'),
      $container->get('entity_type.bundle.info'),
      $container->get('renderer'),
      $container->get('datetime.time'),
      $container->get('module_handler')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state) {
    // Create hook_fillpdf_form_form_pre_form_build_alter().
    $this->moduleHandler->alter('fillpdf_form_form_pre_form_build', $this);

    $form = parent::form($form, $form_state);

    /** @var \Drupal\fillpdf\FillPdfFormInterface $fillpdf_form */
    $fillpdf_form = $this->entity;

    $existing_fields = $fillpdf_form->getFormFields();

    if (!count($existing_fields)) {
      $fillpdf_form_fields_empty_message = $this->t('PDF does not contain fillable fields.');
      $this->messenger()->addWarning($fillpdf_form_fields_empty_message);
    }

    $form['title']['token_tree'] = $this->adminFormHelper->getAdminTokenForm();

    $default_entity_type = $fillpdf_form->getDefaultEntityType();

    $form['default_entity_type'] = [
      '#type' => 'select',
      '#title' => $this->t('Default entity type'),
      '#options' => $this->getEntityTypeOptions(),
      '#empty_option' => $this->t('- None -'),
      '#weight' => 12.5,
      '#default_value' => $default_entity_type,
      '#ajax' => [
        'callback' => '::ajaxUpdateEntityId',
        'event' => 'change',
        'wrapper' => 'test-entity-wrapper',
        'progress' => ['type' => 'none'],
      ],
    ];

    // On AJAX-triggered rebuild, work with the user input instead of previously
    // stored values.
    if ($form_state->isRebuilding()) {
      $default_entity_type = $form_state->getValue('default_entity_type');
      $default_entity_id = $form_state->getValue('default_entity_id');
    }
    else {
      $stored_default_entity_id = $fillpdf_form->get('default_entity_id');
      $default_entity_id = count($stored_default_entity_id) ? $stored_default_entity_id->first()->value : NULL;
    }

    $form['default_entity_id'] = [
      '#title' => $this->t('Default entity'),
      '#target_type' => $default_entity_type,
      '#weight' => 13,
      '#prefix' => '<div id="test-entity-wrapper">',
      '#suffix' => '</div>',
      '#ajax' => [
        'callback' => '::ajaxUpdateEntityId',
        'event' => 'autocompleteclose autocompletechange',
        'wrapper' => 'test-entity-wrapper',
        'progress' => ['type' => 'none'],
      ],
    ];

    // If a default entity type is set, allow selecting a default entity, too.
    if ($default_entity_type) {
      $storage = $this->entityTypeManager->getStorage($default_entity_type);

      $default_entity = $default_entity_id ? $storage->load($default_entity_id) : NULL;
      if (!empty($default_entity)) {
        $description = Link::fromTextAndUrl(
          $this->t('Download this PDF template populated with data from the @type %label (@id).', [
            '@type' => $default_entity_type,
            '%label' => $default_entity->label(),
            '@id' => $default_entity_id,
          ]),
          $this->linkManipulator->generateLink([
            'fid' => $this->entity->id(),
            'entity_ids' => [$default_entity_type => [$default_entity_id]],
          ])
        )->toString();
      }

      $entity_ids = $storage->getQuery()->accessCheck(TRUE)->range(0, self::SELECT_MAX + 1)->execute();
      if (count($entity_ids) > self::SELECT_MAX) {
        if (!isset($description)) {
          $description = $this->t('Enter the title of a %type to test populating the PDF template.', [
            '%type' => $default_entity_type,
          ]);
        }
        $form['default_entity_id'] += [
          '#type' => 'entity_autocomplete',
          '#default_value' => $default_entity,
          '#description' => $description,
        ];
      }
      else {
        $options = [];
        foreach ($storage->loadMultiple($entity_ids) as $id => $entity) {
          $options[$id] = $entity->label();
        }
        if (!isset($description)) {
          $description = $this->t('Choose a %type to test populating the PDF template.', [
            '%type' => $default_entity_type,
          ]);
        }
        $form['default_entity_id'] += [
          '#type' => 'select',
          '#options' => $options,
          '#empty_option' => $this->t('- None -'),
          '#default_value' => $default_entity_id,
          '#description' => $description,
        ];
      }
    }
    // No default entity type set, so just provide a wrapper for AJAX replace.
    else {
      $form['default_entity_id'] += [
        '#type' => 'hidden',
      ];
    }

    $fid = $fillpdf_form->id();

    /** @var \Drupal\file\FileInterface $file_entity */
    $file_entity = $this->entityTypeManager->getStorage('file')->load($fillpdf_form->get('file')->first()->target_id);
    $pdf_info_weight = 0;
    $form['pdf_info'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('PDF form information'),
      '#weight' => $form['default_entity_type']['#weight'] + 1,
      'submitted_pdf' => [
        '#type' => 'item',
        '#title' => $this->t('Uploaded PDF'),
        '#description' => $file_entity->getFileUri(),
        '#weight' => $pdf_info_weight++,
      ],
    ];

    $upload_location = FillPdf::buildFileUri($this->config('fillpdf.settings')->get('template_scheme'), 'fillpdf');
    if (!$this->fileSystem->prepareDirectory($upload_location, FileSystemInterface::CREATE_DIRECTORY + FileSystemInterface::MODIFY_PERMISSIONS)) {
      $this->messenger()->addError($this->t('The directory %directory does not exist or is not writable. Please check permissions.', [
        '%directory' => $this->fileSystem->realpath($upload_location),
      ]));
    }
    else {
      $form['pdf_info']['upload_pdf'] = [
        '#type' => 'managed_file',
        '#title' => $this->t('Update PDF template'),
        '#accept' => 'application/pdf',
        '#upload_validators' => [
          'FileExtension' => ['extensions' => 'pdf'],
        ],
        '#upload_location' => $upload_location,
        '#description' => $this->t('Update the PDF file used as template by this form.'),
        '#weight' => $pdf_info_weight++,
      ];
    }

    $form['pdf_info']['sample_populate'] = [
      '#type' => 'item',
      '#title' => $this->t('Sample PDF'),
      '#description' => $this->t('@link<br />If you have set a custom path on this PDF, the sample will be saved there silently.', [
        '@link' => Link::fromTextAndUrl(
        $this->t('See which fields are which in this PDF.'),
        $this->linkManipulator->generateLink([
          'fid' => $fid,
          'sample' => TRUE,
        ]))->toString(),
      ]),
      '#weight' => $pdf_info_weight++,
    ];
    $form['pdf_info']['form_id'] = [
      '#type' => 'item',
      '#title' => $this->t('Form info'),
      '#description' => $this->t('Form ID: [@fid].  Populate this form with entity IDs, such as %path . For more usage examples, see <a href="@documentation">the documentation</a>.', [
        '@fid' => $fid,
        '%path' => "/fillpdf?fid={$fid}&entity_ids[]=node:10&entity_ids[]=user:7",
        '@documentation' => 'https://www.drupal.org/docs/8/modules/fillpdf/usage#makelink',
      ]),
      '#weight' => $pdf_info_weight,
    ];

    $available_schemes = $form['scheme']['widget']['#options'];
    // If only one option is available, this is 'none', so there's nothing to
    // chose.
    if (count($available_schemes) == 1) {
      $form['scheme']['#type'] = 'hidden';
      $form['destination_path']['#type'] = 'hidden';
      $form['destination_redirect']['#type'] = 'hidden';
    }
    // Otherwise show the 'Storage and download' section.
    else {
      $form['storage_download'] = [
        '#type' => 'fieldset',
        '#title' => $this->t('Storage and download'),
        '#weight' => $form['pdf_info']['#weight'] + 1,
        '#open' => TRUE,
        '#attached' => [
          'library' => ['fillpdf/form'],
        ],
      ];

      $form['storage_download']['storage'] = [
        '#type' => 'container',
      ];

      // @todo Check for empty value after Core issue is fixed.
      // @see https://www.drupal.org/project/drupal/issues/1585930
      $states_no_scheme = [
        ':input[name="scheme"]' => ['value' => '_none'],
      ];
      $form['scheme']['#group'] = 'storage';
      $form['destination_path']['#group'] = 'storage';
      $form['destination_path']['widget']['0']['value']['#field_prefix'] = 'fillpdf/';
      $form['destination_path']['#states'] = [
        'invisible' => $states_no_scheme,
      ];
      $form['destination_path']['token_tree'] = $this->adminFormHelper->getAdminTokenForm();
      $description = $this->t('If filled PDFs should be automatically saved to disk, chose a file storage');
      $description .= isset($available_schemes['public']) ? '; ' . $this->t('note that %public storage does not provide any access control.', [
        '%public' => 'public://',
      ]) : '.';
      $description .= ' ' . $this->t('Otherwise, filled PDFs are sent to the browser for download.');
      $form['storage_download']['storage']['description_scheme_none'] = [
        '#type' => 'item',
        '#description' => $description,
        '#weight' => 22,
        '#states' => [
          'visible' => $states_no_scheme,
        ],
      ];
      $form['storage_download']['storage']['description_scheme_set'] = [
        '#type' => 'item',
        '#description' => $this->t('As PDFs are saved to disk, make sure you include the <em>&download=1</em> flag to send them to the browser as well.'),
        '#weight' => 23,
        '#states' => [
          'invisible' => $states_no_scheme,
        ],
      ];

      $form['destination_redirect']['#group'] = 'storage_download';
      $form['destination_redirect']['#states'] = [
        'invisible' => $states_no_scheme,
      ];
    }

    $form['additional_settings'] = [
      '#type' => 'details',
      '#title' => $this->t('Additional settings'),
      '#weight' => $form['pdf_info']['#weight'] + 1,
      '#open' => $fillpdf_form && (
          !empty($fillpdf_form->replacements->value) ||
          !empty($fillpdf_form->pdftk_encryption->value) ||
          !empty($fillpdf_form->permissions->value) ||
          !empty($fillpdf_form->owner_password->value) ||
          !empty($fillpdf_form->user_password->value)),
    ];
    $form['replacements']['#group'] = 'additional_settings';

    $form['additional_settings']['security'] = [
      '#name' => 'security',
      '#type' => 'details',
      '#title' => $this->t('PDF Security (currently only works with pdftk)'),
      '#weight' => 100,
      '#open' => $fillpdf_form && (
          !empty($fillpdf_form->pdftk_encryption->value) ||
          !empty($fillpdf_form->permissions->value) ||
          !empty($fillpdf_form->owner_password->value) ||
          !empty($fillpdf_form->user_password->value)),
    ];
    $form['pdftk_encryption']['#group'] = 'security';
    $form['permissions']['#group'] = 'security';
    $form['owner_password']['#group'] = 'security';
    $form['user_password']['#group'] = 'security';

    // @todo Add a button to let them attempt re-parsing if it failed.
    if (count($existing_fields)) {
      $form['fillpdf_fields']['fields'] = FillPdf::embedView('fillpdf_form_fields',
        'block_1',
        $fillpdf_form->id());
    }
    else {
      $form['fillpdf_fields'] = [
        '#theme' => 'status_messages',
        '#message_list' => [
          'warning' => [$fillpdf_form_fields_empty_message],
        ],
        '#status_headings' => [
          'warning' => $this->t('Warning message'),
        ],
      ];
    }

    $form['fillpdf_fields']['#weight'] = 100;

    return $form;
  }

  /**
   * Helper function providing available entity type select options.
   *
   * After translating entity types to token types, we're filtering out those
   * entity types that don't have any tokens supplied, because without tokens
   * available there is no point populating a FillPDF form with an entity.
   *
   * @return string[][]
   *   Multidimensional array of entity type options, keyed by entity type and
   *   grouped by the group label (i.e. 'Content' or 'Config').
   */
  protected function getEntityTypeOptions() {
    $token_info = $this->tokenResolver->getTokenService()->getInfo();
    $entity_mapper = $this->tokenResolver->getEntityMapper();
    $all_tokens = $token_info['types'];

    $options = [];
    foreach ($this->entityTypeManager->getDefinitions() as $entity_type => $definition) {
      // Exclude entity types with no tokens being provided.
      $token_type = $entity_mapper->getTokenTypeForEntityType($entity_type, FALSE);
      if (!$token_type || empty($all_tokens[$token_type])) {
        continue;
      }

      $label = $definition->getLabel();
      $group_label = (string) $definition->getGroupLabel();
      $options[$group_label][$entity_type] = "$entity_type ($label)";
    }

    foreach ($options as &$group_options) {
      // Sort each group's list alphabetically.
      array_multisort($group_options, SORT_ASC, SORT_NATURAL);
    }
    // Make sure that 'Content' entities are listed on top.
    $content = (string) $this->t('Content', [], ['context' => 'Entity type group']);

    return [$content => $options[$content]] + $options;
  }

  /**
   * AJAX callback updating the 'default_entity_id' element.
   *
   * This is triggered whenever either the default entity type is changed or
   * another default entity ID is chosen. It replaces the 'default_entity_id'
   * form element. If triggered by the 'default_entity_type' element, both the
   * description and the autocomplete are reset, the latter being fed with
   * referenceable entities of the chosen entity type. Otherwise, only the
   * description is rebuilt reflecting the chosen default entity ID.
   *
   * @param array $form
   *   An associative array containing the structure of the form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   *
   * @return array
   *   A render array containing the replacement form element.
   */
  public function ajaxUpdateEntityId(array &$form, FormStateInterface $form_state) {
    $element = $form['default_entity_id'];
    $triggering_element = reset($form_state->getTriggeringElement()['#array_parents']);
    if ($triggering_element == 'default_entity_type') {
      unset($element['#value']);
    }
    return $element;
  }

  /**
   * {@inheritdoc}
   *
   * @todo Remove this (imperfect) workaround once the Core issue is fixed.
   * @see https://www.drupal.org/project/fillpdf/issues/3046178
   */
  protected function actions(array $form, FormStateInterface $form_state) {
    $actions = parent::actions($form, $form_state);

    // Replace inherited '?destination' query parameter with current URL.
    /** @var \Drupal\Core\Url $route_info */
    $route_info = $actions['delete']['#url'];
    $route_info->setOption('query', []);
    $actions['delete']['#url'] = $route_info;

    return $actions;
  }

  /**
   * {@inheritdoc}
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   * @throws \Drupal\Core\Entity\EntityMalformedException
   */
  public function save(array $form, FormStateInterface $form_state) {
    /** @var \Drupal\fillpdf\FillPdfFormInterface $entity */
    $entity = $this->getEntity();

    // Display updated message, handling empty label.
    $link = $entity->toLink();
    if ($link->getText()) {
      $this->messenger()->addStatus($this->t('FillPDF Form %link has been updated.', ['%link' => $link->toString()]));
    }
    else {
      $this->messenger()->addStatus($this->t('FillPDF Form has been updated.'));
    }

    if ($form_state->getValue('upload_pdf')) {
      $existing_fields = $entity->getFormFields();

      /** @var \Drupal\file\FileInterface $new_file */
      $new_file = $this->entityTypeManager->getStorage('file')->load($form_state->getValue('upload_pdf')['0']);
      $new_file->setPermanent();
      $new_file->save();

      // Set the new file to our unsaved FillPdf form and parse its fields.
      $entity->file = $new_file;
      $new_fields = $this->inputHelper->parseFields($entity);

      // Enrich the new field objects with existing values. Note that we pass
      // them in seemingly-reverse order since we want to import the EXISTING
      // fields into the NEW fields.
      $non_matching_fields = $this->serializer->importFormFields($existing_fields, $new_fields, FALSE);

      // Save new fields.
      /** @var \Drupal\fillpdf\FillPdfFormFieldInterface $field */
      foreach ($new_fields as $field) {
        $field->save();
      }
      // Delete existing fields. Importing the new fields saved them.
      /** @var \Drupal\fillpdf\FillPdfFormFieldInterface $field */
      foreach ($existing_fields as $field) {
        $field->delete();
      }

      if (count($existing_fields)) {
        $this->messenger()->addStatus($this->t('Your previous field mappings have been transferred to the new PDF template you uploaded.'));
      }
      if (count($non_matching_fields)) {
        $message = [
          '#prefix' => $this->t("These keys couldn't be found in the new PDF:"),
          [
            '#theme' => 'item_list',
            '#items' => $non_matching_fields,
          ],
        ];
        $this->messenger()->addWarning($this->renderer->render($message));
      }

      $this->messenger()->addStatus($this->t('You might also want to update the <em>Filename pattern</em> field; this has not been changed.'));
    }

    // Save custom form elements' values, resetting default_entity_id to NULL,
    // if not matching the default entity type.
    $default_entity_type = $form_state->getValue('default_entity_type');
    $default_entity_id = ($default_entity_type == $form['default_entity_id']['#target_type']) ? $form_state->getValue('default_entity_id') : NULL;
    return $entity->set('default_entity_type', $default_entity_type)
      ->set('default_entity_id', $default_entity_id)
      ->save();
  }

}
