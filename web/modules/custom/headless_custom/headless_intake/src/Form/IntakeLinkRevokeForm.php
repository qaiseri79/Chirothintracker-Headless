<?php

declare(strict_types=1);

namespace Drupal\headless_intake\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\headless_intake\IntakeInviteService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Confirmation form to revoke a single intake invite token.
 *
 * Moved here from ctt_patient_intake. The clinic-ownership check is unchanged:
 * a non-admin may only revoke tokens belonging to their own clinic.
 */
class IntakeLinkRevokeForm extends ConfirmFormBase {

  public function __construct(
    protected IntakeInviteService $inviteService,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected AccountInterface $account,
    protected RouteMatchInterface $currentRouteMatch,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('headless_intake.invite'),
      $container->get('entity_type.manager'),
      $container->get('current_user'),
      $container->get('current_route_match'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'headless_intake_link_revoke_form';
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    $token = $this->token();
    if ($token === NULL) {
      return $this->t('Revoke intake link');
    }
    return $this->t('Revoke the intake link %token?', [
      '%token' => $this->shortToken($token['token']),
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription() {
    $token = $this->token();
    if ($token === NULL) {
      return $this->t('This link was not found. It may already have been revoked.');
    }
    return $this->t('This stops the link immediately. The patient will see “This link is no longer valid” instead of the intake form.');
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText() {
    return $this->t('Revoke link');
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl(): Url {
    return Url::fromRoute('headless_intake.intake_links');
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $token = $this->token();
    if ($token === NULL) {
      $this->messenger()->addWarning(
        $this->t('That link is unknown. Nothing was revoked.'),
      );
      $form_state->setRedirectUrl($this->getCancelUrl());
      return;
    }
    if (!$this->canManage($token)) {
      $this->messenger()->addError(
        $this->t('You can only revoke links for your own clinic.'),
      );
      $form_state->setRedirectUrl($this->getCancelUrl());
      return;
    }
    $this->inviteService->revoke($token['token']);
    $this->messenger()->addStatus($this->t('Intake link %token revoked.', [
      '%token' => $this->shortToken($token['token']),
    ]));
    $form_state->setRedirectUrl($this->getCancelUrl());
  }

  /**
   * Loads the token named in the URL.
   *
   * @return array{token: string, status: string, clinic_id: int, expires_at: int, max_uses: int, uses: int, created: int}|null
   *   The token row, or NULL when unknown.
   */
  private function token(): ?array {
    $token = (string) $this->currentRouteMatch->getRawParameter('token');
    return $token === '' ? NULL : $this->inviteService->find($token);
  }

  /**
   * Whether the current user may revoke this token.
   *
   * @param array{token: string, status: string, clinic_id: int, expires_at: int, max_uses: int, uses: int, created: int} $token
   *   The token being revoked.
   *
   * @return bool
   *   TRUE for an admin, or a chiropractor owning the token's clinic.
   */
  private function canManage(array $token): bool {
    if ($this->account->isAnonymous()) {
      return FALSE;
    }
    if ($this->account->hasPermission('administer site configuration')) {
      return TRUE;
    }
    $user = $this->entityTypeManager->getStorage('user')->load($this->account->id());
    if (
      $user === NULL
      || !$user->hasField('field_clinic')
      || $user->get('field_clinic')->isEmpty()
    ) {
      return FALSE;
    }
    return (int) $user->get('field_clinic')->target_id === $token['clinic_id'];
  }

  /**
   * Abbreviates a token for display.
   *
   * @param string $token
   *   Full token value.
   *
   * @return string
   *   Shortened token.
   */
  private function shortToken(string $token): string {
    return strlen($token) > 14 ? substr($token, 0, 10) . '…' : $token;
  }

}
