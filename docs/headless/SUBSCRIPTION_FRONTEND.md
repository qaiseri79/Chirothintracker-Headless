# Next.js doctor subscription integration

The anonymous homepage uses Drupal's subscription catalog. `/subscribe?plan=ID`
opens the approved subscription design with that plan selected. The former
`/design-preview/subscribe` redirects to the real flow; timer-based fake purchases
and dummy plan prices have been removed.

## Shared code

- `frontend/src/components/marketing/pricing.tsx`: server-rendered homepage pricing.
- `frontend/src/app/(marketing)/subscribe/page.tsx`: catalog loading and selected plan.
- `frontend/src/components/subscriptions/subscribe-flow.tsx`: cart, account, checkout,
  confirmation and payment recovery state.
- `frontend/src/components/subscriptions/payment-fields.tsx`: browser-only Authorize.Net
  Accept.js fields and tokenization. No card number or security code is posted to Next.js.
- `frontend/src/lib/drupal/subscriptions.ts`: shared server catalog fetch.
- `frontend/src/lib/subscriptions/client.ts`: shared browser API calls and errors.
- `frontend/src/lib/subscriptions/types.ts`: backend DTOs.
- `frontend/src/lib/subscriptions/plans.ts`: one catalog-to-design adapter.
- Existing design `steps.tsx`, `subscribe-fields.tsx`, `subscribe-primitives.tsx`
  remain reusable presentation components.
- `frontend/src/app/api/subscriptions/[...path]/route.ts`: explicit operation allowlist,
  same-origin POST validation, no-store responses and Drupal session forwarding.

## API mapping

| Browser request | Drupal request | Caller |
| --- | --- | --- |
| GET `/api/subscriptions/plans` | GET `/api/headless/subscriptions/plans` | Public; server-rendered pages fetch Drupal directly through the shared server client |
| POST `/api/subscriptions/cart/create` | POST `/api/headless/subscriptions/cart/create` | Selected backend plan ID only |
| GET `/api/subscriptions/cart?id=ID` | GET `/api/headless/subscriptions/cart?id=ID` | Restore the same checkout attempt |
| POST `/api/subscriptions/register` | POST `/api/headless/subscriptions/register` | New doctor and clinic; explicit terms consent |
| POST `/api/auth/login` | POST `/user/login?_format=json` | Existing shared session login after registration or returning-doctor login |
| GET `/api/auth/me` | GET `/api/headless/session` | Restore account and subscription capabilities |
| GET `/api/subscriptions/current` | GET `/api/headless/subscriptions/current` | Current authenticated managed doctor's payment/access status |
| POST `/api/subscriptions/checkout` | POST `/api/headless/subscriptions/checkout` | Cart, stable payment key, billing address and opaque provider token |

## Behavior

Registration requires email, full name, clinic name, a 12–128-character password,
matching confirmation and terms acceptance. Recurring-payment consent is a separate,
initially unchecked checkbox. The existing password-reset route is linked from login.

Backend subscription capabilities are retained in client and server session models.
New unpaid doctors go to `/subscribe` instead of clinical pages; patient and legacy
account routing continues to use existing role rules when capabilities are absent.
Previously paid read-only doctors retain their backend-authorized read access.

The order summary uses server prices and currency. Paid confirmation requires the
backend's `portalWrite` capability. Provider review displays a pending status instead
of a success message. Legacy and patient accounts are not migrated by checkout.

An interrupted payment retains its cart ID, plan ID and idempotency key in tab session
storage. No passwords, billing addresses or payment tokens are stored there. Reloads
and ambiguous retries reuse the same attempt. An expired cart can replay that attempt;
the backend checks durable attempts before cart expiry. Definitive declines clear the
failed attempt, allowing corrected payment details to start a new one.

## Payment availability and validation

Dedicated Authorize.Net sandbox credentials are now configured in the local Drupal
settings include, and checkout is enabled. Credentials were authenticated against
the sandbox and the Accept.js public client key was checked against that account.
Copied legacy production gateway credentials were not read or reused. If checkout
is disabled or credentials are missing, the form still saves the new account without
granting portal access and explains that payment is unavailable.

