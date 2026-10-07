<?php

namespace Drupal\custom_module\Form;

use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;

/**
 * Confirmation form to block a chiropractor.
 */
class BlockChiropractorConfirmForm extends ConfirmFormBase
{

    /**
     * The user ID to block.
     *
     * @var int
     */
    protected $user;

    /**
     * {@inheritdoc}
     */
    public function getFormId()
    {
        return 'block_chiropractor_confirm_form';
    }

    /**
     * {@inheritdoc}
     */
    public function getQuestion()
    {
        return $this->t('Are you sure you want to block this chiropractor?');
    }

    /**
     * {@inheritdoc}
     */
    public function getDescription()
    {
        return $this->t('This action cannot be undone.<br> <b>Are you sure you want to block this chiropractor?</b>');
    }

    /**
     * {@inheritdoc}
     */
    public function getCancelUrl()
    {
        return Url::fromRoute('view.subscriber_s_chirpractors.page_1');
    }

    /**
     * {@inheritdoc}
     */
    public function getConfirmText()
    {
        return $this->t('Block');
    }

    /**
     * {@inheritdoc}
     */
    public function buildForm(array $form, FormStateInterface $form_state, $user = NULL)
    {
        $this->user = $user;
        return parent::buildForm($form, $form_state);
    }

    /**
     * {@inheritdoc}
     */
    public function submitForm(array &$form, FormStateInterface $form_state)
    {
        $this->user->block();
        $this->user->save();
        $this->messenger()->addStatus($this->t('User %name has been blocked.', ['%name' => $this->user->getDisplayName()]));

        // Redirect to subscriber_s_chirpractors view page.
        $form_state->setRedirect('view.subscriber_s_chirpractors.page_1');
    }

}
