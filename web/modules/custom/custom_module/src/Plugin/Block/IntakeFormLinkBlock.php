<?php

namespace Drupal\custom_module\Plugin\Block;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Block\BlockPluginInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\node\Entity\Node;

/**
 * Provides a block for Mortage Interest Block calculator.
 *
 * @Block(
 *   id = "intake_form_link_block",
 *   admin_label = @Translation("Intake Form Link (Custom Block))"),
 * )
 */
class IntakeFormLinkBlock extends BlockBase implements BlockPluginInterface {
  /**
   * {@inheritdoc}
   */
  public function build() {
      $html = "";
      $chiropractor = \Drupal\user\Entity\User::load(\Drupal::currentUser()->id());
      $clinic = $chiropractor->get("field_clinic")->getValue();
      $brand_name = "";
      if($clinic){
        $clinic_id = $clinic[0]["target_id"];
        $storage = \Drupal::service('entity_type.manager')->getStorage('clinic');
          if($clinic_id){
              $clinic_entity = $storage->load($clinic_id);
              $field_brand = $clinic_entity->get('field_brand')->getValue();
              if($field_brand){
                  $brand_name = $field_brand[0]["value"];
          }
        $host = \Drupal::request()->getSchemeAndHttpHost();
        $copy_text = $host . '/intake?clinic_id=' . $clinic_id . '&clinicbrand=' . $brand_name;

        // Add ClipboardJS-enabled link
        $html .= '<div class="text-content clearfix field field--name-body field--type-text-with-summary field--label-hidden field__item">';
        $html .= '<p>Intake Form: ';
       // $html .= '<a class="text-content clipboardjs-button use-clipboard-js" href="#" data-clipboard-text="' . $copy_text . '">' . $copy_text . '</a>';
        $html .= '<input id="intake-url" value="'.$copy_text.'">';
        $html .= '<button class="clipboard-btn" data-clipboard-target="#intake-url"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-clipboard-check" viewBox="0 0 16 16">
  <path fill-rule="evenodd" d="M10.854 7.146a.5.5 0 0 1 0 .708l-3 3a.5.5 0 0 1-.708 0l-1.5-1.5a.5.5 0 1 1 .708-.708L7.5 9.793l2.646-2.647a.5.5 0 0 1 .708 0"/>
  <path d="M4 1.5H3a2 2 0 0 0-2 2V14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V3.5a2 2 0 0 0-2-2h-1v1h1a1 1 0 0 1 1 1V14a1 1 0 0 1-1 1H3a1 1 0 0 1-1-1V3.5a1 1 0 0 1 1-1h1z"/>
  <path d="M9.5 1a.5.5 0 0 1 .5.5v1a.5.5 0 0 1-.5.5h-3a.5.5 0 0 1-.5-.5v-1a.5.5 0 0 1 .5-.5zm-3-1A1.5 1.5 0 0 0 5 1.5v1A1.5 1.5 0 0 0 6.5 4h3A1.5 1.5 0 0 0 11 2.5v-1A1.5 1.5 0 0 0 9.5 0z"/>
</svg></button>';
        $html .= '</p>';
       // $html .= '<p class="clipboard-feedback" style="display:none;">Copied to clipboard!</p>';
        $html .= '</div>';
        $html .= '<script src="https://cdn.jsdelivr.net/npm/clipboard@2.0.11/dist/clipboard.min.js"></script>';
        $html .= '<script>new ClipboardJS(".clipboard-btn");</script>';
        // $html .= '<div class="text-content clearfix field field--name-body field--type-text-with-summary field--label-hidden field__item" >';
        // $html .= "<p>Intake Form: <a class='text-content clipboardjs-button' href='".$host."/intake?clinic_id=".$clinic_id."&clinicbrand=".$brand_name."'>".$host."/intake?clinic_id=".$clinic_id."&clinicbrand=".$brand_name."</a></p>";
        // $html .= "</div>";
        
        }
      }

  
    return [
      '#markup' => $html,
      '#allowed_tags' => [
        'script',
        'div',
        'button',
        'input',
        'i',
        'svg',
        'path', 
      ]
    ];

  }


  public function getCacheMaxAge() {
    return 0;
  }
  
}

