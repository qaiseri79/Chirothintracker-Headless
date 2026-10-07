<?php

namespace Drupal\custom_module\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\user\Entity\User;
use Drupal\views\Views;

/**
 * Class EnrollmentLimit.
 */
class EnrollmentLimit extends ControllerBase {

  /**
   * The enrollment limit for whoever is making the request.
   *
   * Kept as the no-argument entry point because callers outside this module
   * already depend on that shape: two webform handlers and a custom_module
   * function all do `new EnrollmentLimit` then `->Limit()`, and the
   * `/enroll-limit` route points straight at this method as a controller. Adding a
   * parameter here would break all four — and because the route passes a Request,
   * even an optional `?int` would fail with a TypeError rather than falling back.
   *
   * Resolving the clinic from the current user is the right reading for those
   * callers: they are asking "may I enroll?". Callers that already know the clinic
   * should use {@see self::limitForClinic()} instead, which does not consult the
   * session at all.
   *
   * @return array<string, mixed>
   *   The limit shape. See {@see self::limitForClinic()}.
   */
  public function Limit() {
    $user = User::load($this->currentUser()->id());

    // hasField() rather than get() with a null-coalesce: `get()` on a field the
    // user entity type does not have raises a FieldException, which the ?? would
    // never see.
    $clinicId = $user?->hasField('field_clinic')
      ? (int) ($user->get('field_clinic')->target_id ?? 0)
      : 0;

    return $this->limitForClinic($clinicId);
  }

  /**
   * The enrollment limit for a given clinic.
   *
   * The limit is a property of the clinic's Enrollment Package, not of the
   * chiropractor who happens to be logged in. Both package limits are read off the
   * package the clinic is attached to, and the count compared against them is
   * scoped to that same clinic, so two chiropractors at one practice are held to
   * one shared cap.
   *
   * @param int $clinicId
   *   The clinic to evaluate. A clinic with no package is unlimited rather than an
   *   error: the package is what carries the cap, so a practice without one has not
   *   chosen a cap.
   *
   * @return array<string, mixed>
   *   One of:
   *   - ['enrollment_status' => 2, 'message' => ..., 'type' => 'error'] at the cap.
   *   - ['enrollment_status' => 1, 'message' => ..., 'type' => 'warning'] at the
   *     soft limit.
   *   - ['enrollment_status' => 0] when under both or when there is no package.
   */
  public function limitForClinic(int $clinicId): array {
    if (\Drupal::hasService('headless_subscriptions.subscription')) {
      $quota = \Drupal::service('headless_subscriptions.subscription')->quotaForClinic($clinicId);
      if ($quota !== NULL) return $quota;
    }

    if ($clinicId <= 0) {
      return ['enrollment_status' => 0];
    }

    $storage = \Drupal::entityTypeManager()->getStorage('clinic');
    $clinic = $storage->load($clinicId);

    // Every load below can legitimately fail — a clinic can be deleted while its
    // package reference is being rebuilt, and a clinic can exist without the
    // package field entirely on a site that has not set packages up. None of those
    // are an error at this point in an enrollment: they all mean "no cap configured",
    // and the previous code fataled on the first two instead.
    if ($clinic === NULL || !$clinic->hasField('field_enrollment_package')) {
      return ['enrollment_status' => 0];
    }

    $packageId = (int) ($clinic->get('field_enrollment_package')->target_id ?? 0);
    if ($packageId <= 0) {
      return ['enrollment_status' => 0];
    }

    $package = \Drupal::entityTypeManager()->getStorage('enrollment_package')->load($packageId);
    if ($package === NULL) {
      return ['enrollment_status' => 0];
    }

    $softLimit = $package->hasField('field_enrollment_soft_limit')
      ? (int) ($package->get('field_enrollment_soft_limit')->value ?? 0)
      : 0;
    $hardLimit = $package->hasField('field_enrollment_hard_limit')
      ? (int) ($package->get('field_enrollment_hard_limit')->value ?? 0)
      : 0;

    $count = $this->enrolledCount($clinicId);

    // A zero limit is the "no limit" sentinel throughout, which is why these are
    // truthiness checks rather than comparisons: a package with both limits at 0
    // means unlimited, not "block everyone at zero".
    $status = 0;
    if ($hardLimit && $count >= $hardLimit) {
      $status = 2;
    }
    elseif ($softLimit && $count >= $softLimit) {
      $status = 1;
    }

    switch ($status) {
      case 1:
        return [
          'message' => 'Warning! You are reaching your patient enrollment limit. You can archive any inactive patients, or contact us at <a href="mailto:support@chirothintracker.com">support@chirothintracker.com</a> to increase your limit.',
          'type' => 'warning',
          'enrollment_status' => 1,
        ];
      case 2:
        return [
          'message' => '<p>You have reached your limit of enrolled patients. No additional patients may be enrolled at this time. You can archive any inactive patients, or contact us at <a href="mailto:support@chirothintracker.com">support@chirothintracker.com</a> to increase your limit.</p>',
          'type' => 'error',
          'enrollment_status' => 2,
        ];
      default:
        return ['enrollment_status' => 0];
    }
  }

  /**
   * How many patients are currently enrolled at a clinic.
   *
   * Counted through the `rule_count_enrolled_patients` view rather than an entity
   * query on purpose. The view is what the rest of the legacy enrollment flow and
   * the admin screens already read, so it is the site's existing definition of
   * "enrolled" — including the cases it deliberately excludes, which an entity
   * query written fresh here would not reproduce. Changing what counts would change
   * who gets blocked, so the view stays until it is replaced deliberately.
   *
   * If the view cannot run, this reports 0 rather than throwing, so a broken view
   * does not stop a chiropractor enrolling patients. That is a deliberate trade: a
   * briefly uncounted cap is recoverable and nobody is turned away from care, and
   * because the count is recomputed on every attempt it self-corrects as soon as
   * the view works again. It is logged so the gap is visible.
   *
   * @param int $clinicId
   *   The clinic to count.
   *
   * @return int
   *   Enrolled patient count, or 0 if the count is unavailable.
   */
  private function enrolledCount(int $clinicId): int {
    try {
      $view = Views::getView('rule_count_enrolled_patients');
      if ($view === NULL) {
        throw new \RuntimeException('View rule_count_enrolled_patients is missing.');
      }
      $view->setDisplay('default');
      $view->setArguments([$clinicId]);
      $view->execute();

      return is_array($view->result) ? count($view->result) : 0;
    }
    catch (\Throwable $e) {
      \Drupal::logger('custom_module')->error(
        'Could not count enrolled patients in clinic @clinic; treating the limit as uncounted. @message',
        [
          '@clinic' => $clinicId,
          '@message' => $e->getMessage(),
        ]
      );
      return 0;
    }
  }

  public function Test() {

  $user = User::load(1077);

  if ($user) {
    $user->setPassword('thin1');
    $user->save();
  }
}


}