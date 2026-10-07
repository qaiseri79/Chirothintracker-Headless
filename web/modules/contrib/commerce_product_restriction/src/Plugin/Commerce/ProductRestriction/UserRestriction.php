<?php

namespace Drupal\commerce_product_restriction\Plugin\Commerce\ProductRestriction;

use Drupal\commerce_product_restriction\Plugin\ProductRestrictionPluginBase;
use Drupal\commerce_product_restriction\Plugin\ProductRestrictionPluginInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;

use Drupal\user\Entity\User;

/**
 * Provides product restriction by user role.
 *
 * @ProductRestrictionPlugin(
 *   id = "restrict_to_users",
 *   label = @Translation("Restrict to specified users"),
 *   category = @Translation("User"),
 *   entity_type = "commerce_product",
 *   weight = -1
 * )
 */
class UserRestriction extends ProductRestrictionPluginBase implements ProductRestrictionPluginInterface {

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'uids' => [],
    ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);

    $form['uids'] = [
      '#type' => 'entity_autocomplete',
      '#title' => 'Permitted users',
      '#target_type' => 'user',
      '#default_value' => User::loadMultiple(array_column($this->configuration['uids'], 'target_id')),
      '#selection_handler' => 'default:user',
      '#tags' => TRUE,
      '#selection_settings' => [
        'include_anonymous' => FALSE,
      ],
      '#required' => TRUE,
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::submitConfigurationForm($form, $form_state);

    $values = $form_state->getValue($form['#parents']);
    $this->configuration['uids'] = array_filter($values['uids']);
  }

  /**
   * {@inheritdoc}
   */
  public function evaluate(EntityInterface $entity) {
    $this->assertEntity($entity);
    $account = \Drupal::currentUser();
    if ($this->configuration['uids']) {
      $uids = array_column($this->configuration['uids'], 'target_id');
      return (bool) array_intersect($uids, [$account->id()]);
    }
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function accessErrorMessage($product_or_variation) {
    $uids = $this->configuration['uids'];

    return new TranslatableMarkup(
      "You are not on the list of users permitted to purchase this product.",
      []
    );
  }

}
