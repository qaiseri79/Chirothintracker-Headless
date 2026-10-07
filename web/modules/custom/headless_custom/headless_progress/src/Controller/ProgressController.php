<?php

namespace Drupal\headless_progress\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Drupal\contact\Entity\Message;
use Drupal\user\Entity\User;

/**
 * Controller for the headless progress endpoint.
 */
class ProgressController extends ControllerBase {

  /**
   * Returns the current user's progress snapshot.
   *
   * ## Why this is wrapped
   *
   * The other three write paths in this class catch \Throwable and report a JSON
   * error. This one did not, which made it the single place in the module where
   * a bad field value on a single weigh-in took down every entry on the
   * dashboard as an opaque Drupal 500 page: the patient saw "the website
   * encountered an unexpected error", the Next.js page logged
   * `The progress service responded 500`, and nothing in either response said
   * which field or which entry was at fault.
   *
   * A read has no partial-success story to preserve the way `submit()` does — the
   * log is either readable or it is not — so this returns 500 with the reason
   * rather than pretending to succeed with partial data. What it adds is the
   * message and the `headless_progress` log entry, which is what makes the next
   * occurrence diagnosable instead of another round of guessing.
   */
  public function current(): JsonResponse {
    try {
      $service = \Drupal::service('headless_progress.progress_service');
      $progress = $service->getProgress();
    } catch (\Throwable $e) {
      \Drupal::logger('headless_progress')->error(
        'Progress snapshot failed for uid @uid: @message (@class: @line)',
        [
          '@uid' => \Drupal::currentUser()->id(),
          '@message' => $e->getMessage(),
          '@class' => get_class($e),
          '@line' => $e->getLine(),
        ]
      );
      return new JsonResponse(
        ['error' => 'server_error', 'message' => $e->getMessage()],
        500
      );
    }

    $response = new JsonResponse($progress);
    $response->headers->set('Cache-Control', 'no-store, private, must-revalidate');
    return $response;
  }

  /**
   * Submits a new tracking_weight contact message for the current user.
   */
  public function submit(Request $request): JsonResponse {
    $data = json_decode($request->getContent(), true);
    if (!is_array($data) || empty($data['fields']) || !is_array($data['fields'])) {
      return new JsonResponse(['error' => 'bad_request', 'message' => 'Invalid JSON body.'], 400);
    }

    $fields = $data['fields'];
    $uid = \Drupal::currentUser()->id();

    if (!$uid) {
      return new JsonResponse(['error' => 'unauthenticated'], 401);
    }

    try {
      $account = User::load($uid);
      if (!$account) {
        return new JsonResponse(['error' => 'user_not_found'], 404);
      }
      $result = \Drupal::service('headless_progress.log_writer')->create($account, $fields, (int) $uid);
      return new JsonResponse($result, 201);
    }
    catch (\Drupal\headless_progress\Exception\ProgressValidationException $error) {
      return new JsonResponse(['error' => 'invalid_fields', 'issues' => $error->getIssues()], 422);
    }
    catch (\Throwable $error) {
      \Drupal::logger('headless_progress')->error('Submit failed: @message', ['@message' => $error->getMessage()]);
      return new JsonResponse(['error' => 'server_error', 'message' => 'Unable to save progress.'], 500);
    }
  }

