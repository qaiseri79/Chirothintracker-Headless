# Doctor subscription backend

Implemented in `web/modules/custom/headless_custom/headless_subscriptions/`.
This module owns new-account subscription billing and management. Next.js uses the real catalog, checkout and billing APIs; legacy subscriptions remain separate.

## Ownership and compatibility

- One newly registered doctor owns one newly created clinic and its default location.
- `headless_subscription_account` is the explicit opt-in boundary. Existing users
  are not adopted, migrated, billed, or assigned new subscription roles.
- The catalog uses a NEW `portal_membership` Commerce product/variation type and
  platform store. It has no Commerce License or Recurring traits.
- Prices come from Commerce variations; logical plan IDs are 1, 2, 3, 4 and 72.
  These logical IDs are not existing Drupal variation IDs.
- Recurring billing belongs to ChiroThin's dedicated Authorize.Net account.
  Doctors' patient-store gateways are unrelated to this flow.
- The custom purchase ledger stores durable payment checkpoints and immutable
  plan snapshots. It does not invoke a Commerce checkout/payment flow in parallel.
- Existing Commerce Recurring/License integrations remain enabled for legacy
  subscribers. Legacy migration and module removal are later work.

## Backend APIs and frontend contract

Prefix: `/api/headless/subscriptions` on Drupal. All responses are JSON and
`private, no-store`. Mutations require `Content-Type: application/json`.

| Method | Path | Auth | Purpose |
| --- | --- | --- | --- |
| GET | `/plans` | Public | Plan list and public payment configuration |
| POST | `/cart/create` | Public/own account | Create/replace selected-plan cart |
| GET | `/cart?id=...` | Bearer cart / owner | Retrieve cart summary |
| POST | `/register` | Public | Create doctor and clinic; return `requiresLogin` |
| POST | `/checkout` | New managed doctor | Initial payment and recurring setup |
| GET | `/current` | New managed doctor | Subscription, capabilities and billing availability |
| POST | `/cancel` | New managed doctor | Stop future charges, preserve paid access |
| POST | `/payment-method` | New managed doctor | Replace tokenized saved billing method |
| POST | `/webhook/authorize-net` | Provider signature | Durable event receipt |

Existing login remains `POST /user/login?_format=json` on Drupal, through the
existing Next.js `/api/auth/login` proxy. Logout and password reset are reused.
`GET /api/headless/session` adds `subscription` and `capabilities` only for new
managed doctors. Registration deliberately does not create a second auth system
or log a user in implicitly.

The frontend agent should create subscription proxies using the existing Drupal
client/session forwarding utilities. Validate same-origin requests at Next.js
mutation proxies. Drupal rejects supplied browser Origins unless they match its
own origin or `headless_subscriptions_allowed_origins` in server settings.
Do not introduce a production simulation endpoint or treat UI success as payment.

### Plan shape

```json
{
  "id": 1,
  "variationId": 133,
  "name": "Newbie",
  "amountMinor": 9900,
  "price": 99,
  "currency": "USD",
  "intervalMonths": 1,
  "per": "month",
  "patientLimit": 4,
  "ecommerce": false,
  "laser": false,
  "trialPolicy": "none",
  "activationFeeMinor": 0
}
```

`variationId` above is illustrative and environment-specific. Use `id` to select
plans. `amountMinor` is authoritative; `price` is a display convenience. Map
`ecommerce` to the mock UI's `ecom`, and derive patient text from `patientLimit`
(`null` means unlimited). Annual billing has `intervalMonths: 12` and `per: year`.
The initial prices/limits match the frontend's mock plan definitions; production
commercial terms, platform store details and any tax rules still need confirmation.

### Registration

```json
{
  "email": "doctor@example.invalid",
  "password": "a-password-of-at-least-12-characters",
  "fullName": "Doctor Name",
  "clinicName": "Clinic Name",
  "acceptTerms": true
}
```

Password length is 12–128 characters. Only the explicit fields above are accepted.
Caller-supplied roles, user IDs and clinic ownership are never used. Email/account
names are checked globally and registration is rate-limited. New accounts have
billing access, not paid portal access. Login follows the normal existing flow.

### Cart and checkout

