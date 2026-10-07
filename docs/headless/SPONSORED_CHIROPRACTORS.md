# Sponsored chiropractors in a shared clinic

Implemented 6 October 2026. The primary doctor pays for one clinic's subscription;
new additional doctors in that clinic receive effective access through that payer.
The patient allowance and exact additional-doctor limit are separate deferred decisions.

## Relationships and billing boundary

The database field is user.field_chiropractor, not field_chirpractor.
For a patient it identifies the treating doctor. New covered doctors reference the
primary through both field_chiropractor and field_chiropractor_subscribers.
A primary doctor's existing field_chiropractor self-reference is a legacy convention,
not sponsorship.

headless_subscription_member records the covered doctor's uid, primary_uid,
clinic_id, active/blocked state, timestamps and actor. The relationship has one
funding owner. It must agree with the doctor and clinic references and cannot point
to another covered doctor. A covered doctor cannot also have an independent
managed billing account.

Only doctors explicitly enrolled through the new managed flow receive new funding
inheritance. Existing legacy relationships are still shown and can be managed under
their existing policy, but are not automatically migrated. No duplicate purchase,
payment profile, store or clinic is created for a covered doctor.

All plans expose sponsoredDoctors=true and clinicModel=shared. The UI describes
multiple doctors in one clinic without promising an unconfirmed seat allowance.
The existing clinic-scoped enrollment quota remains unchanged.

## Access transitions

| Situation | Covered doctor | Enrolled patient |
| --- | --- | --- |
| Primary has a verified paid period | Active, unless manually restricted | Operations available |
| Renewal cancelled, period still paid | Access continues through paid-through | Operations continue |
| Paid period ends | Inactive, restriction notice | Still enrolled; operations disabled |
| Payment period restored | Valid members active again | Operations available again |
| Doctor manually blocked | Login blocked; funding unavailable | Operations disabled |
| Patient manually archived | Doctor unaffected | Remains archived after renewal |

The local access resolver evaluates verified payment periods and the configured
grace policy. It does not call Authorize.Net during dashboard requests. Invalid
cross-clinic or conflicting relationships also deny portal reads. Billing restrictions
otherwise preserve authorized history reads.

A request subscriber synchronizes the current managed doctor's active/inactive role.
The headless_subscription_members queue processes members in batches of 50 and
rechecks current funding before saving each role. Billing-owner user updates queue
member synchronization. Patient roles and program dates are never changed by this
funding evaluation. Account blocks are independent of billing and survive renewal.

The legacy request subscriber skips explicitly managed members. A narrow legacy
presave guard preserves their primary-doctor references when the user is edited or
roles are synchronized. The broader legacy dependency refactor remains deferred.

The shared session DTO includes funding and canManageDoctors. A covered doctor
cannot access payer billing mutations, payment details or nested doctor creation.
Patients receive operation access without the primary's private billing details.

## Clinic APIs and reusable fetch code

| Method | Drupal endpoint | Purpose |
| --- | --- | --- |
| GET | /api/headless/clinic | Clinic, locations, team and effective access |
| POST | /api/headless/clinic/doctors | Create covered doctor and send account invitation |
| PATCH | /api/headless/clinic/doctors/{id} | Edit owned secondary doctor |
| POST | /api/headless/clinic/doctors/{id}/access | Block/unblock owned secondary doctor |
| POST | /api/headless/clinic/locations | Add location within the primary clinic |
| PATCH | /api/headless/clinic/locations/{id} | Edit owned location |

Drupal routes are in headless_clinic.routing.yml. ClinicController validates
request origin, JSON shape and size and delegates to ClinicService. The service
resolves actor ownership, validates location membership and duplicate emails, and
serializes clinic mutations under payer/email locks. Transactions commit before
these locks are released. A secondary doctor can read the authorized roster but
cannot mutate it. Submitted clinic, owner and role overrides are rejected.

Invitation messages reuse headless_mail and the existing account/password setup.
Failed mail delivery leaves a real account with a clear notice and a Forgot password
fallback; duplicate email errors name the conflict.

Next.js exposes the same operations through /api/clinic and its allowed subpaths.
The reusable server fetch is frontend/src/lib/clinic/server.ts; the browser client
and shared response types are client.ts and types.ts in the same directory.
frontend/src/app/api/clinic/[[...path]]/route.ts validates and forwards these requests.
The authenticated chiropractor clinic page supplies initial server data to
ClinicPage; drawers use the shared browser client and only update the table after
a successful saved response. Errors preserve form input.

Drupal's /admin/commerce/portal-subscriptions/{uid} detail page uses adminTeam()
to show real related doctors and aggregated enrolled/archived patient counts.
Existing subscription administration permission is required.

## Key implementation files

- web/modules/custom/headless_custom/headless_clinic/src/ClinicService.php
- web/modules/custom/headless_custom/headless_clinic/src/Controller/ClinicController.php
- web/modules/custom/headless_custom/headless_subscriptions/src/Sponsorship.php
- web/modules/custom/headless_custom/headless_subscriptions/src/SubscriptionRepository.php
- web/modules/custom/headless_custom/headless_subscriptions/src/Plugin/QueueWorker/SubscriptionMembers.php
- web/modules/custom/headless_custom/headless_access/src/PortalAccessResolver.php
- frontend/src/lib/clinic/
- frontend/src/components/portal/clinic/
- frontend/src/components/portal/portal-access-notice.tsx

## Local setup and verification

The local database has run headless_subscriptions_update_10004 and enabled
headless_clinic. On another environment, deploy the source, run Drush updatedb,
enable headless_clinic and rebuild caches. Configure normal cron for role batches.
The update creates the membership table only; it does not adopt existing users.

Verification completed with fresh owned accounts:
- Subscription unit suite: 55 tests, 254 assertions.
- Access resolver suite: 11 tests, 60 assertions.
- Patient creation/re-enrollment suite: 70 tests, 143 assertions.
- Private service/controller checks: 24 checks, plus 2 final transaction checks.
- Browser and direct Drupal/Next.js API checks: 77 passed, covering paid
  cancellation, expiry, renewal, manual blocks and archives, patient assignment,
  billing privacy, secondary ownership, wrong-clinic denial, invalid origins,
  editing/location persistence, duplicate-email errors, mobile layout and admin data.
- PHP syntax validation: 40 files.
- Production Next.js build, TypeScript and targeted ESLint passed.

The fixture mailer intercepted all invitations. Synthetic local paid periods were
used for access transitions, with no gateway requests or charges. These checks
supplement the previously verified Authorize.Net sandbox checkout rather than
claiming a new gateway test. Disposable accounts and private fixture data are removed
after validation; existing subscription users are not modified for testing.