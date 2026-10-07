<?php

declare(strict_types=1);

namespace Drupal\headless_intake\Form;

use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\headless_intake\IntakeInviteService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Doctor-facing page to generate, copy and revoke patient intake invite links.
 *
 * Chiropractors manage links for their own clinic (from user.field_clinic).
 * Administrators may choose any clinic. Reachable at /manage/intake-links; it
 * is deliberately not added to any menu.
 *
 * Moved here from ctt_patient_intake. The clinic-scoping rule is unchanged.
 */
class IntakeLinksForm extends FormBase {

  public function __construct(
    protected IntakeInviteService $inviteService,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected AccountInterface $account,
    protected DateFormatterInterface $dateFormatter,
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
      $container->get('date.formatter'),
      $container->get('current_route_match'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'headless_intake_links_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['#attached']['library'][] = 'headless_intake/clipboard';

    if ($this->inviteService->intakeBaseUrl() === $this->currentSiteHost()) {
      $form['base_url_warning'] = [
        '#theme' => 'status_messages',
        '#message_list' => [
          [
            'status' => 'warning',
            'message' => $this->t('No intake frontend base URL is configured, so the links below point at this Drupal site, which has no /intake/{token} page. Set it at /admin/config/headless-intake/invite-settings.'),
          ],
        ],
        '#weight' => -10,
      ];
    }

    $clinicOptions = $this->clinicOptions();
    if ($clinicOptions === []) {
      $form['no_clinic'] = [
        '#markup' => $this->t('<p>No clinic is assigned to your account yet. Ask a site administrator to assign your clinic before generating intake links.</p>'),
      ];
      return $form;
    }

    $defaultClinic = (int) ($form_state->getValue('clinic') ?? $this->defaultClinicId());
    $form['clinic'] = [
      '#type' => 'select',
      '#title' => $this->t('Clinic'),
      '#description' => $this->t('The clinic the intake submission will belong to.'),
      '#options' => $clinicOptions,
      '#default_value' => $defaultClinic,
      '#empty_option' => $this->t('- Select clinic -'),
    ];

    $form['max_uses'] = [
      '#type' => 'number',
      '#title' => $this->t('Max submissions'),
      '#description' => $this->t('How many patients may complete this link. One-time is the default; raise it only when several patients share one intake link.'),
      '#default_value' => $this->inviteService->defaultMaxUses(),
      '#min' => 1,
      '#max' => 1000,
      '#required' => TRUE,
    ];

    $form['expiry_days'] = [
      '#type' => 'number',
      '#title' => $this->t('Expires after (days)'),
      '#description' => $this->t('Days until the link stops accepting submissions. 0 = never expires.'),
      '#default_value' => $this->inviteService->defaultExpiryDays(),
      '#min' => 0,
      '#max' => 365,
      '#required' => TRUE,
    ];

    $form['actions']['#type'] = 'actions';
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Generate intake link'),
    ];

    $generated = $form_state->get('generated');
    if (is_array($generated)) {
      $form['generated'] = $this->generatedBox((string) $generated['url']);
    }

    if ($defaultClinic > 0) {
      $form['tokens'] = $this->tokenTable($defaultClinic);
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $clinicId = (int) $form_state->getValue('clinic');
    if ($clinicId <= 0 || !isset($form['clinic']['#options'][$clinicId])) {
      $form_state->setErrorByName(
        'clinic',
        $this->t('Choose a clinic first.'),
      );
      return;
    }
    $maxUses = max(1, (int) $form_state->getValue('max_uses'));
    $expiryDays = max(0, (int) $form_state->getValue('expiry_days'));
    $expiresAt = $expiryDays > 0 ? time() + ($expiryDays * 86400) : NULL;

    $token = $this->inviteService->issue($clinicId, $expiresAt, $maxUses);
    $form_state->set('generated', [
      'url' => $this->inviteService->intakeUrl($token),
      'token' => $token,
      'clinic_id' => $clinicId,
    ]);
    $this->messenger()->addStatus($this->t('Intake link generated.'));
    $form_state->setRebuild();
  }

  /**
   * Renders the "copy and share" box for a freshly generated link.
   *
   * @param string $url
   *   The full intake URL.
   *
   * @return array<string, mixed>
   *   Render array for the details element.
   */
  private function generatedBox(string $url): array {
    return [
      '#type' => 'details',
      '#title' => $this->t('New intake link — copy and share with the patient'),
      '#open' => TRUE,
      'url' => [
        '#type' => 'textfield',
        '#title' => $this->t('Intake link'),
        '#value' => $url,
        '#attributes' => [
          'id' => 'headless-intake-generated-url',
          'readonly' => 'readonly',
          'onfocus' => 'this.select()',
        ],
        '#title_display' => 'before',
      ],
      'copy' => [
        '#type' => 'button',
        '#value' => $this->t('Copy link'),
        '#attributes' => ['data-ctt-copy' => '#headless-intake-generated-url'],
      ],
      'note' => [
        '#markup' => '<p class="description">' . $this->t('The link is single-use unless the max submissions setting says otherwise. Revoke it below if the patient never completes it.') . '</p>',
      ],
    ];
  }

  /**
   * Clinic options the current user may issue links for.
   *
   * @return array<int, string>
   *   Clinic ids keyed to labels.
   */
  private function clinicOptions(): array {
    if ($this->account->isAnonymous()) {
      return [];
    }
    $clinicStorage = $this->entityTypeManager->getStorage('clinic');
    if ($this->account->hasPermission('administer site configuration')) {
      $available = [];
      foreach ($clinicStorage->loadMultiple() as $clinic) {
        $available[$clinic->id()] = $clinic->label();
      }
      natcasesort($available);
      return $available;
    }
    $own = $this->ownClinicId();
    if ($own === 0) {
      return [];
    }
    $clinic = $clinicStorage->load($own);
    return $clinic !== NULL ? [$own => $clinic->label()] : [];
  }

  /**
   * Clinic preselected in the form for an administrator.
   *
   * @return int
   *   Clinic id, or 0 when the user must choose.
   */
  private function defaultClinicId(): int {
    if ($this->account->hasPermission('administer site configuration')) {
      return 0;
    }
    return $this->ownClinicId();
  }

  /**
   * The clinic assigned to the current user.
   *
   * @return int
   *   Clinic id, or 0 when the user has none.
   */
  private function ownClinicId(): int {
    $user = $this->entityTypeManager->getStorage('user')->load($this->account->id());
    if (
      $user === NULL
      || !$user->hasField('field_clinic')
      || $user->get('field_clinic')->isEmpty()
    ) {
      return 0;
    }
    return (int) $user->get('field_clinic')->target_id;
  }

  /**
   * Table of existing tokens for a clinic.
   *
   * @param int $clinicId
   *   Clinic whose tokens to list.
   *
   * @return array<string, mixed>
   *   Render array for the table.
   */
  private function tokenTable(int $clinicId): array {
    $baseHref = $this->inviteService->intakeBaseUrl();

    $rows = [];
    foreach ($this->inviteService->list($clinicId) as $token) {
      $link = $baseHref . '/intake/' . $token['token'];
      $status = $token['status'];
      $statusLabel = ucfirst($status);
      $rows[] = [
        'status' => [
          'data' => [
            '#markup' => '<span class="headless-token-' . $status . '">'
            . $this->t($statusLabel) . '</span>',
          ],
        ],
        'created' => $token['created']
          ? $this->dateFormatter->format($token['created'], 'short')
          : '—',
        'expires' => $token['expires_at']
          ? $this->dateFormatter->format($token['expires_at'], 'short')
          : $this->t('Never'),
        'uses' => $token['uses'] . ' / ' . $token['max_uses'],
        'link' => [
          'data' => ['#markup' => '<code>' . $link . '</code>'],
        ],
        'actions' => $token['status'] === 'active'
          ? [
            'data' => [
              '#markup' => $this->t('<a href="@url">revoke</a>', [
                '@url' => Url::fromRoute(
                  'headless_intake.intake_link_revoke',
                  ['token' => $token['token']],
                )->toString(),
              ]),
            ],
          ]
          : '',
      ];
    }

    return [
      '#type' => 'table',
      '#header' => [
        $this->t('Status'),
        $this->t('Created'),
        $this->t('Expires'),
        $this->t('Uses'),
        $this->t('Link'),
        '',
      ],
      '#rows' => $rows,
      '#empty' => $this->t('No intake links generated yet for this clinic. Use the form above to create the first one.'),
      '#attributes' => ['class' => ['headless-intake-tokens']],
      '#caption' => $this->t('Intake links for this clinic'),
    ];
  }

  /**
   * Host of the site being served, used to detect an unconfigured base URL.
   *
   * Uses the parent FormBase request helper rather than an injected request
   * stack: FormBase already declares a $requestStack property, so a typed one
   * declared here would collide with it and fatal on class load.
   *
   * @return string
   *   Scheme and host of the current request, or an empty string when there is
   *   no current request.
   */
  private function currentSiteHost(): string {
    return $this->getRequest()?->getSchemeAndHttpHost() ?? '';
  }

}