Accept.js requires HTTPS even for sandbox checkout. The local production preview is
available at `https://localhost:3443/subscribe`. On HTTP, the form explains that HTTPS
is required and disables card fields. The separate HTTPS preview binds only to the
loopback interface and uses a mkcert certificate; it leaves the existing dev server
and production startup command unchanged. Local certificates may need to be trusted
in the browser, particularly when the browser runs on Windows and mkcert runs in WSL.

To restart the HTTPS preview after building, run from `frontend/` in WSL:

```sh
npm run build
DRUPAL_INTERNAL_URL=http://127.0.0.1:57577 npm run preview:https
```

Use the current Lando HTTP port if it changes after a restart. The HTTP backend URL
is local loopback traffic and avoids disabling TLS verification globally. The preview
command generates ignored certificates under `.local-https/` if they are missing,
requires mkcert, and supports an optional `HTTPS_PREVIEW_PORT` (default 3443). Use the
normal hosting TLS configuration in deployment.

Validation: production build, TypeScript, lint on changed files and 29 browser checks
against the real local Drupal backend and newly created test accounts (UIDs 91658 and
91659). These accounts remain unpaid. Successful payment, interruption/retry, review
and decline paths were verified with browser-only provider/API response interceptions;
that earlier validation executed no real Authorize.Net charge. Final verification added 14 focused payment-recovery checks (including expired carts and definitive declines) and 9 shared access-rule checks. Mobile checkout was checked at 390px. The refreshed production preview on localhost:3000 was also verified. Lint completed with zero errors and existing warnings in portal files.

Provider reference: https://developer.authorize.net/api/reference/features/acceptjs.html
Backend configuration and lifecycle: [SUBSCRIPTION_BACKEND.md](SUBSCRIPTION_BACKEND.md).

### Real sandbox verification (2026-10-05)

- Created a new doctor through the Next.js registration form (UID 91661). Its unpaid
  account had no portal access. Real Accept.js tokenization over HTTPS, a $99 sandbox
  payment, CIM profile creation, ARB renewal setup and the paid confirmation passed.
- Replaying the same checkout returned the same purchase and created no second
  purchase. Fourteen provider/lifecycle checks confirmed payment amount, invoice,
  customer, payment profile, renewal date, signature validation, reconciliation and
  cancellation. The test ARB schedule was cancelled after verification; this doctor
  retains access through its already paid sandbox period.
- Created a second new test doctor (UID 91662) through the registration service for a
  real sandbox decline. Eight checks verified the clear duplicate-email error,
  decline feedback, access denial, retry availability, discarded declined attempt,
  and HTTP/HTTPS behavior. This account remains unpaid.
- Used Authorize.Net's public Visa test card `4111111111111111`, expiry `12/29`,
  CVV `900`, ZIP `46214` for approval and ZIP `46282` for decline.
- Targeted lint, production build/TypeScript and subscription unit tests passed
  (27 tests, 81 assertions). Existing subscription users were not modified.
- External webhook delivery and a future scheduled renewal were not executed in
  this local test. Configure a publicly reachable HTTPS Drupal webhook endpoint
  and scheduled cron/queue processing before deployment.

Testing reference: https://developer.authorize.net/hello_world/testing_guide.html

## Doctor billing management (2026-10-05)

Open `/billing`, or use **Subscription & Billing** in the managed doctor's sidebar.
The page remains accessible to authenticated unpaid and expired managed doctors
without granting clinical access. Legacy doctors are not automatically enrolled.

- `frontend/src/app/(billing)/billing/page.tsx` loads the session, catalog and
  management data through the shared server clients.
- `frontend/src/components/subscriptions/billing-page.tsx` displays current/pending
  plans, billing dates, access, masked card, payments and subscription attempts.
  Refresh, card update, plan change and cancellation use asynchronous JSON calls.
- `frontend/src/lib/subscriptions/client.ts` implements those browser API fetches.
- `frontend/src/lib/drupal/subscriptions.ts` implements the server-side fetches.
- `frontend/src/app/api/subscriptions/[...path]/route.ts` validates and forwards
  the allowed operations, with same-origin checks for mutations.
- Shared `payment-fields.tsx` waits for the provider's AcceptCore handshake before
  enabling Accept.js tokenization. Only opaque tokens reach Next.js or Drupal.

