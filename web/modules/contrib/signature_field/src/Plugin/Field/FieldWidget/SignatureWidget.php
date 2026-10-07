<?php

namespace Drupal\signature_field\Plugin\Field\FieldWidget;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\WidgetBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;

/**
 * Plugin implementation of the 'field_signature_field_widget' widget.
 *
 * @FieldWidget(
 *   id = "field_signature_field_widget",
 *   module = "signature_field",
 *   label = @Translation("Signature Data"),
 *   field_types = {
 *     "field_signature"
 *   }
 * )
 */
class SignatureWidget extends WidgetBase implements ContainerFactoryPluginInterface {

  /**
   * The renderer service.
   *
   * @var \Drupal\Core\Render\RendererInterface
   */
  protected $renderer;

  /**
   * The module handler service.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * {@inheritdoc}
   */
  public function __construct($plugin_id, $plugin_definition, FieldDefinitionInterface $field_definition, array $settings, array $third_party_settings, RendererInterface $renderer, ModuleHandlerInterface $module_handler) {
    parent::__construct($plugin_id, $plugin_definition, $field_definition, $settings, $third_party_settings);
    $this->renderer = $renderer;
    $this->moduleHandler = $module_handler;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $plugin_id,
      $plugin_definition,
      $configuration['field_definition'],
      $configuration['settings'],
      $configuration['third_party_settings'],
      $container->get('renderer'),
      $container->get('module_handler')
    );
  }

  /**
   * {@inheritdoc}
   */
  public static function defaultSettings(): array {
    return [
      'show_data_box' => TRUE,
      'show_thumb' => FALSE,
      'canvas_width' => 400,
      'canvas_height' => 200,
      'min_line_width' => 1,
      'max_line_width' => 2,
      'pen_color' => '',
      'background_color' => '',
    ] + parent::defaultSettings();
  }

  /**
   * {@inheritdoc}
   */
  public function settingsForm(array $form, FormStateInterface $form_state) {
    $form = parent::settingsForm($form, $form_state);

    $form['show_data_box'] = [
      '#title' => $this->t('Show signature data'),
      '#type' => 'checkbox',
      '#description' => $this->t('Show signature data textarea box.'),
      '#default_value' => $this->getSetting('show_data_box'),
    ];

    $form['show_thumb'] = [
      '#title' => $this->t('Show signature thumbnail'),
      '#type' => 'checkbox',
      '#description' => $this->t('Show signature thumbnail image.'),
      '#default_value' => $this->getSetting('show_thumb'),
    ];

    $form['canvas_width'] = [
      '#title' => $this->t('Canvas width'),
      '#type' => 'number',
      '#min' => 0,
      '#default_value' => $this->getSetting('canvas_width'),
    ];

    $form['canvas_height'] = [
      '#title' => $this->t('Canvas height'),
      '#type' => 'number',
      '#min' => 0,
      '#default_value' => $this->getSetting('canvas_height'),
    ];

    $form['min_line_width'] = [
      '#title' => $this->t('Minimum width of a line'),
      '#type' => 'number',
      '#min' => 1,
      '#default_value' => $this->getSetting('min_line_width'),
    ];

    $form['max_line_width'] = [
      '#title' => $this->t('Maximum width of a line'),
      '#type' => 'number',
      '#min' => 2,
      '#default_value' => $this->getSetting('max_line_width'),
    ];

    $form['pen_color'] = [
      '#title' => $this->t('Pen color'),
      '#type' => $this->moduleHandler->moduleExists('color') ? 'color' : 'textfield',
      '#default_value' => $this->getSetting('pen_color'),
      '#size' => 7,
    ];

    $form['background_color'] = [
      '#title' => $this->t('Background color'),
      '#type' => $this->moduleHandler->moduleExists('color') ? 'color' : 'textfield',
      '#default_value' => $this->getSetting('background_color'),
      '#size' => 7,
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function settingsSummary() {
    $summary = [];

    $summary[] = $this->t('Show signature data: %show', ['%show' => $this->getSetting('show_data_box') ? 'Yes' : 'No']);
    $summary[] = $this->t('Show signature thumbnail: %show', ['%show' => $this->getSetting('show_thumb') ? 'Yes' : 'No']);

    if ($this->getSetting('canvas_width')) {
      $summary[] = $this->t('Canvas width: %width', ['%width' => $this->getSetting('canvas_width')]);
    }

    if ($this->getSetting('canvas_height')) {
      $summary[] = $this->t('Canvas height: %height', ['%height' => $this->getSetting('canvas_height')]);
    }

    if ($this->getSetting('min_line_width')) {
      $summary[] = $this->t('Min line width: %width', ['%width' => $this->getSetting('min_line_width')]);
    }

    if ($this->getSetting('max_line_width')) {
      $summary[] = $this->t('Max line width: %width', ['%width' => $this->getSetting('max_line_width')]);
    }

    if ($this->getSetting('pen_color')) {
      $summary[] = $this->t('Pen color: %color', ['%color' => $this->getSetting('pen_color')]);
    }

    if ($this->getSetting('background_color')) {
      $summary[] = $this->t('Background color: %color', ['%color' => $this->getSetting('background_color')]);
    }

    return $summary;
  }

  /**
   * {@inheritdoc}
   */
  public function formElement(FieldItemListInterface $items, $delta, array $element, array &$form, FormStateInterface $form_state) {
    // Get value.
    $value = $items[$delta]->value ?? '';

    // Signature thumbnail.
    $sign_thumb = NULL;
    if ($this->getSetting('show_thumb')) {
      $sign_thumb = [
        '#type' => 'html_tag',
        '#tag' => 'img',
        '#attributes' => [
          'src' => '',
          'id' => 'signature-thumb',
          'class' => [
            'signature-thumb',
            'align-right',
          ],
        ],
      ];
    }

    // Signature data box.
    $sign_data_attributes = [
      'id' => ['signature-data'],
      'class' => [
        'signature-data',
      ],
    ];
    if (!$this->getSetting('show_data_box')) {
      $sign_data_attributes['class'][] = 'visually-hidden';
    }
    $element += [
      '#type' => 'textarea',
      '#default_value' => $value,
      '#attributes' => $sign_data_attributes,
      '#attached' => [
        'library' => ['signature_field/signature_pad'],
      ],
    ];

    // Signature pad.
    $pen_color = NULL;
    if ($this->getSetting('pen_color')) {
      $pen_color = sprintf('rgb(%s)', implode(', ', _signature_field_color_unpack($this->getSetting('pen_color'))));
    }

    $bg_color = NULL;
    if ($this->getSetting('background_color')) {
      $bg_color = sprintf('rgb(%s)', implode(', ', _signature_field_color_unpack($this->getSetting('background_color'))));
    }

    $sign_pad = [
      '#theme' => 'signature',
      '#signature_src' => $value,
      '#canvas_width' => $this->getSetting('canvas_width'),
      '#canvas_height' => $this->getSetting('canvas_height'),
      '#min_line_width' => $this->getSetting('min_line_width'),
      '#max_line_width' => $this->getSetting('max_line_width'),
      '#pen_color' => $pen_color,
      '#bg_color' => $bg_color,
    ];

    $element['#prefix'] = '<div class="signature">';

    if ($sign_pad) {
      $element['#field_prefix'] = $this->renderer->renderPlain($sign_pad);
    }

    // Attach signature thumbnail or not.
    if ($sign_thumb) {
      $element['#field_suffix'] = $this->renderer->renderPlain($sign_thumb);
    }

    $element['#suffix'] = '</div>';

    // Attach library.
    $element['#attached']['library'][] = 'signature_field/signature_pad';

    // Return element.
    return ['value' => $element];
  }

}