  /**
   * Updates an existing tracking_weight contact message.
   */
  public function update(Request $request, int $message_id): JsonResponse {
    $data = json_decode($request->getContent(), true);
    if (!is_array($data) || empty($data['fields'])) {
      return new JsonResponse(['error' => 'bad_request', 'message' => 'Invalid JSON body.'], 400);
    }

    $fields = $data['fields'];
    $uid = \Drupal::currentUser()->id();

    if (!$uid) {
      return new JsonResponse(['error' => 'unauthenticated'], 401);
    }

    try {
      $account = User::load($uid);
      if (!$account) {
        return new JsonResponse(['error' => 'user_not_found'], 404);
      }

      $message = Message::load($message_id);
      if (!$message || $message->bundle() !== 'tracking_weight') {
        return new JsonResponse(['error' => 'not_found', 'message' => 'Entry not found'], 404);
      }

      // `Message` has no `getOwnerId()` — that is an `EntityOwnerField` method
      // and contact_message does not use it, so calling it is a fatal. The
      // owner is the `uid` entity reference field, read the same way
      // `ProgressService::getEntry()` reads it.
      //
      // Both sides are cast: `\Drupal::currentUser()->id()` returns the id as a
      // string, and a strict `!==` against an int would reject every owner.
      $owner_id = (int) ($message->get('uid')->getValue()[0]['target_id'] ?? 0);
      if ($owner_id !== (int) $uid) {
        return new JsonResponse(['error' => 'forbidden', 'message' => 'You do not own this entry'], 403);
      }

      $this->mapFields($message, $fields, $uid);

      $calculator = \Drupal::service('headless_progress.tracking_calculator');
      $derived = $calculator->computeAll($message, $fields, $account);
      $calculator->applyToMessage($message, $derived['message']);
      $calculator->applyToUser($account, $derived['user']);

      $message->save();

      // As in submit(): the update is already persisted, so a failure in the
      // follow-up work is logged rather than reported as a rejected update.
      try {
        $calculator->runEvaluations($account, $message, $fields);
      } catch (\Throwable $e) {
        \Drupal::logger('headless_progress')->error(
          'Log @id updated, but post-save evaluations failed: @message',
          ['@id' => $message->id(), '@message' => $e->getMessage()]
        );
      }

      return new JsonResponse(['id' => $message->id(), 'uuid' => $message->uuid()], 200);
    } catch (\Throwable $e) {
      \Drupal::logger('headless_progress')->error('Update failed: @message', ['@message' => $e->getMessage()]);
      return new JsonResponse(['error' => 'server_error', 'message' => $e->getMessage()], 500);
    }
  }

  /**
   * Deletes a single tracking_weight contact message owned by the current user.
   *
   * ## Why this is not a soft delete
   *
   * The entry is removed outright rather than archived with `field_archive`.
   * `field_archive` exists for the clinic's bulk archival sweep, which moves
   * *every* old log off the dashboard at once; this is a patient correcting one
   * bad entry from the row they are looking at, and the two have different
   * lifetimes. An archived entry would still be returned by
   * `ProgressService::buildEntries()` — that query filters on `contact_form`
   * only — so "deleted" would have meant "still on the dashboard but inert".
   *
   * The guards are the same three the update path uses, and the order matters:
   * existence is checked before ownership, so a caller cannot use the 403 to
   * confirm that somebody else's entry exists.
   *
   * ## What is deliberately not recomputed
   *
   * The user-level rollups (`field_net_weight_loss`, `field_net_inches_lost`,
   * `field_goal_achieved`, `field_gross_weight_loss`,
   * `field_user_current_program_day_c`) are written by `TrackingCalculator`
   * from whichever log was submitted most recently, so after a delete they can
   * still describe the entry that was just removed. They are left alone here
   * because the correct repair is a full historical recalculation, which
   * `TrackingCalculator::runEvaluations()` documents as NOT MIGRATED and
   * assigns to the queue worker and nightly cron
   * (`docs/headless/NON_ARCHIVE_BACKLOG.md`, `ResetStartingValues`). Re-deriving
   * the aggregates from just the remaining logs is that same unmigrated work,
   * and doing a partial version of it here would be worse than leaving it to
   * the one place that is meant to own it. The entry list, which is what the
   * patient is looking at, is correct immediately.
   */
  public function deleteEntry(int $message_id): JsonResponse {
    $uid = \Drupal::currentUser()->id();
    if (!$uid) {
      return new JsonResponse(['error' => 'unauthenticated'], 401);
    }

    try {
      $message = Message::load($message_id);
      if (!$message || $message->bundle() !== 'tracking_weight') {
        return new JsonResponse(['error' => 'not_found', 'message' => 'Entry not found'], 404);
      }

      // Same read and same int cast as update(); see the comment there for why
      // `getOwnerId()` cannot be used and why the string from currentUser()
      // must be cast before comparing.
      $owner_id = (int) ($message->get('uid')->getValue()[0]['target_id'] ?? 0);
      if ($owner_id !== (int) $uid) {
        return new JsonResponse(['error' => 'forbidden', 'message' => 'You do not own this entry'], 403);
      }

      $deleted_id = (int) $message->id();
      $message->delete();

      \Drupal::logger('headless_progress')->notice(
        'Progress log @id deleted by uid @uid.',
        ['@id' => $deleted_id, '@uid' => $uid]
      );

      return new JsonResponse(['id' => $deleted_id, 'deleted' => TRUE], 200);
    } catch (\Throwable $e) {
      \Drupal::logger('headless_progress')->error('Delete failed: @message', ['@message' => $e->getMessage()]);
      return new JsonResponse(['error' => 'server_error', 'message' => $e->getMessage()], 500);
    }
  }

