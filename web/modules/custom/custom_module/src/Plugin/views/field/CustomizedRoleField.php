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
 * @ViewsField("customized_role_field")
 */

class CustomizedRoleField extends FieldPluginBase {
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

public function filterrole($inputArray, $value1, $value2) {
    $filteredValues = array();
    foreach ($inputArray as $value) {
        if ($value === $value1 || $value === $value2) {
            $filteredValues[] = $value;
        }
    }
     $resultString = implode(', ', $filteredValues);
    return $resultString;
}



public function ReplyLink($data) {
    $account = \Drupal\user\Entity\User::load($data->get("field_to")->getValue()[0]['target_id']);
    if($account){
        $roles = $account->getRoles();
        //$resultArray = $this->filterrole($roles, "archived_patient", "enrolled_patient");
        $resultArray = $this->filterrole($roles, "chiropractor_inactive_", "chiropractor_active_");
    }
    $html = "";
    $current_path = \Drupal::service('path.current')->getPath();
    if(!empty($resultArray)){ 
    $html = '<a class="use-ajax button--primary button" href="/contact/chiropractor_message?uid='.$data->get("field_patient_uid")->getValue()[0]['value'].'&destination='.$current_path.'" data-dialog-type="modal" data-dialog-options="{&quot;width&quot;:700}">Reply</a>';
    }
   return $html; 
  }
  

  /**
   * {@inheritdoc}
   */
public function render(ResultRow $values) {
    $value =  $this->getEntity($values);
    $html = "";
     if($this->view->storage->id() == "clinician_messages_" && $this->view->current_display == "page_1"){
      if($value){
        $html = $this->ReplyLink($value);
      }
    }else if($this->view->storage->id() == "clinician_messages_" && $this->view->current_display == "page_2"){
       if($value){
        $html = $this->ReplyLink($value);
      }
    }
    return [
      [
       '#markup' => $html,
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