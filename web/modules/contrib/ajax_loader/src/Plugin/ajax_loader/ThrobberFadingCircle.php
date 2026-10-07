<?php

namespace Drupal\ajax_loader\Plugin\ajax_loader;

use Drupal\ajax_loader\ThrobberPluginBase;

/**
 * Provides the "Fading Circle" throbber.
 *
 * @Throbber(
 *   id = "throbber_fading_circle",
 *   label = @Translation("Fading circle")
 * )
 */
class ThrobberFadingCircle extends ThrobberPluginBase {

  /**
   * Function set markup.
   *
   * @inheritdoc
   */
  protected function setMarkup() {
    return '<div class="ajax-throbber sk-circle-fade">
              <div class="sk-circle-fade-dot"></div>
              <div class="sk-circle-fade-dot"></div>
              <div class="sk-circle-fade-dot"></div>
              <div class="sk-circle-fade-dot"></div>
              <div class="sk-circle-fade-dot"></div>
              <div class="sk-circle-fade-dot"></div>
              <div class="sk-circle-fade-dot"></div>
              <div class="sk-circle-fade-dot"></div>
              <div class="sk-circle-fade-dot"></div>
              <div class="sk-circle-fade-dot"></div>
              <div class="sk-circle-fade-dot"></div>
              <div class="sk-circle-fade-dot"></div>
            </div>';
  }

  /**
   * Function to set css file.
   *
   * @inheritdoc
   */
  protected function setCssFile() {
    return $this->path . '/css/fading-circle.css';
  }

}