| Browser request | Drupal request | Purpose |
| --- | --- | --- |
| GET `/api/subscriptions/management` | GET `/api/headless/subscriptions/management` | Own billing details and allowed actions |
| POST `/api/subscriptions/refresh` | POST `/api/headless/subscriptions/refresh` | Reconcile provider status |
| POST `/api/subscriptions/payment-method` | POST `/api/headless/subscriptions/payment-method` | Update the saved card using an opaque token |
| POST `/api/subscriptions/change-plan` | POST `/api/headless/subscriptions/change-plan` | Confirm a server-priced upgrade or downgrade |
| POST `/api/subscriptions/cancel` | POST `/api/headless/subscriptions/cancel` | Stop renewal and retain paid access |

Upgrades now activate after a verified prorated payment and renewal update; the
existing paid-through/renewal date stays unchanged. Downgrades require enrollment
to fit the target limit and activate at the next confirmed renewal. Changes still
require at least 24 hours before renewal, and only one pending change is allowed.
See [SUBSCRIPTION_PLAN_CHANGES.md](SUBSCRIPTION_PLAN_CHANGES.md) for the review,
manual archival, payment recovery and validation details. Cancellation preserves
the already paid period.

### Management validation

Real sandbox tests used only fresh doctors 91663 and 91664, each with one approved
$99 initial payment. Masked card display, real Accept.js card replacement, monthly
plan change, monthly-to-annual replacement, refresh and cancellation passed. No
immediate plan-change payment was captured. Both test schedules were cancelled
afterwards; paid access remains. The temporary test administrator and role were
removed after validating the Drupal list, filters, details, refresh and cancellation.

Cross-origin mutations, raw card input, foreign user IDs, anonymous management and
doctor access to admin pages were rejected. Desktop and 390px mobile layouts were
checked. Production build/TypeScript and targeted lint passed (zero errors; existing
unrelated portal warnings remain). Subscription unit tests: **43 tests, 161 assertions**.
Patient enrollment regression tests: **70 tests, 143 assertions**.

Public webhook delivery and a future scheduled renewal still require verification
on publicly reachable staging with cron/queue processing.


## Legacy plan description parity (2026-10-06)

Next.js homepage pricing and the production subscription funnel share
frontend/src/lib/subscriptions/plans.ts. Common clinic, availability and support
wording lives in frontend/src/lib/subscriptions/copy.ts; FAQ uses those same
constants. Drupal remains the source of prices, periods, enrollment limits,
activation fees, store/laser entitlements and trial policies.

Legacy sources:
- https://chirothintracker.com/
- https://chirothintracker.com/product/1

Restored descriptions cover unlimited data, clinic locations, additional doctors
in the same clinic, fees, monthly contract terms, trials and store/laser differences.
The annual card now displays the complete shared feature list.

The Newbie product body explicitly excludes e-commerce, despite the generic
homepage list including the label. Next.js follows the product body and the actual
catalog entitlement. Veteran/All Pro trials require contacting support; checkout
is paid. The annual laser bonus is request-only rather than automatically enabled.

Video conferencing and the patient storefront are explicitly unavailable in the
new portal. Legacy compliance statements are not treated as evidence of a compliance
audit of the new implementation; the old FAQ claim that video conferencing works
has been replaced with its actual availability.

The description update itself did not change billing. The subsequent implemented
upgrade/downgrade flow is documented in [SUBSCRIPTION_PLAN_CHANGES.md](SUBSCRIPTION_PLAN_CHANGES.md).

### Reviewed downgrade direction (now implemented)

The doctor should see enrolled patients, target plan capacity and how many patients
must be archived before becoming eligible. Archival is a deliberate doctor action,
never an automatic consequence of changing or cancelling a subscription. Use the
published limits (Newbie 4, Rookie 10) rather than the legacy cancellation/rebuy
message's 3/9 thresholds. Use the existing clinic-wide enrollment count, including
patients of covered doctors.

Downgrades activate at the next renewal, with current paid features preserved
until then. The lower enrollment allowance is reserved when scheduled and shown in
the enrollment banner. Eligibility is checked again before applying a renewal.
The review displays the effective date, target allowance and all enrolled patients
when archival is required.

Validation: targeted ESLint and production Next.js build/TypeScript passed.
31 read-only browser checks covered all five live plans, shared descriptions,
trial/add-on disclosures and 390px mobile layout. No accounts or payments were
created or modified.