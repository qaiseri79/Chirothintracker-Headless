<?php

namespace Drupal\custom_module\Plugin\Block;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Block\BlockPluginInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\node\Entity\Node;
/**
 *
 * @Block(
 *   id = "refresh_data_block",
 *   admin_label = @Translation("Refresh Data (Custom Block)"),
 * )
 */
class RefreshDataBlock extends BlockBase implements BlockPluginInterface {
  /**
   * {@inheritdoc}
   */
  public function build() {
    $account = \Drupal\user\Entity\User::load(\Drupal::currentUser()->id());
       if(in_array('enrolled_patient', $account->getRoles())) {
          $query = \Drupal::entityQuery('contact_message')
          ->condition('contact_form', 'tracking_weight')
          ->condition('uid', \Drupal::currentUser()->id())
          ->accessCheck(FALSE);
        $ids = $query->execute();
        if($ids){
           $form = \Drupal::formBuilder()->getForm('Drupal\batch_with_ajax\Form\UpdateNodeForm');
          return $form;
        }else{
             return array();
        }
       }else{
        return array();
       }
  }


  public function getCacheMaxAge() {
    return 0;
  }
}