Create with `{"planId":1}`. Store the returned 64-character cart ID as a bearer
secret until login. Guest carts contain no account information, expire after one
hour by default, and become owned when checkout starts. Another doctor cannot
read a claimed cart. Cart prices are revalidated before payment; changed terms
produce `cart_changed` and require a new cart.

Use Authorize.Net Accept.js or its hosted AcceptUI form to tokenize card data.
Never send a card number or CVV to Drupal or persist the token in application logs.
The backend accepts only the opaque payment token:

```json
{
  "cartId": "64-character-cart-id",
  "idempotencyKey": "stable-random-key-for-this-attempt",
  "opaqueData": {
    "dataDescriptor": "COMMON.ACCEPT.INAPP.PAYMENT",
    "dataValue": "one-time-token-from-authorize-net"
  },
  "billing": {
    "firstName": "Doctor",
    "lastName": "Name",
    "zip": "90001"
  }
}
```

Optional billing fields: company, address, city, state and country. The
idempotency key must contain 16–64 letters, digits, underscores or hyphens.
Reuse the same key/cart for retries of one attempt; generate a new key and token
only after a definitive decline. Cart and account locks prevent concurrent checkout.

1. Record the attempt before the external call.
2. Charge the first billing period using `authCaptureTransaction`.
3. Persist the transaction and paid-through boundary before recurring setup.
4. Create a tokenized customer payment profile from the confirmed transaction.
5. Start ARB on the next billing date, not today; the first period is not billed twice.
6. Update subscription capabilities and compatible roles.

ARB's first scheduled renewal date anchors subsequent billing periods. For
example, a January 31 initial payment schedules February 28 renewal in a
non-leap year; that schedule then renews on March 28. The billing boundary is
02:00 America/Los_Angeles, matching the documented scheduled processing window.
A provider's actual processing delay is handled by the configurable grace policy.

An ambiguous response becomes `payment_review`/`renewal_review` and blocks another
charge. A paid first period retains access if recurring setup fails; the response
sets `needsReview`. Operator recovery verifies the receipt's merchant context,
customer ID, invoice reference and amount before activation. An uncertain ARB
creation cannot be blindly retried.

## Subscription access

`/current` returns `subscription`, `capabilities`, and `billing`.
Capabilities: `portalRead`, `portalWrite`, `store`, `laser`, `patientLimit`,
`paidThrough`, `inGracePeriod`, and `billingOnly`. Timestamps are Unix seconds.

- Never paid: billing-only access.
- Confirmed paid period: plan permissions and limits.
- Cancellation: stop ARB future collection; retain paid access until expiry.
- Previously paid and expired: clinic read-only; billing/resubscription remains available.
- Renewal overdue: grace period only if configured; default is **zero days**.
- Patient creation and re-enrollment share the subscription cap and a clinic lock.
- Archiving does not consume an enrollment slot; blocked patients do not count.
- Store-manager role and clinic e-commerce setting are synchronized with `store`.
- Expiry is enforced before route access, so a delayed cron cannot leave a paid
  write role effective indefinitely.
- The new doctor/clinic relationship cannot be changed through user or clinic saves.

Trials remain **request-only** marketing eligibility. Automatic trial activation,
trial duration, registration email verification, and
legacy subscriber migration are not implemented in this first milestone.
The annual design's requested laser add-on also needs a separate approval policy.
Refund events flag the purchase for review; partial refunds do not invent an
access-revocation rule. A void of the initial payment revokes paid write access.

## Configuration

Admin page: `/admin/config/people/portal-subscriptions`.
Initial configuration: registration enabled, payments disabled, zero grace days.

Use dedicated sandbox credentials, not copied production gateway credentials.
Add this to a local/server settings include and configure its environment variables:

```php
$settings['headless_subscriptions_authorize_net'] = [
  'environment' => getenv('AUTHNET_SUBSCRIPTION_ENVIRONMENT') ?: 'sandbox',
  'api_login_id' => getenv('AUTHNET_SUBSCRIPTION_API_LOGIN_ID') ?: '',
  'transaction_key' => getenv('AUTHNET_SUBSCRIPTION_TRANSACTION_KEY') ?: '',
  'public_client_key' => getenv('AUTHNET_SUBSCRIPTION_PUBLIC_CLIENT_KEY') ?: '',
  'signature_key' => getenv('AUTHNET_SUBSCRIPTION_SIGNATURE_KEY') ?: '',
];
$settings['headless_subscriptions_allowed_origins'] = ['http://localhost:3000'];
```

