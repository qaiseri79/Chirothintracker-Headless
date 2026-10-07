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
use Drupal\Core\Access\AccessResult;
use Drupal\contact\Entity\Message;

/**
 * 
 *
 * @Action(
 *   id = "custom_module_mass_email_action",
 *   label = @Translation("Send Mass Email"),
 *   type = "user",
 *   confirm = TRUE
 * )
 */
class MassEmailAction extends ViewsBulkOperationsActionBase implements PluginFormInterface {
  use StringTranslationTrait;

   /**
   * {@inheritdoc}
   */

  public function buildConfigurationForm(array $form, \Drupal\Core\Form\FormStateInterface $form_state) {
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Send Emails'),
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function execute(ContentEntityInterface $entity = NULL) {
    $result = $this->sendMassEmails($entity);
    return $result
    ? $this->t('The message was sent successfully.')
    : $this->t('An error occurred while sending the message.');
  }

  /**
   * {@inheritdoc}
   */
  public function access($object, AccountInterface $account = NULL, $return_as_object = FALSE) {
       //return $object->access('update', $account, $return_as_object);
     if ($account->hasRole('administrator')) {
       return AccessResult::allowed();
     }
  }
  public function sendMassEmails($user_entity){
    
           // Send email to the user.
                $host = \Drupal::request()->getSchemeAndHttpHost();    
                $mailManager = \Drupal::service('plugin.manager.mail');
                $module = 'custom_module';
                $key = 'subscription_trial_ending';
                $to = $user_entity->getEmail();
                //$to = "qaiseri79@gmail.com";
                $params['subject'] = "Upcoming Platform Migration – Important Information for ChiroThinTracker Users";
                $params['body'] = '
                <html>
                  <body>
                    <p><strong>Dear ChiroThinTracker User,</strong></p>

                    <p>We have been working very hard to prepare all your data for migration to our new ChiroThinTracker platform. We are scheduling the migration to take place during the period starting <strong>Friday, June 13</strong> and extending through <strong>Sunday, June 15</strong>. Please save all your log information and then submit it once the new platform goes live.</p>

                    <p>All your data has been saved. Nothing will be lost during the migration and there is nothing for you to do during this process.</p>

                    <p>Please take note that we have streamlined and improved the back-end processes by removing the clinic subdomains. Now, everyone will log into the portal from a universal login page (you will be automatically redirected), and you will use the same login email address and password to access the portal.</p>
                  </body>
                </html>';

                $langcode = \Drupal::currentUser()->getPreferredLangcode();
                $send = true;
                $result = $mailManager->mail($module, $key, $to, $langcode, $params, NULL, $send);
            return $result;
  }
}
