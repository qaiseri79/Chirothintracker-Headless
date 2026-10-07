<?php

namespace Drupal\custom_module\Plugin\views\field;

use Drupal\Component\Utility\Xss;
use Drupal\Core\Form\FormStateInterface;
use Drupal\views\Render\ViewsRenderPipelineMarkup;
use Drupal\views\ResultRow;
use Drupal\views\Plugin\views\field\FieldPluginBase;
use Drupal\taxonomy\Entity\Term;
use Drupal\views\Views;

/**
 *
 * @ingroup views_field_handlers
 *
 * @ViewsField("set_lock_field")
 */
class SetLockField extends FieldPluginBase {
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
    $value =  $this->getEntity($values);
     if($value->id()){
         $account = \Drupal\user\Entity\User::load($value->id());
         $current_phase = $account->get('field_weight_loss_phase')->getValue()[0]['value'];
         $phases = array("phase-0"=>"Zero-Day (Pre-Loading)","phase-1"=>"Loading Phase","phase-2"=>"Losing Phase","phase-3"=>"Cycling / Maintenance Phase","phase-4"=>"Continuity Phase");
         $output = "";
          if(array_key_exists($current_phase, $phases)){
            foreach ($phases as $key => $val) {
              if($key !== $current_phase){

               $output .= '<li><a class="dropdown-item" href="/'.$key.'?key='.$key.'&uid='.$value->id().'">'.'Set '.$val.'</a></li>';
              }
            }
         }
     }
  return [
      [
       '#markup' => $output,
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