Enable payments in the admin page only after credentials are available. The public
API exposes the API login ID/public client key for Accept.js, never transaction or
signature keys. Disabling new checkout does not disable cancellation/reconciliation
or verified webhook handling when credentials remain configured.

Register the webhook URL at Authorize.Net. Notifications use HMAC-SHA512 and the
signature key; IDs are deduplicated with the billing account/environment context.
Only minimal event IDs/types are stored. Cron queues pending events, processes
verified notifications, and reconciles batches of this module's purchases.
Use a normal scheduled Drupal cron/queue setup in deployment.

Configure the NEW platform store's real contact/address details before launch.
No legacy credentials were inspected or reused during implementation. Real
Authorize.Net sandbox checkout has now been verified with dedicated local credentials; see the real sandbox validation below.

## Operator recovery

```sh
drush headless-subscriptions:reconcile PURCHASE_ID
drush headless-subscriptions:reconcile PURCHASE_ID --transaction-id=TRANSACTION_ID
drush headless-subscriptions:reconcile PURCHASE_ID --transaction-id=TRANSACTION_ID --subscription-id=ARB_ID
```

The last form attaches an existing uncertain ARB schedule only after its name,
amount and payment-profile IDs match the purchase. There is no public force-active
endpoint. If the provider confirms no schedule exists after an ambiguous ARB
request, an operator must review it before a separate recovery implementation
allows recreation; the backend does not guess or charge again.

## Validation performed

- Subscription lifecycle/provider tests: **27 tests, 81 assertions**.
- Existing patient creation/enrollment tests: **94 tests, 232 assertions**.
- HTTP verification with two newly created test doctors: plan list, registration,
  normal login, unpaid access denial, server price, unavailable payment rejection,
  duplicate registration rejection and foreign-cart denial.
- SQL-backed verification with simulated provider responses: one initial charge
  across retries, role activation, separate-doctor isolation, patient cap,
  paid-period cancellation and read-only expiry.
- Temporary simulated payment and patient records were removed. The two new test
  doctor accounts remain unpaid, IDs 91648 and 91649 in this local database.
- Frontend mock files and existing subscription users were not changed.

Run unit tests:

```sh
vendor/bin/phpunit -c web/core/phpunit.xml.dist web/modules/custom/headless_custom/headless_subscriptions/tests/src/Unit
vendor/bin/phpunit -c web/core/phpunit.xml.dist web/modules/custom/headless_custom/headless_patients/tests/src/Unit/PatientsServiceCreateTest.php
```

