<?php

namespace Drupal\commerce_product_restriction\Plugin\Commerce\ProductRestriction;

use Drupal\commerce_product_restriction\Plugin\ProductRestrictionPluginBase;
use Drupal\commerce_product_restriction\Plugin\ProductRestrictionPluginInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\user\Entity\Role;

/**
 * Provides product restriction by user role.
 *
 * @ProductRestrictionPlugin(
 *   id = "restrict_to_user_role",
 *   label = @Translation("Restrict to users with specified roles"),
 *   category = @Translation("User"),
 *   entity_type = "commerce_product",
 *   weight = -1
 * )
 */
class UserRoleRestriction extends ProductRestrictionPluginBase implements ProductRestrictionPluginInterface {

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'roles' => [],
    ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);

    $form['roles'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Allowed roles'),
      '#default_value' => $this->configuration['roles'],
      '#options' => array_map('\Drupal\Component\Utility\Html::escape', user_role_names()),
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
    $this->configuration['roles'] = array_filter($values['roles']);
  }

  /**
   * {@inheritdoc}
   */
  public function evaluate(EntityInterface $entity) {
    $this->assertEntity($entity);
    $account = \Drupal::currentUser();
    return (bool) array_intersect($this->configuration['roles'], $account->getRoles());
  }

  /**
   * {@inheritdoc}
   */
  public function accessErrorMessage($product_or_variation) {
    $roles = $this->configuration['roles'];

    $role_names = [];
    foreach ($roles as $role) {
      $role = Role::load($role);
      $role_names[] = $role->label();
    }

    $loginlink = Url::fromRoute('user.login', [], [
      'absolute' => TRUE,
      'query' => ['destination' => \Drupal::request()->getRequestUri()],
    ])->toString();

    if (count($role_names) == 1) {
      if ($role_names[0] == 'authenticated user') {
        return new TranslatableMarkup(
          'You need to be <a href=\"@loginlink\">logged in</a> to purchase this product.',
          [
            '@loginlink' => $loginlink,
            '@roles' => implode(', ', $role_names),
          ]
        );
      }
      else {
        if (\Drupal::currentUser()->isAnonymous()) {
          return new TranslatableMarkup(
            "You need to be <a href=\"@loginlink\">logged in</a> and be a '@role' user to purchase this product.",
            [
              '@loginlink' => $loginlink,
              '@role' => $role_names[0],
            ]
          );
        }
        else {
          return new TranslatableMarkup(
            "You need to be a '@role' user to purchase this product.",
            [
              '@loginlink' => $loginlink,
              '@role' => $role_names[0],
            ]
                  );
        }
      }
    }

    $message = "You need one of the following roles: ";
    return new TranslatableMarkup(
      "You do not have the required user role to purchase this product. @message @roles",
      [
        '@loginlink' => $loginlink,
        '@message' => $message,
        '@roles' => implode(', ', $role_names),
      ]
    );
  }

}
