# Patient dashboard log sync

The actions in `frontend/src/components/portal/progress/welcome-header.tsx` match
`New Design/progress.html`. Log your progress reuses `TrackingForm` inside
`CreateEntryDialog`. Meet Now remains disabled until meetings are available.

Sync logs calls `POST /api/progress/sync`, which forwards the Drupal session to
`POST /api/headless/progress/sync`. Drupal identifies the patient from that
session; the browser cannot choose a user or submission IDs. Enrolled patients
can sync. Archived accounts remain read-only.

Start with `{ "token": null, "processed": 0 }`. The response contains an opaque
patient-owned token, `processed`, `total`, and `done`. Repeat with the returned
token and processed count until done. Each request recalculates at most 20 logs,
ordered by log date, creation time, then ID, ascending. Retries are idempotent;
a new start request resumes an unfinished job. Jobs expire after one hour.

`ProgressLogSync` reuses `TrackingCalculator`, preserving input fields,
ownership, author, and creation dates. Each batch and its progress state are
transactional. User summary values are saved only after all batches complete;
measurement-only entries preserve the latest available weight values. Sync does
not replay `runEvaluations`, questions, or notifications. Changes to the set of
logs or baseline settings invalidate an unfinished job so it can restart.

This is manual recalculation using the current stored baselines. Historical
starting-value resets, formula changes, archive/read ordering changes, and the
queue/cron work in `NON_ARCHIVE_BACKLOG.md` remain deferred.

Verification: `ProgressLogSyncTest` covers batching, date order, patient scoping,
read-only access, retries, program/log changes, and rollback on a failed save.
Temporary API/browser fixtures additionally verified 28 logs across two batches,
foreign account isolation, question non-replay, the form popup, responsive
buttons at 320/390/1440px, and failed-request retry. Fixtures were removed.
