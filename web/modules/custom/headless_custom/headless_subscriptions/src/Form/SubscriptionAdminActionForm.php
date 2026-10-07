<?php
declare(strict_types=1);
namespace Drupal\headless_subscriptions\Form;

use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\headless_subscriptions\SubscriptionException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** Confirmation and CSRF protection for privileged provider operations. */
final class SubscriptionAdminActionForm extends ConfirmFormBase {
  protected int $doctorId;
  protected string $operation;
  protected string $purchaseId;
  public function getFormId(): string { return 'headless_subscription_admin_action'; }
  public function buildForm(array $form, FormStateInterface $form_state, string $uid = '', string $operation = ''): array {
    if (!in_array($operation, ['cancel','refresh'], TRUE)) throw new NotFoundHttpException();
    $repo = \Drupal::service('headless_subscriptions.repository');
    if (!$repo->account((int) $uid) || !($p = $repo->current((int) $uid))) throw new NotFoundHttpException();
    $this->doctorId = (int) $uid; $this->operation = $operation; $this->purchaseId = $p['id'];
    return parent::buildForm($form, $form_state);
  }
  public function getQuestion() { return $this->operation === 'cancel' ? $this->t('Cancel this doctor’s future subscription renewals?') : $this->t('Refresh this doctor’s subscription from Authorize.Net?'); }
  public function getDescription() { return $this->operation === 'cancel' ? $this->t('This stops future collection. The doctor keeps access for the confirmed paid period. No refund is issued.') : $this->t('This verifies provider payments and schedules. It does not create a new payment.'); }
  public function getCancelUrl(): Url { return Url::fromRoute('headless_subscriptions.admin_detail', ['uid' => $this->doctorId]); }
  public function getConfirmText() { return $this->operation === 'cancel' ? $this->t('Cancel renewal') : $this->t('Refresh status'); }
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $service = \Drupal::service('headless_subscriptions.subscription');
    try {
      $p = \Drupal::service('headless_subscriptions.repository')->current($this->doctorId);
      if (!$p || $p['id'] !== $this->purchaseId) throw new SubscriptionException('subscription_changed', 'The subscription changed. Review it before continuing.', 409);
      if ($this->operation === 'cancel') $service->cancel($this->doctorId);
      else $service->refresh($this->doctorId);
      $this->messenger()->addStatus($this->operation === 'cancel' ? $this->t('Future renewals cancelled. The paid period remains accessible.') : $this->t('Provider status refreshed.'));
    }
    catch (SubscriptionException $e) { $this->messenger()->addError($e->getMessage()); }
    catch (\Throwable $e) { $this->messenger()->addError($this->t('The provider operation could not be confirmed. Review the subscription before retrying.')); }
    $form_state->setRedirectUrl($this->getCancelUrl());
  }
}
