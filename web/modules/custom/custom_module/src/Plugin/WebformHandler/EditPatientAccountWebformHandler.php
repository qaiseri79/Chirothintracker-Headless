<?php

/**
 * @file
 * Contains \Drupal\custom_module\Plugin\WebformHandler\EditPatientAccountWebformHandler.
 */

namespace Drupal\custom_module\Plugin\WebformHandler;

use Drupal\Core\Form\FormStateInterface;
use Drupal\webform\Plugin\WebformHandlerBase;
use Drupal\webform\WebformSubmissionInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\user\Entity\User;

/**
 * Webform validate handler for Edit Patient Account.
 *
 * @WebformHandler(
 *   id = "custom_module_edit_patient_account_validator",
 *   label = @Translation("Edit Patient Account Validator"),
 *   category = @Translation("Settings"),
 *   description = @Translation("Validate email uniqueness for patient account edits"),
 *   cardinality = \Drupal\webform\Plugin\WebformHandlerInterface::CARDINALITY_SINGLE,
 *   results = \Drupal\webform\Plugin\WebformHandlerInterface::RESULTS_PROCESSED,
 *   submission = \Drupal\webform\Plugin\WebformHandlerInterface::SUBMISSION_OPTIONAL,
 * )
 */
class EditPatientAccountWebformHandler extends WebformHandlerBase
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
        $this->_validateEmailUniqueness($form_state);
    }

    /**
     * Validate email uniqueness for patient account edit.
     *
     * Ensures that the email being updated to is not already taken by another user.
     *
     * @param \Drupal\Core\Form\FormStateInterface $formState
     *   The form state.
     */
    private function _validateEmailUniqueness(FormStateInterface $formState)
    {
        $new_email = trim($formState->getValue('patient_email_address'));
        $patient_id = $formState->getValue('field_patient_id');

        if (!$new_email) {
            return;
        }

        // Load the current patient to get their current email
        $current_patient = User::load($patient_id);
        if (!$current_patient) {
            return;
        }

        $current_email = $current_patient->getEmail();

        // Only check for duplicates if the email has been changed
        if ($new_email === $current_email) {
            return;
        }

        // Check if another user already has this email
        $users_with_email = \Drupal::entityQuery('user')
            ->condition('mail', $new_email)
            ->accessCheck(false)
            ->execute();

        if (!empty($users_with_email)) {
            $formState->setErrorByName(
                'patient_email_address',
                $this->t('The email address @email is already taken.', ['@email' => $new_email])
            );
        }
    }

}
