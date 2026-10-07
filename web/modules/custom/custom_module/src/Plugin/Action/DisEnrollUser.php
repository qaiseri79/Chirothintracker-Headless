<?php

namespace Drupal\custom_module\Plugin\Action;

use Drupal\node\Entity\Node;
use Drupal\user\Entity\User;
use Drupal\views_bulk_operations\Action\ViewsBulkOperationsActionBase;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Plugin\PluginFormInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\webform\Entity\Webform;
use Drupal\webform\Entity\WebformSubmission;
use Drupal\webform\WebformSubmissionForm;

/**
 * 
 *
 * @Action(
 *   id = "custom_module_disenroll_user",
 *   label = @Translation("Disenroll/Archieved User"),
 *   type = "user",
 *   confirm = TRUE
 * )
 */

class DisEnrollUser extends ViewsBulkOperationsActionBase {

  use StringTranslationTrait;

  /**
   * {@inheritdoc}
   */
  public function execute(ContentEntityInterface $entity = NULL) {
     $this->disenroll($entity);
    return $this->t('Disenrollment done on the selected users.');
  }
  public function disenroll($user_entity){
     if(in_array('enrolled_patient', $user_entity->getRoles())) {
          // $user_entity->removeRole('archived_patient');
          // $user_entity->addRole('enrolled_patient');
          $user_entity->addRole('archived_patient');
          $user_entity->removeRole('enrolled_patient');
          $user_entity->changed->preserve = TRUE;
          $user_entity->save();
     }
  }
  /**
   * {@inheritdoc}
   */
  public function access($object, AccountInterface $account = NULL, $return_as_object = FALSE) {
       return $object->access('update', $account, $return_as_object);
  }

}