References: [Authorize.Net Accept.js](https://developer.authorize.net/api/reference/features/acceptjs.html),
[recurring billing](https://developer.authorize.net/api/reference/features/recurring-billing.html),
[webhook verification](https://developer.authorize.net/api/reference/features/webhooks.html).

## Real sandbox validation (2026-10-05)

Local dedicated credentials in `web/sites/default/settings.subscriptions.local.php`
were authenticated against `apitest.authorize.net`, and checkout was enabled in
`headless_subscriptions.settings`. Transaction/signature keys remain server-only.
The existing production-backed subscription users and Commerce credentials were
not modified or used for tests.

New doctor UID 91661 completed a real $99 sandbox checkout, CIM profile creation,
ARB setup, idempotent checkout replay, provider transaction/schedule verification,
reconciliation and cancellation. Sandbox transaction `80060505917` and ARB schedule
`9930699` identify those test records in the sandbox account. The ARB schedule was
cancelled to avoid future test renewals; its already paid period remains accessible.
New doctor UID 91662 verified a real decline and remains unpaid. Subscription unit
checks remain green at 27 tests and 81 assertions.

The local HTTPS checkout preview is `https://localhost:3443/subscribe`; startup
and browser testing instructions are in [SUBSCRIPTION_FRONTEND.md](SUBSCRIPTION_FRONTEND.md).
Accept.js rejects HTTP pages, including localhost.

Webhook signatures were verified with the configured signature key, including a
modified-body rejection. Real external delivery to
`/api/headless/subscriptions/webhook/authorize-net` is still unverified: this local
instance has no publicly reachable callback URL. Register the public HTTPS Drupal
endpoint and configure cron/queue processing before deployment. A future scheduled
renewal was not processed as part of this immediate checkout test.

## Doctor and administrator management (2026-10-05)

Subscription records continue to live in Drupal's custom tables. The new-account
billing flow avoids Commerce Recurring/License while retaining Drupal ownership.
Doctors manage their own subscriptions at Next.js `/billing`.

Additional JSON APIs under `/api/headless/subscriptions`:

| Method | Path | Access | Purpose |
| --- | --- | --- | --- |
| GET | `/management` | Own managed account | Masked card, current/pending plan, payments and allowed actions |
| POST | `/refresh` | Own managed account | Reconcile provider status and return management data |
| POST | `/change-plan` | Own managed account | Schedule a catalog plan for the next successful renewal |

Management returns the latest 50 payments and subscription attempts. Normal session
and access checks do not call the provider. Existing cancellation/card APIs are reused.

Drupal administrators use **Commerce → Portal subscriptions**:

- `/admin/commerce/portal-subscriptions`: paginated search and state filters.
- `/admin/commerce/portal-subscriptions/{uid}`: doctor, clinic, plan, access, masked
  card, payment history, provider transaction/schedule references and review details.
- Provider refresh and cancellation use native Drupal confirmation forms with CSRF
  protection. Cancellation preserves the paid period and does not issue a refund.

Pages require the restricted `administer portal subscriptions` permission. The
existing Administrator role receives it through Drupal's administrator-role behavior.
Doctor roles do not receive it. Configuration remains at
`/admin/config/people/portal-subscriptions`.

### Plan changes and payment policy

Plan changes now require a server quote and explicit confirmation. Upgrades charge
the prorated difference using the existing saved CIM profile, verify the receipt,
update/verify the renewal schedule and unlock the new plan immediately. The renewal
date and already-paid expiry stay unchanged. Downgrades require manual patient
archival when enrollment exceeds the new cap and activate at the next paid renewal.

The existing 24-hour cutoff and single-pending-change rule remain. Same-interval
changes update ARB amount; monthly/annual changes use an owned, verified replacement
at the paid-through boundary. Unknown charge or schedule responses remain durable
and block repeat submissions.

See [SUBSCRIPTION_PLAN_CHANGES.md](SUBSCRIPTION_PLAN_CHANGES.md) for API contracts,
paid-period normalization, recovery commands and validation.

Card replacement updates the CIM profile with an opaque Accept.js token, preserving
the billing address. Card management, cancellation and reconciliation remain
available when new checkout is disabled if provider credentials remain configured.

Update **10003**, applied locally, adds pending-change checkpoints, immutable initial
plan snapshots, schedule start metadata and the `headless_subscription_payment`
ledger. Payments are deduplicated by merchant context and transaction ID. No card
number or token is stored. Initial receipt verification uses the original price even
after plan changes. A confirmed initial-payment void revokes paid write access;
later reconciliation of the same receipt cannot restore it.

An operator can attach an already-created uncertain replacement with:

```sh
drush headless-subscriptions:reconcile PURCHASE_ID --subscription-id=VERIFIED_REPLACEMENT_ARB_ID
```

Recovery verifies the original receipt and candidate ownership/terms before saving
the replacement or cancelling the old schedule; it does not charge again.

### Management verification

- Subscription unit tests: **43 tests, 161 assertions**.
- Patient enrollment regression tests: **70 tests, 143 assertions**.
- Production frontend build/TypeScript and targeted lint passed.
- Real sandbox tests used fresh doctors 91663 and 91664 only, each with one $99
  approved payment and one purchase. Card update, monthly change, annual replacement,
  refresh and paid-period cancellation passed. The annual replacement starts at
  next renewal, with no immediate annual payment.
- Admin list/filter/detail/refresh/cancel and doctor access denial passed. The
  temporary administrator and its unique role were removed.
- Both owned test schedules were confirmed cancelled. Existing subscriptions and
  users were not modified. Private fixture credentials were removed.
- Public webhook delivery and a future scheduled renewal remain staging checks.

