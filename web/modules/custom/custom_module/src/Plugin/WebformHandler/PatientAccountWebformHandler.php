<?php

/**
 * @file
 * Contains \Drupal\custom_module\Plugin\WebformHandler\PatientAccountWebformHandler.
 */

namespace Drupal\custom_module\Plugin\WebformHandler;

use Drupal\Core\Form\FormStateInterface;
use Drupal\webform\Plugin\WebformHandlerBase;
use Drupal\webform\WebformSubmissionInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\custom_module\Controller\EnrollmentLimit;

/**
 * Webform validate handler.
 *
 * @WebformHandler(
 *   id = "custom_module_patient_account_custom_validator",
 *   label = @Translation("Alter form to validate it"),
 *   category = @Translation("Settings"),
 *   description = @Translation("Form alter to validate it."),
 *   cardinality = \Drupal\webform\Plugin\WebformHandlerInterface::CARDINALITY_SINGLE,
 *   results = \Drupal\webform\Plugin\WebformHandlerInterface::RESULTS_PROCESSED,
 *   submission = \Drupal\webform\Plugin\WebformHandlerInterface::SUBMISSION_OPTIONAL,
 * )
 */
class PatientAccountWebformHandler extends WebformHandlerBase
{

    use StringTranslationTrait;

    public function alterForm(array &$form, FormStateInterface $form_state, WebformSubmissionInterface $webform_submission)
    {
        $enrollment_limit = new EnrollmentLimit;
        $result = $enrollment_limit->Limit();
        $html = "";
        if ($result) {
            if (array_key_exists("enrollment_status", $result)) {
                if (array_key_exists("message", $result)) {
                    $rendered_message = \Drupal\Core\Render\Markup::create($result["message"]);
                    $error_message = new TranslatableMarkup('@message', array('@message' => $rendered_message));
                }
                if ($result["enrollment_status"] > 0) {

                    if ($result["enrollment_status"] == 2) {
                        $class = 'messages--error';
                        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="31px" height="31px" viewBox="0 0 31 31">
                  <path d="M0,16.2C0.5,23.1,5.2,29,12,30.6c6.3,1.5,13.1-1.2,16.6-6.7c3.4-5.3,3.2-12.3-0.5-17.4C24.2,1,16.9-1.4,10.6,0.8C4.2,3-0.5,9.4,0,16.2z M3.9,14.5c0.4-4.1,3-7.8,6.7-9.5c1.9-0.9,3.9-1.2,5.9-1c1,0.1,2,0.3,3,0.7c0.4,0.1,2.2,0.7,2.3,1.2C22,6,19.5,8.1,19.3,8.3c-1.8,1.8-3.6,3.6-5.4,5.5c-1.8,1.8-3.6,3.6-5.5,5.4c-0.6,0.6-1.2,1.2-1.8,1.8c-0.1,0.1-0.6,0.8-0.8,0.8c-0.6,0-1.5-3.2-1.6-3.7C3.9,16.9,3.8,15.7,3.9,14.5z M25.3,9.1c0.7,0.7,1.2,2.2,1.4,3.1c0.3,1.2,0.5,2.4,0.4,3.7c-0.1,2.8-1.2,5.4-3.1,7.5c-3.8,4.1-10.2,4.9-14.9,1.8C10.7,23.6,25.1,9,25.3,9.1z"></path>
                </svg>';
                    } else if ($result["enrollment_status"] == 1) {
                        $class = 'messages--warning';
                        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="32px" height="32px" viewBox="0 0 32 32">
            <path d="M16,0C7.2,0,0,7.2,0,16c0,8.8,7.2,16,16,16c8.8,0,16-7.2,16-16C32,7.2,24.8,0,16,0z M18.7,26c0,0.4-0.3,0.7-0.6,0.7h-4c-0.4,0-0.7-0.3-0.7-0.7v-4c0-0.4,0.3-0.7,0.7-0.7h4c0.4,0,0.6,0.3,0.6,0.7V26z M18.6,18.8c0,0.3-0.3,0.5-0.7,0.5h-3.9c-0.4,0-0.7-0.2-0.7-0.5L13,5.9c0-0.1,0.1-0.3,0.2-0.4c0.1-0.1,0.3-0.2,0.5-0.2h4.6c0.2,0,0.4,0.1,0.5,0.2C18.9,5.6,19,5.7,19,5.9L18.6,18.8z"></path>
          </svg>';
                    }
                    $html = '<div data-drupal-messages="" class="messages-list">
                <div class="messages__wrapper layout-container">
                    <div class="messages-list__item messages ' . $class . '" data-drupal-selector="messages" role="contentinfo" aria-label="Error message" data-once="messages">
                      <div class="messages__container" data-drupal-selector="messages-container" role="alert">
                        <div class="messages__header">
                          <h2 class="visually-hidden">Error message</h2>
                          <div class="messages__icon">
                              ' . $svg . '
                          </div>
                          </div>
                            <div class="messages__content">
                                <ul class="messages__list">
                                  <li class="messages__item">' . $error_message . '</li>
                                </ul>
                            </div>
                          </div>
                      </div>
                    </div>
              </div>';
                }
            }

            $form['header_message'] = array
            (
            '#prefix' => '<p>',
            '#markup' => \Drupal\Core\Render\Markup::create($html),
            '#suffix' => '</p>',
            '#weight' => -100,
            );
        }
    }

    /**
     * {@inheritdoc}
     */
    public function validateForm(array &$form, FormStateInterface $form_state, WebformSubmissionInterface $webform_submission)
    {
        $this->_validateUser($form_state);
        $this->_validateLimit($form_state);
    }

    /**
     * Validate user email uniqueness.
     *
     * @param \Drupal\Core\Form\FormStateInterface $formState
     *   The form state.
     */
    private function _validateUser(FormStateInterface $formState)
    {
        $email = $formState->getValue('patient_email_address');
        $username = $email;
        $fetched_by_email = user_load_by_mail($email);
        $fetched_by_name = user_load_by_name($username);

        if ($fetched_by_email || $fetched_by_name) {
            $formState->setErrorByName(
                'patient_email_address',
                $this->t('The email address @email is already taken.', ['@email' => $email])
            );
        } else {
            // Optional: sanitize or normalize email if needed
            $formState->setValue('patient_email_address', trim($email));
        }
    }

    /**
     * Validate enrollment limits.
     *
     * @param \Drupal\Core\Form\FormStateInterface $formState
     *   The form state.
     */
    private function _validateLimit(FormStateInterface $formState)
    {
        $enrollment_limit = new EnrollmentLimit;
        $result = $enrollment_limit->Limit();
        if ($result) {
            if ($result["enrollment_status"] == 2) {
                \Drupal::messenger()->addMessage('Unable to entertain the request. Please contact support for assistance.', "error", true);
                $formState->setRebuild();
            }
        }
    }
}
