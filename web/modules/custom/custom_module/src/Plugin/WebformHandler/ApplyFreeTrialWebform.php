<?php

/**
 * @file
 * Contains \Drupal\custom_module\Plugin\WebformHandler\ApplyFreeTrialWebform.
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
 *   id = "custom_module_apply_free_trial_validator",
 *   label = @Translation("Free Trial Validator"),
 *   category = @Translation("Settings"),
 *   description = @Translation("validate User before applying for free trial."),
 *   cardinality = \Drupal\webform\Plugin\WebformHandlerInterface::CARDINALITY_SINGLE,
 *   results = \Drupal\webform\Plugin\WebformHandlerInterface::RESULTS_PROCESSED,
 *   submission = \Drupal\webform\Plugin\WebformHandlerInterface::SUBMISSION_OPTIONAL,
 * )
 */

class ApplyFreeTrialWebform extends WebformHandlerBase
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
        $fetched_user = user_load_by_name($email);

        if ($fetched_user) {
            $formState->setErrorByName('chiropractor_email', $this->t('A user with "' . $email . '" email already exists in the system. Please use a different one.'));
        } else {
            $formState->setValue('chiropractor_email', $email);
        }
    }

}
