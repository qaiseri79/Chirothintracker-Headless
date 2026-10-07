<?php

/**
 * @file
 * Contains \Drupal\custom_module\Plugin\WebformHandler\MyChiropractorWebformHandler.
 */

namespace Drupal\custom_module\Plugin\WebformHandler;

use Drupal\Core\Form\FormStateInterface;
use Drupal\webform\Plugin\WebformHandlerBase;
use Drupal\webform\WebformSubmissionInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Webform validate handler.
 *
 * @WebformHandler(
 *   id = "custom_module_my_chiropractor_validator",
 *   label = @Translation("My Chiropractor Validator"),
 *   category = @Translation("Settings"),
 *   description = @Translation("validate chiropractor before creation."),
 *   cardinality = \Drupal\webform\Plugin\WebformHandlerInterface::CARDINALITY_SINGLE,
 *   results = \Drupal\webform\Plugin\WebformHandlerInterface::RESULTS_PROCESSED,
 *   submission = \Drupal\webform\Plugin\WebformHandlerInterface::SUBMISSION_OPTIONAL,
 * )
 */

class MyChiropractorWebformHandler extends WebformHandlerBase
{

    use StringTranslationTrait;

    /**
     * {@inheritdoc}
     */
    public function alterForm(array &$form, FormStateInterface $form_state, WebformSubmissionInterface $webform_submission)
    {
        // Nothing to alter.
    }

    /**
     * {@inheritdoc}
     */
    public function validateForm(array &$form, FormStateInterface $form_state, WebformSubmissionInterface $webform_submission)
    {
        $this->_validateUser($form_state);
    }

    /**
     * Validate user email uniqueness.
     *
     * @param \Drupal\Core\Form\FormStateInterface $formState
     *   The form state.
     */
    private function _validateUser(FormStateInterface $formState)
    {
        $email = $formState->getValue('chiropractor_email');
        $fetched_by_name = user_load_by_name($email);
        $fetched_by_mail = user_load_by_mail($email);

        if ($fetched_by_name || $fetched_by_mail) {
            $formState->setErrorByName('chiropractor_email', $this->t('The email address @email is already taken.', ['@email' => $email]));
        } else {
            $formState->setValue('chiropractor_email', $email);
        }
    }

}
