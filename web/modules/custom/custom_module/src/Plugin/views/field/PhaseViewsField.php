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
 * @ViewsField("phase_views_field")
 */
class PhaseViewsField extends FieldPluginBase {
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
    $clinic_id = $value->id();
    $output = "";
   if($this->view->storage->id() == "resources_test" && $this->view->current_display == "page_2"){
    $shared_resources = $value->get('field_hide_shared_resources')->getValue();
    if($shared_resources){
      if($shared_resources[0]["value"] == 1){
              $view = Views::getView('training');
              $view->setDisplay('embed_1');
              $view->setArguments([$clinic_id]);
              $view->execute();
              $rendered = $view->render();
              $output = \Drupal::service('renderer')->render($rendered);
      }else{
            $view = Views::getView('training');
            $view->setDisplay('embed_2');
            $view->setArguments([$clinic_id]);
            $view->execute();
            $rendered = $view->render();
            $output = \Drupal::service('renderer')->render($rendered);
      }
    }
  }
    if($this->view->storage->id() == "resources_test" && $this->view->current_display == "page_1"){
      $shared_resources = $value->get('field_hide_shared_resources')->getValue();
        if($shared_resources){
          if($shared_resources[0]["value"] == 1){
                  $view = Views::getView('resources');
                  $view->setDisplay('embed_1');
                  $view->setArguments([$clinic_id]);
                  $view->execute();
                  $rendered = $view->render();
                  $output = \Drupal::service('renderer')->render($rendered);
          }else{
                $view = Views::getView('resources');
                $view->setDisplay('embed_2');
                $view->setArguments([$clinic_id]);
                $view->execute();
                $rendered = $view->render();
                $output = \Drupal::service('renderer')->render($rendered);
          }
       }
   }
  
    if($this->view->storage->id() == "resources_test" && $this->view->current_display == "page_3"){
      $shared_resources = $value->get('field_hide_shared_resources')->getValue();
        if($shared_resources){
          if($shared_resources[0]["value"] == 1){
                  $view = Views::getView('recipes');
                  $view->setDisplay('embed_3');
                  $view->setArguments([$clinic_id]);
                  $view->execute();
                  $rendered = $view->render();
                  $output = \Drupal::service('renderer')->render($rendered);
          }else{
                $view = Views::getView('recipes');
                $view->setDisplay('embed_4');
                $view->execute();
                $rendered = $view->render();
                $output = \Drupal::service('renderer')->render($rendered);
          }
       }
   }
    if($this->view->storage->id() == "resources_test" && $this->view->current_display == "page_4"){
      $shared_resources = $value->get('field_hide_shared_resources')->getValue();
        if($shared_resources){
          if($shared_resources[0]["value"] == 1){
                  $view = Views::getView('recipes');
                  $view->setDisplay('embed_1');
                  $view->setArguments([$clinic_id]);
                  $view->execute();
                  $rendered = $view->render();
                  $output = \Drupal::service('renderer')->render($rendered);
          }else{
                $view = Views::getView('recipes');
                $view->setDisplay('embed_2');
                $view->execute();
                $rendered = $view->render();
                $output = \Drupal::service('renderer')->render($rendered);
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