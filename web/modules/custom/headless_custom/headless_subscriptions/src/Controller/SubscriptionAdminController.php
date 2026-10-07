<?php
declare(strict_types=1);
namespace Drupal\headless_subscriptions\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** Administrative visibility into the new billing records, separate from legacy ARB. */
final class SubscriptionAdminController extends ControllerBase {
  public function listing(Request $request): array {
    $search = mb_substr(trim((string) $request->query->get('search', '')), 0, 254);
    $state = (string) $request->query->get('status', '');
    $options = ['' => 'All statuses', 'none' => 'No subscription', 'active' => 'Active', 'past_due' => 'Past due', 'cancelled' => 'Cancelled', 'expired' => 'Expired', 'review' => 'Review or pending change'];
    if (!isset($options[$state])) $state = '';
    $records = \Drupal::service('headless_subscriptions.repository')->adminAccounts($search, $state);
    $clinics = $this->entityTypeManager()->getStorage('clinic')->loadMultiple(array_column($records, 'clinic_id'));
    $rows = [];
    foreach ($records as $record) {
      $plan = $record['purchase_plan'] ? json_decode($record['purchase_plan'], TRUE, 512, JSON_THROW_ON_ERROR) : NULL;
      $rows[] = [
        'doctor' => ['data' => ['#type' => 'link', '#title' => $record['mail'] ?: $record['name'], '#url' => Url::fromRoute('headless_subscriptions.admin_detail', ['uid' => $record['uid']])]],
        'clinic' => ($clinics[$record['clinic_id']] ?? NULL)?->label() ?? ('Clinic ' . $record['clinic_id']),
        'plan' => $plan['name'] ?? 'None',
        'price' => $plan ? $this->price($plan) : '—',
        'status' => $record['purchase_state'] ?? 'No subscription',
        'paid_through' => $this->date($record['purchase_paid_until']),
        'renewal' => $record['purchase_cancel_requested'] ? 'Cancelled' : ($record['purchase_subscription_id'] ? 'Scheduled' : 'Not scheduled'),
        'review' => ($record['purchase_pending_change'] || $record['purchase_review_reason'] || in_array($record['purchase_state'], ['payment_review','renewal_review','scheduling','paid_pending_schedule'], TRUE)) ? 'Review / pending change' : '—',
      ];
    }
    return [
      'intro' => ['#type' => 'html_tag', '#tag' => 'p', '#value' => $this->t('Subscriptions created through the new doctor portal. Select a doctor to see billing history, payment details and provider status.')],
      'filter' => ['#type' => 'form', '#method' => 'get', '#action' => Url::fromRoute('headless_subscriptions.admin')->toString(),
        'search' => ['#type' => 'textfield', '#title' => $this->t('Doctor name or email'), '#value' => $search, '#name' => 'search', '#id' => 'billing-search'],
        'status' => ['#type' => 'select', '#title' => $this->t('Status'), '#options' => $options, '#value' => $state, '#name' => 'status', '#id' => 'billing-status'],
        'submit' => ['#type' => 'submit', '#value' => $this->t('Filter')],
      ],
      'table' => ['#type' => 'table', '#header' => ['Doctor','Clinic','Plan','Price','Status','Paid through','Renewal','Attention'], '#rows' => $this->plainRows($rows), '#empty' => $this->t('No portal subscription accounts match these filters.')],
      'pager' => ['#type' => 'pager'],
      '#cache' => ['max-age' => 0],
    ];
  }
  public function detail(string $uid): array {
    $uid = (int) $uid;
    $repo = \Drupal::service('headless_subscriptions.repository');
    $account = $repo->account($uid);
    if (!$account) throw new NotFoundHttpException();
    $user = $this->entityTypeManager()->getStorage('user')->load($uid);
    $clinic = $this->entityTypeManager()->getStorage('clinic')->load((int) $account['clinic_id']);
    $billing = \Drupal::service('headless_subscriptions.subscription')->management($uid);
    $purchase = $repo->current($uid);
    $subscription = $billing['subscription'];
    $method = $billing['paymentMethod'];
    $rows = [
      ['Doctor', $user?->getEmail() ?? ('User ' . $uid)], ['Clinic', $clinic?->label() ?? '—'],
      ['Plan', $subscription ? $subscription['plan']['name'] . ' — ' . $this->price($subscription['plan']) : 'No subscription'],
      ['Status', $subscription['status'] ?? 'Unpaid'], ['Paid through', $this->date($subscription['paidThrough'] ?? NULL)],
      ['Renewal', $subscription && $subscription['cancelAtPeriodEnd'] ? 'Cancellation requested / cancelled' : ($subscription && $subscription['autoRenew'] ? 'Enabled' : 'Not enabled')],
      ['Portal access', $billing['capabilities']['portalWrite'] ? 'Active' : ($billing['capabilities']['portalRead'] ? 'Read only' : 'Billing only')],
      ['Patient limit', $billing['capabilities']['patientLimit'] ?? 'Unlimited'],
      ['Additional doctors', 'Multiple doctors share this clinic; the primary doctor owns billing'],
      ['Store access', $billing['capabilities']['store'] ? 'Included' : 'Not included'],
      ['Payment method', $method ? ($method['brand'] . ' ending ' . ($method['lastFour'] ?? 'unavailable')) : ($billing['paymentMethodUnavailable'] ? 'Provider unavailable; refresh later' : 'No saved card')],
      ['Provider', 'Authorize.Net'], ['Subscription reference', $purchase['reference'] ?? '—'],
      ['Provider transaction ID', $purchase['transaction_id'] ?? '—'], ['Provider renewal ID', $purchase['subscription_id'] ?? '—'],
      ['Review reason', ($purchase['review_reason'] ?? '') ?: ($subscription && $subscription['needsReview'] ? 'Provider confirmation required' : 'None')],
    ];
    if (!empty($subscription['pendingPlan'])) {
      $pending = $subscription['pendingPlan'];
      $rows[] = ['Pending plan', $pending['plan']['name'] . ' — ' . $this->price($pending['plan'])];
      $rows[] = ['Change takes effect', !empty($purchase['pending_change']['immediate']) ? 'Immediately after verified upgrade payment and renewal update' : $this->date($pending['effectiveAt']) . ' after a confirmed renewal payment'];
      $rows[] = ['Change status', $pending['state']];
      $rows[] = ['Plan-change quote ID', $purchase['pending_change']['quoteId'] ?? 'Legacy scheduled change'];
      $rows[] = ['Replacement renewal ID', $purchase['pending_change']['newSubscriptionId'] ?? 'Not applicable / awaiting confirmation'];
    }
    $teamRows = [];
    if (\Drupal::hasService('headless_clinic.clinic')) {
      foreach (\Drupal::service('headless_clinic.clinic')->adminTeam($this->currentUser(), $uid) as $doctor) {
        $teamRows[] = [$doctor['name'], $doctor['email'], $doctor['blocked'] ? 'Blocked' : ($doctor['active'] ? 'Active' : 'Inactive'),
          $doctor['enrolledPatients'], $doctor['archivedPatients'], $doctor['managedSponsorship'] ? 'Primary subscription' : 'Legacy access'];
      }
    }
    $history = [];
    foreach ($repo->history($uid) as $p) $history[] = [$this->date($p['created']), $p['plan']['name'], $this->price($p['plan']), $p['state'], $this->date($p['paid_until']), $p['transaction_id'] ?? '—', $p['subscription_id'] ?? '—'];
    $payments = [];
    foreach ($billing['payments'] as $payment) $payments[] = [$this->date($payment['paidAt']), $payment['planName'], number_format($payment['amountMinor'] / 100, 2) . ' ' . $payment['currency'], ($payment['kind'] ?? '') === 'upgrade' ? 'Prorated upgrade' : ($payment['renewal'] ? 'Renewal' : 'Initial payment'), $payment['status'], $payment['id']];
    $actions = ['#type' => 'container'];
    if ($purchase) $actions['refresh'] = ['#type' => 'link', '#title' => $this->t('Refresh provider status'), '#url' => Url::fromRoute('headless_subscriptions.admin_refresh', ['uid' => $uid]), '#attributes' => ['class' => ['button']]];
    if ($billing['actions']['cancel']) $actions['cancel'] = ['#type' => 'link', '#title' => $this->t('Cancel renewal'), '#url' => Url::fromRoute('headless_subscriptions.admin_cancel', ['uid' => $uid]), '#attributes' => ['class' => ['button']]];
    return [
      'back' => ['#type' => 'link', '#title' => $this->t('All portal subscriptions'), '#url' => Url::fromRoute('headless_subscriptions.admin')],
      'summary' => ['#type' => 'table', '#header' => ['Detail','Value'], '#rows' => $this->plainRows($rows)],
      'actions' => $actions,
      'team_heading' => ['#type' => 'html_tag', '#tag' => 'h2', '#value' => $this->t('Additional clinic doctors')],
      'team' => ['#type' => 'table', '#header' => ['Doctor','Email','Access','Enrolled patients','Archived patients','Funding'], '#rows' => $this->plainRows($teamRows), '#empty' => $this->t('No additional doctors are linked to this clinic.')],
      'payments_heading' => ['#type' => 'html_tag', '#tag' => 'h2', '#value' => $this->t('Verified payment history')],
      'payments' => ['#type' => 'table', '#header' => ['Date','Plan','Amount','Type','Status','Transaction'], '#rows' => $this->plainRows($payments), '#empty' => $this->t('No verified payments recorded yet. Refresh provider status to verify earlier payments.')],
      'history_heading' => ['#type' => 'html_tag', '#tag' => 'h2', '#value' => $this->t('Subscription attempts')],
      'history' => ['#type' => 'table', '#header' => ['Created','Plan','Price','Status','Paid through','Transaction','Renewal ID'], '#rows' => $this->plainRows($history), '#empty' => $this->t('No subscription attempts.')],
      '#cache' => ['max-age' => 0],
    ];
  }
  private function plainRows(array $rows): array {
    return array_map(static fn(array $row) => array_map(static fn(mixed $cell) => is_array($cell) ? $cell : ['data' => ['#plain_text' => (string) $cell]], $row), $rows);
  }
  private function date(mixed $timestamp): string {
    return $timestamp ? \Drupal::service('date.formatter')->format((int) $timestamp, 'custom', 'M j, Y g:i a', 'America/Los_Angeles') . ' (Pacific)' : '—';
  }
  private function price(array $plan): string { return number_format($plan['amountMinor'] / 100, 2) . ' ' . $plan['currency'] . ' / ' . $plan['per']; }
}
