# Doctor patient summary

The Next.js doctor home (`/chiropractor`) reads enrolled patients through
`GET /api/headless/patients/summary`. Only user summary fields and batched
references/flags are loaded initially. Needs attention is unavailable until a
business rule is defined; missing stored statistics are represented as null.

`PatientSummaryService` composes the existing roster serializer, `PatientPhaseMap`,
`ProgressService`, and `PatientIntakeService`. Progress measurements and food
labels share the patient dashboard mapping. The Daily logs popup follows
`New Design/chirothin-patient-summary-v9.html`: date,
weight, water, optional blood sugar and blood pressure. It uses the shared
progress writer/calculations; the doctor is the author and the selected patient owns the log. A confirmed save refreshes
that patient's summary. Doctor logs have no adherence grade; duplicate dates and
future dates are rejected. Backdated logs appear by log date without replacing
the patient's latest statistics. The strip arrows scroll 480 px; the popup
confirms weight changes over 15 lbs before submitting. Both intake views use
`IntakeSubmissionContent`. Session field definitions and taxonomy choices come
from Drupal, and insertion uses the existing treatment calculation/count hooks.

## API

All reads use `ReadChiropractorAccess`; writes use `WriteChiropractorAccess`.
The clinic is derived from the authenticated account, never from the request.
Detail and file operations also verify patient role/status and ownership.

| Method | Path under `/api/headless/patients` | Operation |
| --- | --- | --- |
| GET | `/summary` | Enrolled roster, locations, phases |
| GET | `/{user}/summary?section=…&offset=…` | overview, logs, progress, sessions, notes, attachments, intake |
| POST | `/{user}/summary` | review, phase, lastSeen |
| POST | `/{user}/summary/notes` | save, delete, pin an owned note |
| POST | `/{user}/summary/progress` | Compact doctor form submits a patient-owned log using the shared progress writer |
| POST | `/{user}/summary/sessions` | Owned package ID and whitelisted fields; JSON or multipart `payload` + `photos[]` |
| POST | `/{user}/summary/files` | Multipart attachment upload |
| GET / DELETE | `/{user}/summary/files/{file}` | Download / remove an owned attachment |
| GET | `/{user}/summary/photo` | Authorized avatar |

Logs initially load 5 records, then load 3 older records per scroll/click;
notes, attachment submissions, and session histories load 100. Responses return `hasMore` and `nextOffset`. The offset counts
submissions, so attachments with several files cannot skip subsequent records.
The chart reads only scalar history columns and includes measurements even when
a submission has no weight. Sessions select the newest package of each type and
page histories per package.

The Next.js summary provider caches each patient section, deduplicates reads,
appends pages, ignores stale reads after saves, and updates the shared row after
confirmed writes. The proxy forwards the existing Drupal session; files are
streamed with private/no-store headers.

Uploads use `private://patient-summary/{uid}`; configure private file storage and
make this directory writable by the web process. Attachments allow 20 MB. Session
photos allow 10 images, 20 MB each and 100 MB total. Deleted uploads are removed
only when owned by this patient and no other entity uses them.

Account editing, photo management, email resend, and
export actions remain disabled in this summary until their flows are implemented.
They do not report a successful action without an API save.

## Validation

Run patient unit tests and the affected progress service tests:

```sh
vendor/bin/phpunit -c web/core/phpunit.xml.dist web/modules/custom/headless_custom/headless_patients/tests/src/Unit
vendor/bin/phpunit -c web/core/phpunit.xml.dist --filter ProgressService web/modules/custom/headless_custom/headless_progress/tests/src/Unit
vendor/bin/phpunit -c web/core/phpunit.xml.dist --filter ProgressLogWriter web/modules/custom/headless_custom/headless_progress/tests/src/Unit
cd frontend
npm run type-check
npm run lint
```

Local integration checks cover persisted flags, phases, dates, notes/pins,
session decrementing, private attachment uploads/downloads/removal, foreign
clinic rejection, anonymous rejection, and inactive doctor read-only access.
Use disposable accounts and remove fixtures after testing; do not send email.
