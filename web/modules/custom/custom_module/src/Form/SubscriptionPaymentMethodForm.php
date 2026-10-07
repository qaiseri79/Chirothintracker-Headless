<?php

namespace Drupal\custom_module\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\commerce_payment\Entity\PaymentMethod;
use Drupal\commerce_recurring\Entity\Subscription;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
/**
 * Provides a form to change the subscription payment method.
 */
class SubscriptionPaymentMethodForm extends FormBase {

  /**
   * The route match service.
   *
   * @var \Drupal\Core\Routing\RouteMatchInterface
   */
  protected $routeMatch;

  /**
   * Constructs the form.
   *
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   The route match service.
   */
  public function __construct(RouteMatchInterface $route_match) {
    $this->routeMatch = $route_match;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('current_route_match')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'subscription_payment_method_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    // Get the dynamic subscription ID from the route.
    $subscription_id = $this->routeMatch->getParameter('subscription_id');
    $subscription = Subscription::load($subscription_id);
    $state = $subscription->get("state")->getValue()[0]["value"];


    if ($state == "canceled") {
       throw new AccessDeniedHttpException($this->t('The subscription ID is missing or invalid.'));
    }
    $subscription_payment = $subscription->get('payment_method')->getValue();
    $current_payment_method_id = $subscription_payment[0]['target_id'] ?? NULL;
    $options = $this->getUserPaymentMethods();

    // Build the form.
    $form['payment_method'] = [
      '#type' => 'select',
      '#title' => $this->t('Select Payment Method'),
      '#options' => $options,
      '#default_value' => $current_payment_method_id,
      '#required' => TRUE,
    ];

    $form['actions']['#type'] = 'actions';
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Update Payment Method'),
      '#button_type' => 'primary',
    ];

    return $form;
  }

  /**
   * Gets payment methods for the current user.
   *
   * @return array
   *   An associative array of payment method IDs and labels.
   */

  protected function getUserPaymentMethods() {
    $payment_method_storage = \Drupal::entityTypeManager()->getStorage('commerce_payment_method');
    $uid = \Drupal::currentUser()->id();
    $payment_method_ids = $payment_method_storage->getQuery()
      ->condition('uid', $uid)
      ->accessCheck(FALSE)
      ->execute();
    $options = [];
    foreach ($payment_method_ids as $payment_method_id) {
      $payment_method = PaymentMethod::load($payment_method_id);
      if ($payment_method) {
        $options[$payment_method_id] = $payment_method->label();
      }
    }

    return $options;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
   
    $payment_method_id = $form_state->getValue('payment_method');
    $subscription_id = $this->routeMatch->getParameter('subscription_id');
    $subscription = Subscription::load($subscription_id);

    if ($subscription && $payment_method_id) {
      $payment_method = PaymentMethod::load($payment_method_id);
      $subscription->set('payment_method', $payment_method);
      $subscription->save();

      $this->messenger()->addStatus($this->t('Payment method updated successfully.'));
    } else {
      $this->messenger()->addError($this->t('Unable to update the payment method. Please try again.'));
    }
  }
}
