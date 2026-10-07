# Add New Patient — enrollment allowance design

Implemented from New Design/ChiroThin — enrolled_patient_count_design.html.

The Add New Patient tab shows the configured plan name, patients allowed, currently
enrolled patients, slots available and a usage bar. The existing patient form and
intake-prefill flow remain in use. Near the limit (10 percent remaining or two
slots, whichever is greater), the banner uses the artifact's warning treatment.
At the limit, the form is replaced by the pause card and the tab icon becomes a
lock. Over-limit counts show zero available and a maximum 100 percent bar.

Unlimited is explicitly different from an unavailable allowance. Unlimited plans
keep the form available without a misleading percentage. If the backend has no
plan/package metadata, the banner reports that the allowance is unavailable and
leaves enrollment eligibility to the existing backend guard.

## Data and reuse

GET /api/headless/patients adds enrollmentAllowance to the existing snapshot:
planName, limit, used and remaining. No new frontend fetch or gateway request is
needed. used reuses the existing SQL enrolledCount, excluding archived accounts,
blocked accounts and staff without the enrolled role.

SubscriptionService::enrollmentAllowanceForClinic reads the purchased plan
snapshot, not a scheduled future plan or the artifact's mock Professional plan.
Primary and covered doctors in the same clinic receive the same current clinic
allowance. Legacy clinics can display their configured enrollment package without
adopting the new billing system. A managed clinic without plan metadata cannot
fall back to old package billing terms.

The frontend adapter in src/lib/patients/data.ts validates the metadata and
distinguishes null/unlimited from unavailable data. src/lib/patients/enrollment.ts
holds the shared full/near/percentage calculation. enrollment-allowance.tsx renders
the reusable banner and pause card. The Add form and header consume the same
snapshot. A server quota conflict refreshes the snapshot while retaining form state.

Only the billing owner receives an Upgrade plan link, which opens /billing.
Covered doctors are directed to their primary doctor. The pause card can return
to the real patient list. The patient summary displays the selected location's
name rather than its numeric ID.

This change implements the display and full-capacity form state. Existing
clinic-scoped enrollment enforcement remains unchanged; separate per-doctor
allowances and unconfirmed additional-doctor seat limits are not introduced.

## Verification

- Five owned fixture checks confirmed count accuracy, purchased plan terms,
  billing privacy and primary/covered-doctor snapshot consistency.
- 44 browser/direct API checks passed across under-limit, near-limit, full,
  over-limit and unlimited states. Existing form validation and direct backend
  quota denial were verified for both doctors.
- Four final capacity-state checks passed after sharing the calculation and
  matching the locked-tab icon.
- Desktop review and mobile widths of 390 and 320 pixels passed without page
  overflow or browser runtime errors.
- Next.js production build, TypeScript and targeted ESLint passed.
- Subscription regression suite: 55 tests, 254 assertions. Modified PHP syntax passed.

Tests used only fresh disposable accounts and synthetic local paid-period data.
They made no provider payment requests and sent no invitation emails. Owned test
accounts, dependent fixture data and private credentials are removed after testing.