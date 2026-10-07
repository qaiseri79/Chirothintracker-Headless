<?php

namespace Drupal\download_file\Plugin\Field\FieldFormatter;

use Drupal\Component\Utility\Xss;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\file\Plugin\Field\FieldFormatter\FileFormatterBase;

/**
 * Plugin implementation of the 'download_file' Formatter.
 *
 * @FieldFormatter(
 *   id = "direct_download" ,
 *   label = @Translation("Direct Download"),
 *   field_types = {
 *    "file"
 *   }
 * )
 */
class DirectDownloadFormatter extends FileFormatterBase {

  /**
   * {@inheritdoc}
   */
  public static function defaultSettings(): array {
    return [
        'class' => '',
        'styles' => '',
      ] + parent::defaultSettings();
  }

  /**
   * {@inheritdoc}
   */
  public function settingsForm(array $form, FormStateInterface $form_state): array {
    $form = parent::settingsForm($form, $form_state);

    $form['class'] = [
      '#title' => $this->t('Element class'),
      '#type' => 'textfield',
      '#default_value' => $this->getSetting('class'),
    ];

    $form['styles'] = [
      '#title' => $this->t('Element inline styles'),
      '#type' => 'textfield',
      '#default_value' => $this->getSetting('styles'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function settingsSummary(): array {
    $summary = parent::settingsSummary();

    if ($class = $this->getSetting('class')) {
      $summary[] = $this->t('Element class: @class', ['@class' => $class]);
    }

    if ($styles = $this->getSetting('styles')) {
      $summary[] = $this->t('Element inline styles: @styles', ['@styles' => $styles]);
    }

    return $summary;
  }

  /**
   * Builds a renderable array for a field value.
   *
   * @param \Drupal\Core\Field\FieldItemListInterface $items
   *   The field values to be rendered.
   * @param string $langcode
   *   The language that should be used to render the field.
   *
   * @return array
   *   A renderable array for $items, as an array of child elements keyed by
   *   consecutive numeric indexes starting from 0.
   */
  public function viewElements(FieldItemListInterface $items, $langcode): array {
    $elements = [];

    $class = Xss::filterAdmin($this->getSetting('class'));
    $styles = Xss::filterAdmin($this->getSetting('styles'));

    foreach ($this->getEntitiesToView($items, $langcode) as $delta => $file) {
      /** @var \Drupal\file\FileInterface $file */
      $item = $file->_referringItem;
      $id = $file->id();

      $elements[$delta] = [
        '#theme' => 'direct_download_file_link',
        '#link_text' => !empty($item->description) ? $item->description : $file->getFilename(),
        '#url' => URL::fromRoute('download_file.download_file_path', ['file' => $id]),
        '#file_id' => $id,
        '#attributes' => [
          'class' => [$class],
          'style' => [$styles],
        ],
        '#cache' => [
          'tags' => $file->getCacheTags(),
        ],
      ];

      if (isset($item->_attributes)) {
        $elements[$delta]['#attributes'] += $item->_attributes;
        unset($item->_attributes);
      }
    }
    return $elements;
  }

}