  /**
   * Returns raw field values of a single tracking entry for editing.
   */
  public function getEntry(int $message_id): JsonResponse {
    $uid = \Drupal::currentUser()->id();
    if (!$uid) {
      return new JsonResponse(['error' => 'unauthenticated'], 401);
    }

    $service = \Drupal::service('headless_progress.progress_service');
    $entry = $service->getEntry($message_id, $uid);

    if ($entry === null) {
      return new JsonResponse(['error' => 'not_found', 'message' => 'Entry not found'], 404);
    }

    return new JsonResponse($entry);
  }

  /** Processes one authenticated batch of existing logs, earliest date first. */
  public function sync(Request $request): JsonResponse {
    $headers = ['Cache-Control' => 'no-store, private'];
    $data = json_decode($request->getContent(), TRUE);
    if (!is_array($data) || !array_key_exists('token', $data)
      || ($data['token'] !== NULL && (!is_string($data['token']) || !preg_match('/^[a-f0-9]{64}$/', $data['token'])))
      || !isset($data['processed']) || !is_int($data['processed']) || $data['processed'] < 0) {
      return new JsonResponse(['message' => 'Invalid sync request.'], 400, $headers);
    }
    $uid = (int) \Drupal::currentUser()->id();
    if (!$uid) {
      return new JsonResponse(['message' => 'Please sign in again.'], 401, $headers);
    }
    try {
      $account = User::load($uid);
      if (!$account) {
        return new JsonResponse(['message' => 'Account not found.'], 404, $headers);
      }
      $status = \Drupal::service('headless_progress.log_sync')->step($account, $data['token'], $data['processed']);
      return new JsonResponse($status, 200, $headers);
    }
    catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) {
      return new JsonResponse(['message' => $error->getMessage()], $error->getStatusCode(), $headers);
    }
    catch (\Throwable $error) {
      \Drupal::logger('headless_progress')->error('Log sync failed for uid @uid: @message', ['@uid' => $uid, '@message' => $error->getMessage()]);
      return new JsonResponse(['message' => 'Unable to sync your logs. Please try again.'], 500, $headers);
    }
  }

  /**
   * Maps the submitted fields to the contact message entity.
   *
   * The field list is an allowlist, not a catch-all. `$message->set($key, …)`
   * accepts any base field as well as any real field, so without this a client
   * could post `uid`, `ip_address` or `created` and rewrite them — the `uid`
   * one being a straight reassignment of the log to another account. Only the
   * fields the tracking form actually owns are written.
   */
  protected function mapFields(Message $message, array $fields, int $uid): void {
    \Drupal::service('headless_progress.log_writer')->mapFields($message,$fields,$uid);
  }

}