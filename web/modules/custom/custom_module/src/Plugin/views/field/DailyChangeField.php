<?php

namespace Drupal\custom_module\Plugin\views\field;

use Drupal\Component\Utility\Xss;
use Drupal\Core\Form\FormStateInterface;
use Drupal\views\Render\ViewsRenderPipelineMarkup;
use Drupal\views\ResultRow;
use Drupal\views\Plugin\views\field\FieldPluginBase;
use Drupal\taxonomy\Entity\Term;
use Drupal\views\Views;
use Drupal\contact\Entity\Message;
/**
 *
 * @ingroup views_field_handlers
 *
 * @ViewsField("daily_change_field")
 */
class DailyChangeField extends FieldPluginBase {
   /**
   * {@inheritdoc}
   */

  public function query() {
    // do nothing -- to override the parent query.
  }

  /**
   * {@inheritdoc}
   */
  protected function defineOptions() {
    $options = parent::defineOptions();
    return $options;
  }

 /**
   * {@inheritdoc}
   */
  public function buildOptionsForm(&$form, FormStateInterface $form_state) {
    parent::buildOptionsForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function render(ResultRow $values) {
          $value = $this->getEntity($values);
          $today_weight = 0;
          $yesterday_weight = 0;

          $daily_change_last = Views::getView('recent_submissions_grid_cs');
          $daily_change_last->setDisplay('embed_2');
          $daily_change_last->setArguments([$value->id()]);
          $daily_change_last->execute();

          $daily_change_last_result = $daily_change_last->result;
          if (!empty($daily_change_last_result)) {
              $submission_id = $daily_change_last_result[0]->_entity->id();
              $message_entity = Message::load($submission_id);
              if ($message_entity) {
                  $field_weight = $message_entity->get('field_weight')->getValue();
                  $today_weight = isset($field_weight) ? $field_weight[0]['value'] : 0;
              }
          }

          $daily_change_sec_last = Views::getView('recent_submissions_grid_cs');
          $daily_change_sec_last->setDisplay('embed_3');
          $daily_change_sec_last->setArguments([$value->id()]);
          $daily_change_sec_last->execute();

          $daily_change_sec_last_result = $daily_change_sec_last->result;
          if (!empty($daily_change_sec_last_result)) {
              $submission_id = $daily_change_sec_last_result[0]->_entity->id();
              $message_entity = Message::load($submission_id);
              if ($message_entity) {
                  $field_weight = $message_entity->get('field_weight')->getValue();
                  $yesterday_weight = isset($field_weight) ? $field_weight[0]['value'] : 0;
              }
          }

          // Calculate the daily change
          if (isset($today_weight) && isset($yesterday_weight)) {
              $daily_change_output = (float)$today_weight - (float)$yesterday_weight;
              $daily_change_output = number_format($daily_change_output, 1, '.', '');
          } else {
              $daily_change_output = 0;
          }

          if ($daily_change_output > 0) {
              $formatted_result = '+' . $daily_change_output;
          } elseif ($daily_change_output < 0) {
              $formatted_result = (string)$daily_change_output;
          } else {
              $formatted_result = '0';
          }
    
  return [
      [
       '#markup' => $formatted_result,
       '#allowed_tags' => [
        'div',
        'ul',
        'li',
        'span',
        'p',
        'a',
        'code',
        'pre',
        'table',
        'thead',
        'tbody',
        'tr',
        'td',
        'th',
        'strong',
        'input',
        'script',
        'select',
        'option',
        'input',
        'button',
        'form',
        'img',
        'canvas',
        'h1',
        'h2',
        'h3',
        'h4',
        'h5',
        'h6',
       ]
      ]
    ];
  }
}