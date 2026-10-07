# Subscription access integration — current status

Updated 6 October 2026. The access foundation and real shared-clinic chiropractor
management are implemented locally. See [Sponsored chiropractors](SPONSORED_CHIROPRACTORS.md)
for the implementation, API map and verification.

## Approved rules

- One clinic may have multiple doctors; its primary doctor owns the subscription.
- All subscription plans permit additional chiropractors in the shared clinic.
- New additional doctors receive covered access without buying a subscription.
- Patients retain their treating-doctor relationship.
- Cancelling renewal preserves access until the verified paid-through boundary.
- At expiry, covered doctors become inactive and their enrolled patients lose
  operation access. Patients remain enrolled; expiry never archives them.
- Verified renewal restores eligible operations. Manual patient archives and
  account blocks remain in effect.
- Secondary doctors cannot manage the primary doctor's billing or add more doctors.
- Existing clinical record permissions remain clinic-scoped under the legacy policy.

## Completed work

1. An explicit indexed membership table links new covered doctors to one payer and
   clinic. Relationship conflicts, nested sponsorship and independent billing
   conflicts are rejected.
2. Shared effective access follows patient → treating doctor → primary payer.
   It reads local verified payment periods; dashboard checks do not call the gateway.
3. My Chiropractors and Clinic Locations use authenticated live APIs for listing,
   creating/inviting, editing and blocking/unblocking doctors and adding/editing
   locations. Ownership and location scope are enforced by Drupal.
4. Doctor role synchronization supports covered members, with resumable batches.
   Access checks enforce expiry immediately even before a queue catches up.
5. Persistent dashboard notices explain restrictions and actions are gated on
   both the frontend and backend. Billing remains private to its owner.
6. Catalog descriptions reflect multiple doctors sharing one clinic.
7. Drupal's Portal subscriptions detail page lists the primary doctor's team,
   effective access and enrolled/archived patient counts.

## Deferred work

- Point 5 of the user's latest task list: separate patient allowance per doctor.
  Existing clinic-scoped quotas remain unchanged.
- Exact included additional-doctor limits and any downgrade/seat-selection policy.
  No new hard seat cap or claim of unlimited doctors has been invented.
- Reviewed migration of existing legacy sponsorships into the new billing system.
  Listing a legacy relationship does not silently adopt or create paid coverage.
- Refactoring remaining legacy custom-module dependencies.

No existing subscription user was used as a test fixture. Verification used only
new disposable accounts, intercepted invitation mail and private synthetic local
paid-period records; no payment-provider requests or charges were made.