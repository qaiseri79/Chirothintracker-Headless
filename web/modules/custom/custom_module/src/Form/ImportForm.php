<?php

namespace Drupal\custom_module\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Component\Serialization\Json;
use Drupal\node\Entity\Node;

/**
 * Provides a form for deleting a custom_module entity.
 *
 * @ingroup custom_module
 */
class ImportForm extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() : string {
    return 'batch_import_example_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {

    $form['#prefix'] = '<p>This example form will import 3 pages from the docs/animals.json example</p>';

    $form['actions'] = array(
      '#type' => 'actions',
      'submit' => array(
        '#type' => 'submit',
        '#value' => 'Proceed',
      ),
    );

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
        $client = new Client();
        $response = $client->get('https://dev-chirothin9.pantheonsite.io/sites/default/files/json/notes_.json');
          
          $data = json_decode($response->getBody(), TRUE);
     
        $operations = [
            ['creating_submissioins', [$data]],
        ];
        $batch = [
            'title' => $this->t('Create Submission'),
            'operations' => $operations,
            'finished' => 'creating_submissioins_finished',
        ];
        batch_set($batch);
  }
}