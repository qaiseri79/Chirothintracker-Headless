# Subscription plan changes — implemented 2026-10-06

## Behavior

- Review is required before confirmation. The server determines plan type, prices,
  actual billing period, enrolled count and archival requirements.
- Upgrades unlock the target plan in the same request after the saved-card payment
  receipt and renewal configuration are verified. No waiting for the next renewal.
- Charge only the nonnegative prorated rate difference. Keep the paid-through date.
  Monthly/annual switches normalize both rates to the actual already-paid interval.
  Paid interval/start are preserved through successive mid-cycle upgrades and reset
  only when the next full renewal is verified. A lower equivalent rate with increased
  capacity can have zero due; no automatic refund is issued.
- Downgrades show all active enrolled patients across the shared clinic, including
  covered doctors' patients. Archived patients, blocked accounts and staff do not
  count. Newbie allows 4 and Rookie 10.
- If count exceeds the target cap, select patients, review their names and explicitly
  confirm archival. Only those accounts are archived through PatientsService.archive.
  Financial confirmation remains disabled until enrollment fits. Partial failures
  refresh actual state rather than assuming every archive succeeded.
- The backend validates every selected account belongs to the payer's clinic before
  archiving any selection. Patient list/archive APIs are primary-billing-owner only.
  Additional doctors cannot manage the primary doctor's billing.
- Eligible downgrades schedule the target plan for the next verified paid renewal.
  Current paid features continue until then. The lower enrollment cap is reserved
  as soon as the downgrade is scheduled; both primary and additional doctors see
  that reservation in their enrollment allowance. Creation/re-enrollment cannot
  consume slots beyond it.
- Recheck enrollment before activating a downgrade renewal. Unexpected legacy/admin
  overages preserve the payment receipt and flag review instead of silently activating
  an over-limit plan or automatically archiving patients. An administrator can archive
  the doctor-selected patients, then reconcile the verified renewal.
- Cancellation clears a scheduled downgrade and preserves the current paid plan
  until expiry. An uncertain upgrade payment must be reconciled before cancellation
  so the payment checkpoint cannot be discarded.
- Changes retain the existing cutoff: at least 24 hours before renewal. One pending
  operation at a time. Review quotes expire after 10 minutes; a submitted attempt
  remains recoverable after expiry.

## Shared implementation

- frontend/src/components/subscriptions/plan-change-panel.tsx: plan review, selected
  patient list and separate archival confirmation. Integrated into billing-page.tsx.
- frontend/src/lib/subscriptions/client.ts and types.ts: typed browser fetches.
- frontend/src/lib/subscriptions/format.ts: shared billing date and amount formatting.
- frontend/src/app/api/subscriptions/[...path]/route.ts: same-origin API proxy and
  allowed payload fields; prices, limits and arbitrary account IDs are not accepted.
- headless_subscriptions/src/PlanChanges.php: subscription-service trait for quotes,
  proration, payment checkpoints, patient selection and upgrade recovery.
- SubscriptionService.php: existing status/entitlements, schedule verification,
  renewal reconciliation and shared clinic enrollment guards.
- AuthorizeNetGateway.php: chargeProfile sends an authCaptureTransaction against
  stored customer/payment profile IDs; no raw card inputs are accepted by the app.
- SubscriptionRepository.php: durable quotes/attempts and atomic purchase/attempt
  completion. Payment ledger identifies prorated upgrades in Next.js and Drupal admin.
- Existing PatientsService.archive is reused; no second archival role implementation.

Paths above are relative to frontend or
web/modules/custom/headless_custom/headless_subscriptions as indicated.

## API contracts

Next.js proxies each route to the same suffix under /api/headless/subscriptions.

| Method | Browser endpoint | Body / result |
| --- | --- | --- |
| POST | /api/subscriptions/change-plan/quote | planId; returns owned quote, amount, expiry, target plan, dates, enrolledCount, mustArchive and eligible |
| GET | /api/subscriptions/change-plan/patients | All active enrolled patient IDs, names and emails in the primary doctor's clinic |
| POST | /api/subscriptions/change-plan/archive | planId, patientIds; explicit archival, then a fresh eligibility quote |
| POST | /api/subscriptions/change-plan | planId, quoteId; verifies ownership, target, catalog snapshot, paid-period fingerprint and enrollment before mutation |

A submitted quote ID is the stable idempotency key. Replaying a completed quote
returns current subscription status without a second payment. Definitive declines
require a new reviewed quote. Held/lost responses never automatically recharge.

## Storage and recovery

Update 10005 adds headless_subscription_change, paid_from, paid_interval and the
payment kind field. It is applied locally. Run normal Drupal database updates on
deployment before exposing the updated APIs.

Before charging, persist the attempt and pending purchase. Persist the returned
transaction before reading its receipt or updating ARB. Verify transaction ID,
amount, unique invoice, customer and approved state. A webhook can recover a lost
charge response by its unique invoice. Refresh resumes known paid checkpoints.
Unknown replacement schedules must be identified at the provider before adoption.

Operator command:
    drush headless-subscriptions:recover-upgrade QUOTE_ID TRANSACTION_ID
Optional --subscription-id=VERIFIED_REPLACEMENT_ARB_ID identifies a previously
created replacement after a lost response. Use none for TRANSACTION_ID when a
zero-charge upgrade only needs schedule recovery. Candidate ownership, amount,
reference, profile and start date are verified; this command never charges again.

Upgrade refunds/voids flag billing review. Partial refunds do not automatically
guess a new access period or archive patients.

Provider references:
- https://developer.authorize.net/api/reference/features/customer-profiles.html
- https://developer.authorize.net/api/reference/features/recurring-billing.html

## Validation

- Subscription unit suite: 75 tests, 350 assertions, including consecutive upgrades,
  monthly/annual normalization, zero-charge upgrades, expired/stale/foreign quotes,
  definitive declines, held/lost responses, no duplicate charge, verified operator
  recovery, late enrollment, downgrade quotas and upgrade refund review.
- Patient suite: 106 tests, 249 assertions. Access suite: 11 tests, 60 assertions.
- Targeted ESLint, TypeScript and Next.js production build passed.
- Fresh sandbox doctor only: initial $99 paid period, real Next.js quote/upgrade
  charged $100 and activated Rookie synchronously, preserving expiry. Replaying
  confirmation produced no additional payment. Three more patients could then be
  re-enrolled immediately through the existing patient service.
- Real downgrade: seven enrolled patients displayed, including covered doctor's
  patients. A bypass attempt and a mixed own/foreign patient selection were refused.
  Selecting alone changed no roles. Explicitly archiving three selected patients
  reduced enrollment to four and enabled Newbie scheduling without another charge.
- 42 browser/API checks plus 18 final backend checks covered clinic/account isolation,
  same-origin protection, mobile layout, payment history, exact archival selection,
  reserved allowance, re-enrollment denial at the reserved limit and cancellation.
- The test renewal was cancelled; fresh test users, clinics, local billing records
  and provider profile were removed. Test emails were suppressed. Existing users
  and subscriptions were not changed.
- Future real renewal and public webhook delivery remain staging checks. Renewal
  receipt activation and webhook recovery were exercised with isolated unit fixtures.
