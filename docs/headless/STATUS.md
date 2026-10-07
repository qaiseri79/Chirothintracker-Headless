# Headless Build Status

Drupal 10 site at `chirothintrackerreact.lndo.site` being converted to a Next.js
frontend. This file is the handoff point for a fresh session.

Read these before touching anything:
- `ChiroThin — Patient Intake (Redesign).html` (repo root) — the agreed UI design
- `docs/headless/intake-blueprint.json` — generated field contract, 6 steps / 53 fields
- `docs/headless/form-spec.json` — raw form-display dump for both forms

## Ground rules

- Lando CLI is not on PATH. Use
  `wsl -d Ubuntu -e docker exec chirothintrackerreact_appserver_1 drush ...`
- The Drupal container mounts the project at `/app`. Webroot is `/app/web`.
  Tool scripts therefore run as `/app/tools/<file>.php`.
- PowerShell is the host shell. Avoid nested quoting through `wsl bash -lc`;
  write a script file and run it instead.
- Not a git repository. The user declined `git init` and will handle version control.
- Composer reports 53 advisories across 12 packages.

## Commands

```
# regenerate the form contract
docker exec chirothintrackerreact_appserver_1 drush php:script /app/tools/export_form_spec.php

# regenerate the design blueprint (run after any form or design change)
docker exec chirothintrackerreact_appserver_1 drush php:script /app/tools/export_intake_blueprint.php

# re-apply the JSON:API lockdown (idempotent)
docker exec chirothintrackerreact_appserver_1 drush php:script /app/tools/lock_down_jsonapi.php

# verify what an enrolled_patient may read
docker exec chirothintrackerreact_appserver_1 drush php:script /app/tools/check_patient_access.php
```

## What is done

### Next.js intake app (`frontend/`)

Next 16.3.6, React 19.2.8, Tailwind v4, shadcn/ui, App Router, TypeScript.
Blueprint synced into the app by `frontend/scripts/sync-blueprint.mjs`
(via `predev`/`prebuild`, fails loudly on staleness) because Turbopack refuses
imports outside the project root.

- `src/lib/intake/invite.ts` — the Drupal API contract:
  `GET /api/intake/invite/{token}` → `{token, status, brand, legal}` and
  `POST /api/intake/submit` → `{id, received}`. `TOKEN_SHAPE = /^[A-Za-z0-9_-]{16,128}$/`.
- `src/lib/intake/submit.ts` — allowlist mapper (blueprint → Drupal field
  payload). Datetime fields here are date-only (`datetime_type: date`) so dates
  are sent as `Y-m-d`, **not** `Y-m-dTH:i:s` — appending the time fails Drupal's
  validator (fixed from the earlier contract note).
- `src/lib/drupal/client.ts` — server-only client; `DRUPAL_BASE_URL` defaults to
  `http://127.0.0.1:62249` and forwards `SESS*`/`SSESS*` cookies.
- `src/app/intake/[token]/page.tsx` + `src/components/intake/*` — stepper UI.
- `src/app/api/intake/[token]/route.ts` — Next submit endpoint; maps Drupal
  404/409/410 → 410 `token_unavailable`.
- `frontend/.env.local` — `DRUPAL_INTERNAL_URL=http://127.0.0.1:62249`.

Run: `cd frontend && npm run dev` (inside WSL).

### Drupal intake API (`web/modules/custom/headless_custom/headless_intake`)

Implements the invite-token contract end to end. This code was moved out of
`ctt_patient_intake` (see "Module split" below); the public paths, table and
permission machine name are unchanged, only the owning module and the internal
namespaces/identifiers moved.

- `headless_intake.info.yml` — package `ChiroThin`, `configure:
  headless_intake.settings`, depends on `drupal:contact`, `drupal:user`, `eck`.
  **No dependency on `ctt_patient_intake`**: the two modules share no code, no
  config and no table, so a hard dependency would only be a false coupling. The
  module reads the `patient_intake` contact form and its field instances, which
  are site config, not something that module provides.
- `headless_intake.install` — schema for `headless_intake_invite` (token PK,
  clinic_id, status, expires_at, max_uses, uses, created), `hook_install()`
  grants `manage intake invite links` to `chiropractor_active_`,
  `hook_uninstall()` drops the table. **The table is this module's own**, named
  after it rather than after where the code used to live, because the code was
  moved and the two modules have no link to justify sharing a table.
  There is no `hook_update_N()`; nothing needed migrating.
- `config/install/headless_intake.settings.yml` + `src/Form/InviteSettingsForm.php`
  at `/admin/config/headless-intake/invite-settings` — the invite base URL and
  default max-uses/expiry now live in this module's own config. Nothing needed
  migrating: `ctt_patient_intake.settings` only ever held `mail_subject` and
  `mail_body`.
- `src/IntakeInviteService.php` (`headless_intake.invite`) — issue/resolve/
  consume/revoke (`consume` uses a row-lock transaction; Drupal has NO
  `Transaction::commit()`, commit is on scope exit), plus `resolveLegalText()`
  mirroring the blueprint's priority (field default → EPP value → legacy webform)
  and `applyTokens()` substituting `[site:name]`, `[site:url]` (falls back to
  request scheme+host) and `[current-page:query:clinicbrand]` (clinic
  `field_brand`). All collaborators are constructor-injected.
- `src/Controller/IntakeApiController.php` — anonymous
  `GET /api/intake/invite/{token}` (404 unknown; 200 with status + brand +
  fully-resolved legal blocks) and `POST /api/intake/submit` (clinic from token
  only; drops `field_clinic`/`field_domain`; field/option/length hardening;
  stores the server-resolved legal texts; saves a `contact_message` for form
  `patient_intake` → fires the notification hook). 404 unknown / 410
  expired·exhausted·revoked / 422 validation / 200 `{id, received}`.
- `headless_intake.routing.yml`, `headless_intake.services.yml`,
  `headless_intake.permissions.yml`, `headless_intake.libraries.yml` +
  `js/clipboard.js`, `src/Form/IntakeLinksForm.php`,
  `src/Form/IntakeLinkRevokeForm.php`, `src/Drush/Commands/IntakeInviteCommands.php`
  — route names are now `headless_intake.*` (nothing external referenced the old
  ones), paths are unchanged. Drush commands renamed `headless-intake:invite-*`
  with `ctt-patient-intake:invite-*` and `ctt-invite-*` kept as aliases.
  Command classes are discovered containerless, so they fetch the service via
  `\Drupal::service()` rather than constructor injection.
- **No menu links.** `headless_intake` deliberately ships no
  `*.links.menu.yml`, so nothing appears in the clinician navigation. The link
  manager is reached by URL at `/manage/intake-links`; invite settings at
  `/admin/config/headless-intake/invite-settings`.
- **Enabled and live.** `headless_intake` is installed; all five routes resolve and
  the link manager works. `ctt_patient_intake` no longer defines `/api/intake/*`
  or `/manage/intake-links`.
- **`IntakeLinksForm` must not declare its own `$requestStack`.** `FormBase`
  already declares an untyped `protected $requestStack` and a `getRequest()`
  helper, so a constructor-promoted typed property with that name is a fatal
  *"Type of … must not be defined (as in class FormBase)"* at class load. The form
  uses the inherited `getRequest()` instead. Worth remembering for any future
  `headless_*` form: check the parent for the property before injecting it.
- **Form signatures must match the interface exactly.** `FormInterface::submitForm()`
  takes `array &$form`, so a `submitForm(array $form, …)` is a fatal at class
  load. `IntakeLinkRevokeForm` had exactly that typo and took a second pass to
  find. `php -l` and PHPCS both pass on a signature mismatch, so neither is a
  substitute for loading the class. The check that actually catches it:
  `drush php:eval` with `class_exists()` over every form class. Worth re-running
  after touching any form.
- **`*.services.yml` must be updated when a constructor changes.** Adding
  `ConfigFactoryInterface` and `RequestStack` to `IntakeInviteService` without
  adding the matching `- '@config.factory'` / `- '@request_stack'` arguments
  leaves the service definition passing 2 of 4 arguments, and the failure only
  appears when something first calls the service — not at `drush cr`. A
  `drush php:eval "\Drupal::service('headless_intake.invite')"` smoke test covers
  it.
- **The legacy table is copied, then dropped — not renamed.** Drupal creates
  `hook_schema()` tables *before* `hook_install()` runs, so at install time the
  new table always exists and there is nothing left to rename onto; the first
  attempt used `renameTable()` and duly found both tables and did nothing.
  `_headless_intake_retire_legacy_table()` therefore does
  `INSERT … SELECT` and then `dropTable()`, which retires the legacy name
  outright — the intent — without discarding a token that was really issued. If
  the new table already holds rows the copy is skipped and logged rather than run,
  since a blind copy would collide on the token primary key.
  `hook_uninstall()` drops `headless_intake_invite` only.
- New `web/.htaccess` (standard Drupal 10.1) — the docroot had none, so clean
  URLs (and therefore `/api/*`) served Apache's own 404. `.lndo.site` deep URLs
  still 404 through the proxy; use the published port
  `http://127.0.0.1:62249` instead (Lando-generated, may change on restart).
- Verified end to end via the Next dev server *before* the split (as part of
  `ctt_patient_intake`): mint → invite (status/brand/
  legal with tokens substituted) → submit (201 `{id, received}`) → token
  exhausted → second submit 410; page `GET /intake/{token}` renders the clinic
  brand, unknown token shows "This link is no longer valid". Notification hook
  fired: `patient_intake notification sent to drjohn@onelightchiro.com
  (uid: 174) for clinic id 1`. Re-verification in the new module is pending
  enablement.
- **Failure-safe submit**: `consume()` now runs only after the shape checks,
  and the token is `release()`d if entity `validate()` finds violations or the
  save throws — a failed/422 submit no longer burns the link, so the patient
  can fix the field errors and resubmit with the same token. Next.js forwards
  Drupal's 422 `issues` verbatim instead of masking them behind a 502, so the
  form shows the exact field messages (e.g. the bundle demands a complete
  `field_mailing_address` — street, city, state, zip — on every submission).
- **422 messages now reach the patient**: Drupal reports issues under paths like
  `field_bp_diagnosis_year.0.value`, which matched no input name, so early 422s
  were invisible — the form just refused to submit. The form now reduces each
  issue to its owning input (`split(".")[0]`) for inline errors, any issue that
  still doesn't map to a visible step field is shown as a banner at the top of
  the form instead of being dropped, and the form auto-jumps to the step that
  owns the first rejected field. Verified: a full realistic 58-field state
  submits 201 (`{id, received}`) and exhausts the token; a payload with a
  diagnosis year < 1900 is rejected with Drupal's own message ("the value may be
  no less than 1900") surfaced inline.
- **Numeric bounds are now exported and enforced**: `export_intake_blueprint.php`
  reads `min`/`max` from the Drupal integer/decimal field settings (e.g. the
  diagnosis-year fields are bounded 1900–2100) into the blueprint; the number
  inputs carry `min`/`max` attributes, `validateStep` rejects out-of-range
  values for any filled number field ("Year Diagnosed must be 2100 or less.")
  before the round trip (on submit, the Next route enforces the same rules), and
  Drupal's own message remains the backstop. This was the user's actual blocker:
  "Year Diagnosed" was being rejected by Drupal (no less than 1900 / no greater
  than 2100) with no visible explanation.
- **Client-side validation now mirrors the bundle**: `validateStep` (run on
  every step advance client-side and for the whole form in the API route)
  covers required-blank, email format, telephone format, string `max_length`
  (255, exported into the blueprint), out-of-range/non-numeric numbers
  (integer/decimal/float), real calendar-date check for `date` inputs, and
  allowed-list membership for select/radio/checkbox-grid — so most bad values
  are flagged before Drupal is ever called. (Runtime additions, not stored in
  the blueprint: the phone pattern and date check.)

### Portal pages (`/`, `/login`, `/dashboard`, `/chiropractor`)

Scaffolded public + logged-in pages sharing the ChiroThin brand header (the
intake lockup extracted into `components/site-header.tsx`):

- `/` — home page: brand header with a header right-hand auth menu, "Coming
  soon" portal announcement, and a CTA that routes to `/login`.
- `/login` — real login card (email + password, sent to Drupal core's
  `POST /user/login`). **Shared by every portal account** — patients and
  chiropractors, active or read-only. It only authenticates; on success the
  visitor goes to `/dashboard`, which then routes them to the dashboard they
  actually belong on. No role decision is made in the form.
- `/dashboard` — the patient's **My Progress** page, built to
  `New Design/ChiroThin — My Progress.html`. Greeting with program day, Refresh
  Data button, five stat tiles, a hand-built SVG weight-vs-goal chart, and the
  log history (newest-first, newest expanded, each row expandable into
  measurements / food / notes, with a per-row menu). Both patient roles are
  admitted, `enrolled_patient` and `archived_patient`; an account in neither
  audience gets an access-denied panel, and a chiropractor is redirected to
  `/chiropractor`. See "Portal access: two audiences, two capability levels"
  below.
- `/dashboard` **and** `/chiropractor` now share one shell,
  `components/portal/portal-shell.tsx`: a 72px sidebar that expands to 256px on
  desktop hover, a mobile drawer, and a sticky header with account initials and
  logout. Nav is declared once in `lib/portal-nav.ts` and the two dashboards
  share the shell chrome but not the nav item set. The server guards stay in
  each route's tiny layout, so no client bundle now contains the guard logic.
- Route groups were reorganised for this: `app/(site)` holds the public pages
  and `app/(portal)` holds `/dashboard` + `/chiropractor`. URLs are unchanged.
- **Dashboard data is still hardcoded.** `lib/progress/data.ts` exports
  `getProgress()`, which currently returns the mock from
  `lib/progress/mock-progress.ts` — a verbatim transcription of the design's
  10 log entries and summary figures. That one function is the intended seam
  for the real backend; `types.ts` already describes the contract the API is
  expected to return. No patient data was consulted to build this.
- `/chiropractor` — chiropractor dashboard, still a **placeholder** (Patient
  intake / My patients / Schedule, all "Coming soon") but now rendered inside the
  shared shell. The chiropractor nav item set is inferred, not designed — it
  needs a design pass like `/dashboard` got.
- `metadata` per segment via tiny server layouts, which also carry the guards.
- Auth is a real Drupal session (`lib/auth.tsx`): `AuthProvider` re-validates
  the session on mount via `/api/auth/me` and exposes `user` + `status`
  (`loading` / `authenticated` / `anonymous`), so the dashboard waits for the
  session check before redirecting. The header menu toggles Log In ↔
  Dashboard/Log out. The old localStorage demo provider is gone. The endpoints
  behind it are described next.
- Routes verified on the dev server: `/`, `/login`, `/dashboard` and
  `/chiropractor` all serve 200, and both dashboards 307 to `/login` when
  anonymous; `/intake/[token]` and `/api/intake/[token]` are untouched.

### Real patient login (Drupal's own session API + a `headless_custom` module)
Patients are ordinary Drupal users, and modern enrollments use the patient's
email address as the account name (`PatientAccountWebformHandler` rejects an
email already taken as a name *or* a mail; the newest accounts confirm
name == mail, e.g. uid 91521 `e2e.patient@example.com`). The portal therefore
logs in against **core's** session endpoints — no custom login/logout code.
Core's routes (`user.routing.yml`, live because the `rest` module is enabled;
they need no REST-resource config, and `config` entries starting with `rest.`
are absent):

- `user.login.http` — `POST /user/login?_format=json`, body `{name, pass}`.
  `UserAuthenticationController::login` does the flood control, calls
  `user_login_finalize()` and returns
  `{current_user: {uid, name}, csrf_token, logout_token}` plus the session
  cookie. 400 on bad credentials.
- `user.logout.http` — `POST /user/logout?_format=json&token=<logout_token>`
  → 204. **The `_csrf_token: 'TRUE'` requirement is satisfied by the `token`
  query parameter, not by an `X-CSRF-Token` header** (the header is for REST
  resource routes; sending it yields 403 — verified). `CsrfAccessCheck` builds
  the token id from the route path, and the only place a valid one is issued is
  the login response.
- `user.login_status.http` — `GET /user/login_status?_format=json` → `"1"`/`"0"`.
- `system.csrftoken` — `GET /session/token` → `{token}` ('rest' token, *not*
  valid for `/user/logout`).

`web/modules/custom/headless_custom/` is the **container directory for the
headless API modules** (one module per API area, each named `headless_*`); it is
not itself a module. It holds:
- **`headless_session`** — the one thing core lacks: **which account a session
  belongs to**. Core can report logged-in vs logged-out but not the identity, so
  the frontend cannot restore the signed-in user on page load.
  `GET /api/headless/session` → `{id, name, mail, roles}`, or 401
  `unauthenticated`. It only ever describes the caller's own account, so it needs
  no permission of its own. `SessionController` is a plain `ControllerBase`
  (uses `currentUser()`), no services, no config, no dependencies.
- **`headless_auth`** — lets a patient log in with the email address they
  enrolled with when their Drupal username is not that address (208 legacy
  accounts; see `docs/headless/known-issues.md`). No routes: it replaces core's
  `user.auth` service with a subclass whose
  `UserAuthenticationInterface::lookupAccount()` falls back from `name` to
  `mail`. Drupal 10.3 added that method as the supported seam for identifier
  resolution, and core already calls it from the JSON login route, the core
  login form and basic_auth — so overriding the service updates all three with
  no new endpoint and no frontend change. Only the lookup widens: the password
  check, flood control, blocked-account handling, session creation, response
  payload and the generic failure message all stay in core, so it does not leak
  which addresses have accounts. A name always beats an email, so no existing
  login changes behaviour. Known gap: for the 208 accounts whose name is not
  their email, core's JSON route cannot key its per-account flood window (it
  looks the name up), so only the IP limit applies; the data fix in
  `known-issues.md` closes that too.

Drupal discovers modules in nested folders, so the grouping costs nothing. Adding
a second API module means a new `headless_custom/headless_<area>/` directory;
keep the `headless_` prefix so the family is obvious in `pm:list`.

Next.js (`frontend/src/app/api/auth/`) — a thin stateless proxy:
- `login/route.ts` — sends `{name: <email>, pass: <password>}` to core with
  `forwardSession: false`, then copies core's `Set-Cookie` onto the Next origin
  (`HttpOnly; SameSite=Lax; Path=/`) and parks core's `logout_token` in the
  HttpOnly cookie `ctt_drupal_logout_token` (no server-side store, and the
  token is session-bound).
- `me/route.ts` — forwards the session cookie to `/api/headless/session` and
  passes Drupal's status through (401 stays 401).
- `logout/route.ts` — replays the stored `logout_token` as `?token=` to core's
  logout, then expires `SESS*`/`SSESS*` and the token cookie.
- `client.ts` exports `SESSION_COOKIE` and `DRUPAL_LOGOUT_COOKIE` for the above.

Verified by curl against both Drupal and the dev server: anonymous `me` → 401;
wrong password → 400 with core's own message; login → 200 + `SESS…` cookie;
`me` with the cookie → 200 account; logout → 200 **and `me` afterwards → 401**,
i.e. Drupal's session is really destroyed, not just the browser cookie dropped.
The throwaway test user was deleted afterwards (it had a published password);
`User::create(['name' => <email>, 'mail' => <email>, 'pass' => …])` in a
`drush php:script` is the reliable way to make another (drush's own
`user:create --password` gets mangled by shell quoting).

Measured impact of that data condition (SQL over `users_field_data` +
`user__roles`; `user__roles` columns are `roles_target_id` / `langcode`, and
roles are config entities so there is no `role` table to join):

- **208 distinct patients** (any patient role) have `name <> mail` — 0.7% of
  the 27,678 `patient_chirothin` accounts. 200 are active, 198 have a password
  set, and **190 are active with a password**, i.e. real patients who will type
  the email they were given and be rejected.
- Examples: `uid 218 wakracke_218 <= wakracke@gmail.com`,
  `uid 220 Wiestfamily_220 <= Wiestfamily@hotmail.com`,
  `uid 195 ajojohnson2003_195 <= ajojohnson2003@yahoo.com`.
- Site-wide there are 652 users with `name <> mail`: 487 match the D7 migration
  pattern `<something>_<uid>` (the `wakracke_218` shape — a Drupal 7 migration
  artifact), 8 have a name that is a prefix of the mail, and 157 have a
  completely unrelated value (mostly junk accounts, e.g.
  `slasisi@pm.me <= bjs0c2e5molc@opayq.com`).
- 190 of the 652 are blocked (`status = 0`) and so could not log in anyway.
- Renaming `name` → `mail` would collide for only 3 accounts
  (uid 22654, 73384, 90287), each because the target string is already another
  account's name.

So the options are (a) a one-time data fix, (b) a resolver in the login path, or
(c) both. **Decision: (c)'s resolver now, the data fix later.** `headless_auth`
resolves the email in the login path (shipped — see above), and the one-time
rename is written up as a tracked issue in
[`known-issues.md`](known-issues.md) rather than done here, because it is a
production data change.

Environment notes for future work on this:
- In this Next build `(await cookies())` is **not** iterable as
  `[name, value]` pairs — iterating it throws `TypeError: store is not
  iterable` (surfaced as a 502 from route handlers). Use
  `(await cookies()).getAll()` → `{name, value}[]`, per
  `node_modules/next/dist/docs/01-app/03-api-reference/04-functions/cookies.md`.
- `npm` on the Windows side cannot run from the `\\wsl.localhost\...` UNC path
  (cmd.exe rejects a UNC cwd, `npm.ps1` is blocked by the execution policy). Run
  the frontend toolchain through WSL:
  `wsl -d Ubuntu -e bash -c "cd /home/danielsudenfield/chirothintracker_react/frontend && npm run lint"`.
- Passing JSON to curl: don't escape quotes through `bash -c '…'` (the
  backslashes survive and the body is no longer valid JSON). Write the payload
  to a file, use `curl -d @file`, and put multi-step shell logic in a
  `tools/*.sh` script rather than inlining it.

### Portal access: two audiences, two capability levels

`/login` is a **single shared form** for every portal account — patients and
chiropractors, active or read-only. It only authenticates; it makes no
authorisation decision. Access is settled in the route layouts, and the
per-account capability is a flag the dashboard components act on. This is
deliberate: refusing a chiropractor or an archived patient at the login door
would be an authorisation decision in the wrong layer, and it would break the
one form both audiences are meant to use.

The model lives in one place, `frontend/src/lib/portal.ts`, which is importable
by both server and client (so it carries neither `"use client"` nor
`server-only`):

| Audience | Full operations | Read-only: view + download own data |
| --- | --- | --- |
| Patient | `enrolled_patient` | `archived_patient` |
| Chiropractor | `chiropractor_active_` | `chiropractor_inactive_` |

`resolvePortalAccess(roles)` returns `{audience, readOnly, dashboard}`, or
`null` for an account in neither audience. "Read-only" is defined by the user as
being able to view and download one's own data but perform no operations — it is
a **component-level** concern, so the layouts admit read-only accounts and
`access.readOnly` is what disables their controls. Nothing is refused for being
read-only.

- `src/app/(site)/dashboard/layout.tsx` — patient guard. Admits **both** patient
  roles. A chiropractor is signed in legitimately but belongs on the other
  dashboard, so it redirects rather than refusing. An account in neither audience
  gets `components/portal-access-denied.tsx`.
- `src/app/(site)/chiropractor/{layout,page}.tsx` — chiropractor guard plus a
  **placeholder** dashboard. The route exists so a chiropractor has a correct
  destination and so the guard has somewhere to live; the panels are the same
  "Coming soon" placeholders the patient dashboard began with.
- `src/lib/auth.tsx` — `AuthUser` carries `roles`, and `login()` **re-reads the
  session** after core accepts the credentials. Required, not tidiness: core's
  JSON login response only includes `roles` when the account passes field access
  on its own roles field (`UserAuthenticationController::login()`), so it
  cannot be trusted to carry them — a patient logging in got `"roles": []`.
- Enforcing in a **layout** rather than the page is deliberate: both pages are
  client components, so a check there is only a rendering decision and a visitor
  who never runs the JavaScript would still receive the markup.
- `portal-access-denied.tsx` titles itself "This account doesn't have a portal"
  and lists the four roles. It is no longer the page an archived patient or an
  inactive chiropractor sees — those are real portal users with reduced
  capabilities.

Verified with six throwaway accounts (one per role state, created then deleted,
uids 91525–91530). Identity is read from the **layout's `<title>`**, which the
server emits — the page bodies are client components and render only "Loading…"
during SSR:

| Account roles | `/dashboard` | Lands on | Renders |
| --- | --- | --- | --- |
| anonymous | 307 | `/login` | — |
| `enrolled_patient` | 200 | `/dashboard` | patient dashboard |
| `archived_patient` | 200 | `/dashboard` | patient dashboard (**not** refused) |
| `chiropractor_active_` | 307 | `/chiropractor` | chiropractor dashboard |
| `chiropractor_inactive_` | 200 | `/chiropractor` | chiropractor dashboard, read-only |
| both chiropractor roles | 200 | `/chiropractor` | chiropractor dashboard, **active** |
| no portal role | 200 | `/dashboard` | access-denied panel |
| wrong password | — | — | 400, core's message |

Login returns 200 for every one of them. Zero hydration warnings, zero server
errors, `tsc --noEmit` and `npm run lint` clean.

`resolvePortalAccess` itself is asserted directly, compiled from the real
source, across all 13 role combinations (empty / `null` / `undefined` roles,
`authenticated`-only, `subscriber`-only, the historic `patient_chirothin`-only
account, each single role, the legacy both-roles case, and both cross-audience
conflicts) — 13/13. The two conflict resolutions are deliberately opposite in
direction, and both are covered there: **patient wins** an audience conflict, so a
stray `chiropractor_inactive_` can never cost a real patient their own dashboard;
**active wins** a capability conflict, so the 50 paying accounts above are not
locked down by a stranded read-only role.

### Role data: what the roles actually look like

Measured over `user__roles` + `users_field_data` (roles are config entities, so
there is no role table to join):

| Role | Accounts | `status=1` | Also holds the sibling role |
| --- | --- | --- | --- |
| `archived_patient` | 29,997 | 29,908 | `enrolled_patient` on **0** |
| `enrolled_patient` | 3,186 | 3,156 | `archived_patient` on **0** |
| `chiropractor_active_` | 331 | 140 | `chiropractor_inactive_` on **92** |
| `chiropractor_inactive_` | 290 | 279 | `chiropractor_active_` on **92** |

Three things this corrects or establishes:

1. **Patient roles really are exclusive** — 0 accounts hold both, so de-enrolment
   correctly swaps `enrolled_patient` → `archived_patient`. The precedence
   question raised earlier does not arise for patients.
2. **Chiropractor roles are *not* exclusive, and that is legacy fallout from
   `commerce_licence` / `commerce_recurring` — both being removed from headless.**
   92 accounts hold both, 90 of them able to log in. License role grants added a
   single role while other paths added both, so a demotion left the read-only
   role stranded beside the active one. The site's own code stopped trusting the
   roles: `custom_module.module:230` resolves the both-roles case by querying
   `commerce_subscription` for a live `state = 'active'` record. Consulted
   directly, the 92 split:

   | Has an active subscription | Count | Really |
   | --- | --- | --- |
   | yes | 50 | active |
   | no, and no subscription rows at all | 40 | inactive |
   | no, account blocked | 2 | inactive |

   So the active role is the accurate signal in 50 cases and the read-only role
   in 42. `CustomRequestSubscriber::onKernelRequest()` would normally reconcile
   this, but it only demotes an account that has `field_chiropractor_subscribers`
   set — true for only **21 of the 92** — so for the other 71 the both-roles
   state is permanent and the portal must tolerate it rather than assume it away.
   `resolvePortalAccess` therefore resolves both-roles to **active**. The
   asymmetry is deliberate: wrongly read-only-ing one of those 50 blocks a
   chiropractor who is paying and trading, whereas the 40 wrongly treated as
   active see a dashboard containing nothing but "Coming soon" and can be
   corrected before it holds patient data.

   **This leaves a real gap worth deciding explicitly:** once `commerce_recurring`
   goes there is no live subscription to consult, so *something* has to become
   the source of truth for chiropractor active vs inactive. Until then those 40
   accounts are read-only *because the role says so*, not because anything
   verified it. (`commerce_subscription` currently holds 50 active, 103
   canceled, 2 expired across the whole site, so a straight port of the existing
   state is not viable either — most subscribers have already churned.)
3. **`chiropractor_inactive_` is also a default role, so it does not mean "is a
   chiropractor" on its own.** `custom_module_user_presave()`
   (`custom_module.module:1919-1939`) grants `subscriber` +
   `chiropractor_inactive_` to every newly created user that is not already
   `enrolled_patient` and not `chiropractor_active_` — so a self-registered junk
   account inherits a chiropractor role and will be routed to the chiropractor
   dashboard. This was reproduced live: a drush-created patient account with
   only `archived_patient` came back holding `chiropractor_inactive_` as well.
   Production is unaffected today (0 accounts span both audiences) because real
   patients are created by the enrollment handler, which sets
   `enrolled_patient` before save and so skips that branch.
   `resolvePortalAccess` therefore prefers the **patient** audience when an
   account somehow holds both, rather than refusing it: misrouting a chiropractor
   to the patient dashboard costs a wrong page, while the reverse locks a real
   patient out of their own account over a role they never asked for.
   **Revisit before the chiropractor dashboard shows real patient data.** The
   alternative — requiring `chiropractor_active_` — would lock out the 198
   accounts that hold only `chiropractor_inactive_` (all of which have a clinic
   record, so they are real ex-chiropractors, not junk), so the exposure is
   accepted deliberately.
   Note that neither `field_chiropractor` nor `field_clinic` can arbitrate: they
   are set on the overwhelming majority of accounts in all four roles, because
   the same presave hook creates a clinic for every new user.

### Doctor-facing "Intake Links" page (`/manage/intake-links`)

Started from the observation that `/manage/intake` is a Views page
(`intake_forms_cs`, role-restricted to `chiropractor_active_`) and the doctor
needed a way to mint tokens without drush. Built in `ctt_patient_intake`, then
moved to `headless_intake` during the module split:

- Permission `manage intake invite links`
  (`headless_intake.permissions.yml`), granted only to `chiropractor_active_` by
  `hook_install()`. The machine name is intentionally the one the old module
  used, so any existing grant survives the move; changing it would silently
  revoke access.
- `src/Form/IntakeLinksForm.php` at `/manage/intake-links` — clinic select
  (chiropractors see only their own clinic via `user.field_clinic`;
  administrators see all), max submissions (default 1), expiry in days (default
  7), a "Generate intake link" action that shows the share URL with a copy
  button, and a table of existing tokens (status, created, expires, uses,
  copy/revoke) for the selected clinic.
- `src/Form/IntakeLinkRevokeForm.php` at `/manage/intake-links/revoke/{token}`
  — confirm-then-revoke, ownership-guarded to the current user's clinic.
- `headless_intake.libraries.yml` + `js/clipboard.js` — small copy-to-clipboard
  behavior on the generated link.
- Invite settings moved to their own form/config: `InviteSettingsForm` at
  `/admin/config/headless-intake/invite-settings` holding `intake_base_url`
  (public origin of the Next app; empty = current host, which would hand patients
  a broken link, so the page warns), `token_default_max_uses` and
  `token_default_expiry_days`. `PatientIntakeSettingsForm` is back to email-only.
- **No menu link.** An earlier version created an `Intake Links` entry (weight
  −49) in `menu-header-right`; the user does not want it in the doctor
  navigation and reaches the page by URL instead, so the menu link is not
  recreated in `headless_intake`. Any pre-existing menu entry from the earlier
  version was removed from the database during that earlier work.
- Ops: route uses `_permission: 'manage intake invite links'` (no `_admin_route`,
  so it renders in the front-office theme like the rest of the doctor UI).

Verified with real sessions: generate (HTTP 200, token row + share URL),
revoke confirm → 303 redirect → status `revoked`. Cross-clinic guard proven:
with the account switched to uid 174, the clinic options are exactly
`["": "- Select clinic -", "1": "One Light Chiropractic and Weight Loss"]`.

Notes on the environment met while testing:
- `drush user:login <name|uid>` here always issued a `/user/reset/1/...` login
  (falls back to UID 1 when the argument is not a valid login), so it cannot be
  used to probe other accounts via curl. Use `account_switcher->switchTo()` in a
  `drush php:script` instead.
- A 500 page that shows only "The website encountered an unexpected error" is a
  PHP fatal; check `drush watchdog:show --severity=Error`.
- The recurring `The URI 'base:1' is invalid` watchdog entry is from the
  `?destination=1` in the one-time-login URL, unrelated to this module.

Field facts learned while building: clinic is an ECK entity with bundles
`clinic`/`clinic_location`, brand in `field_brand`; `user.field_clinic` and
`contact_message.field_clinic` both target `clinic`; required bundle fields on
`patient_intake` include field_consent (typed-name signature), field_gender
(values `M`/`F`), and `field_mailing_address` demands a full address on every
saved message.

### JSON:API locked down

`jsonapi` and `jsonapi_extras` are enabled. Enabling `jsonapi` initially exposed
**258** resources, so `jsonapi_extras.settings` now has `default_disabled: true`
and only four taxonomy option lists are reachable:

`taxonomy_term--gender`, `--yes_no`, `--program_days`, `--weight_loss_phase`

Everything else returns 404, including `user--user`,
`contact_message--patient_intake`, `webform_submission--*`,
`patient_profile--*`, `profile--customer` and `clinic--clinic`.

Two traps in this module version (3.28):
- It has **no role-based access**. Only `disabled` toggles exist. Authorisation
  is left to Drupal's entity access, which is verified correct.
- A resource config created without `resourceFields` leaves it NULL, and
  `ConfigurableResourceType::getResourceFieldConfiguration()` calls
  `array_filter()` unguarded — a 500 on every request. Always set
  `resourceFields` to `[]`. The `path` value must be the bare resource name
  (`taxonomy_term--gender`), not `jsonapi/taxonomy_term--gender`, or the route
  double-prefixes.

`jsonapi.settings` has `read_only = TRUE` (pre-existing), so writes are
impossible over JSON:API regardless.

### Anonymous route access — gate disabled

`custom_module/src/EventSubscriber/RedirectSubscriber.php` used to 302 every
anonymous request on a non-whitelisted host to `/user/login` unless the path was
exempted (`/user/login`, `/user/password`, `/intake`, prefixes `/user/reset`,
`/room`, `/intake/`, `/api/`). Before the headless login work started, the whole
`checkRedirect()` body was commented out (method + event subscription kept, so
the dispatch still resolves) with a note to re-enable only if the frontend stops
owning the public routes.

Consequence: **anonymous requests are no longer redirected anywhere.** Drupal's
own `/user/*` pages still work (nothing intercepts them now); the headless
frontend owns the public entry points, and `/api/auth/*` + `/api/intake/*`
answer anonymous callers themselves. If the site is ever served from a bare
clinic subdomain again, this gate is the thing that must come back.

### Intake blueprint generated

`tools/export_intake_blueprint.php` produces `docs/headless/intake-blueprint.json`
from the design's layout plus live Drupal field values. It fails loudly on three
checks, all currently passing:

```
placed in blueprint : 53
unplaced (would be LOST): none
in design but not in Drupal: none
#states drift vs custom_module.module: none
```

Layout, step order, widget choice and widths come from the design file. Labels,
required flags, option lists, defaults and legal text are read live from Drupal
so the contract cannot drift.

Division of authority in the blueprint:
- `name` — the form input name to POST
- `drupalField` — the Drupal field to write
- `part` — address sub-component, or the second stressor input
- `widget` — `text` `email` `tel` `date` `number` `select` `textarea` `radio`
  `checkbox-grid` `repeatable` `static` `consent` `checkbox`
- `visibleWhen` — `[{field, op: eq|neq|notEmpty, value}]`
- `submitTransform` — `joinNewline` for repeatable fields and the second
  stressor input; absent on address parts, which map to distinct sub-fields
- `textSource` — which config key supplied a legal block

## Decisions taken

- Next.js and Drupal on the same host, using the Drupal cookie session.
- **Login, logout and login-status use Drupal core's own session API** — no
  custom code, in any module. Custom Drupal code is added only for what core
  genuinely lacks, and lives in `web/modules/custom/headless_custom/` (not in the
  older custom modules, which stay untouched). That path is a **container
  directory for the headless API modules**, one `headless_*` module per API
  area, not a module itself; it currently holds `headless_session`,
  `headless_auth` and `headless_intake`.
- **The intake code lives in its own `headless_intake` module, sharing nothing
  with `ctt_patient_intake`.** The two modules are fully decoupled: no
  dependency either way, no shared config, and the new module has its own table
  `headless_intake_invite` rather than inheriting `ctt_patient_intake_invite`.
  `ctt_patient_intake` is back to exactly what it was on the live site (the
  `patient_intake` email notification hook plus its email settings form), with
  one deliberate exception: the clinic-email code is commented out, which the
  user wants left that way for now. The split deliberately preserves the four
  public paths and the permission machine name `manage intake invite links` (so
  an existing grant survives); only namespaces, the service id, library id, route
  names, the config object and the table name changed. No menu link is created,
  per the user's decision to reach `/manage/intake-links` by URL.
- **One shared login form for both audiences; authorisation lives in the route
  layouts, capabilities in the components.** Patients (enrolled or archived) and
  chiropractors (active or inactive) all sign in at `/login`, which only
  authenticates. `frontend/src/lib/portal.ts` is the single source of truth for
  who goes where and what they may do. Read-only is a *capability*
  (`archived_patient` / `chiropractor_inactive_` may view and download their own
  data but perform no operations), enforced by disabling controls in the
  components rather than by refusing the page — so a read-only account is never
  locked out of its own dashboard. `patient_chirothin` is excluded as a
  discriminator because it is the broad historic role on ~27k accounts,
  including every archived patient. Portal authorisation lives in the frontend
  rather than in Drupal: the dashboards render no real data yet, and when they
  do, putting the same `resolvePortalAccess` check in front of each read keeps
  the rule in one place.
- Next.js with Tailwind CSS and shadcn/ui.
- JSON:API for genuinely shared reads only. Per-user and per-clinic data goes
  through custom `/api/*` endpoints, because JSON:API cannot express
  "only rows belonging to my clinic".
- Replace `?clinic_id=` with an opaque invite token at `/intake/{token}`.
  Server resolves clinic from the token; the request body must never be trusted
  to select a clinic.
- Diagnosis follow-ups (year + medications) are included as conditional fields,
  matching current `#states` behaviour.
- Consent keeps two checkboxes: general consent plus the media-release opt-out.
- Repeatable UI on the 5 string fields, joined with newlines on submit. No
  cardinality change, so no migration of the 8,656 existing submissions.
- Legal text is taken verbatim from Drupal, never from the design mock.

## Findings that must not be lost

1. **`field_clinic` is injectable.** Its EPP default is
   `[current-page:query:clinic_id]`, and the field is hidden. Changing the query
   parameter reassigns a submission to another clinic.
   `ctt_patient_intake.module` then emails that clinic's chiropractor, so it is
   both a data-integrity and a spam vector.

2. **The legal texts are injectable too.** `field_agreement` contains
   `[current-page:query:clinicbrand]` inside a 3,046-character EPP value.
   Blueprint reports the tokens:
   ```
   field_agreement            current-page:query:clinicbrand
   field_agreement            site:name
   field_program_agreement    site:name
   field_program_agreement    site:url
   ```
   All four must be substituted server-side from the token-derived clinic and
   site config, never from request input.

3. **The design mock drops a legal agreement.** The live form has a *Finance and
   Payment Agreement* — credit-card authorisation for the amount due at signing
   plus recurring finance charges — bundled inside `field_program_agreement`
   (1,994 chars). The mock omitted it. It is preserved.

4. **The mock's legal text is abridged.** Its Informed Consent ends
   `...may result in...`. The real text is 3,046 chars in EPP settings.
   `field_program_agreement` is 1,994 chars from EPP;
   `field_media_release_agreement` is 1,178 chars from `default_value`.
   Legal text sources, in priority order: field default, EPP value, then the
   legacy D7 `webform.webform.patient_intake` config as a fallback.

5. **Contact forms cannot be submitted through JSON:API.** `patient_intake` is a
   contact form with `contact_storage`; submission must go through a custom
   endpoint that preserves the `ctt_patient_intake` notification hook.

6. **Pre-existing bug, untouched.**
   `custom_module.module:661` uses `= ` instead of `== `:
   ```php
   if ($form_id == "contact_message_patient_intake_form" || $form_id = "contact_message_patient_intake_form_edit_form") {
   ```
   The assignment makes the condition always true, so those `#states` are applied
   to every contact form. Worth fixing separately.
   Impact is contained rather than visible: the guarded fields
   (`field_surgery_weight_loss`, `field_diabetes_diagnosis_year`, …) only exist
   on the intake form, so the rules are effectively applied where they should be,
   and the intended `..._form_edit_form` branch is dead code. The fix is one
   character each — `===` (or `==`) on both comparisons:
   ```php
   if ($form_id === 'contact_message_patient_intake_form' || $form_id === 'contact_message_patient_intake_form_edit_form') {
   ```
   Not applied: `custom_module` is an existing custom module, and the standing
   instruction is that new code goes in `web/modules/custom/headless_custom/`
   instead. This is a fix to old code in place, so it needs the user's say-so.

7. **Correction to an earlier false alarm.** An intermediate report claimed
   patients could read all 33,705 users and all 8,656 intake records. That was
   wrong: the probe used an *administrator* session that had been misidentified
   as a patient. `tools/check_patient_access.php` tests the entity access layer
   with a real `enrolled_patient` and every sensitive type is `view=denied`.
   The 258-resource exposure was real and is now closed; the patient-read
   vulnerability was not.

## Site facts

- Drupal 10.6.10, PHP 8.2, MySQL 8, DB prefix `drupal10_`.
- `patient_intake`: 55 fields, 12 field groups, 8,656 submissions.
- `tracking_weight`: 51 fields, 11 groups, 1,364,022 submissions.
- `message`: 2,042,467 submissions. `contact_message__field_weight`: 1,379,762 rows.
- `intake_form_template` node type has 0 nodes, so intake is one hardcoded form.
- ~33,129 users, ~34,447 patient profiles, 447 clinics, 129 clinic locations,
  224 Views, 22 roles.
- Table names: `contact_message__field_*`, `user__field_*`, `user__roles`,
  bundle keys are `contact_form` (contact_message) and `webform_id`
  (webform_submission), not `type`.
- Option lists live in `field.storage.contact_message.<name>.settings.allowed_values`
  as `[{value, label}]`; they are absent from the field definition's constraints.
  Values are positional (`0` = No, `1` = Yes) and the `#states` rules depend on
  them, so never reorder them.
- Anonymous requests through Lando all report `172.20.0.2`, so session IPs
  cannot identify who logged in.

## Next steps

1. **Set the real intake frontend URL.** `headless_intake.settings` →
   `Intake base URL` (/admin/config/headless-intake/invite-settings). It is
   unset, so `intakeBaseUrl()` falls back to the Drupal host and generated links
   point at `…/intake/{token}`, which Drupal does not serve. The Intake Links
   page shows a warning while it is unset.
2. **Wire the intake link into the app** — wherever clinic staff currently hand
   out `?clinic_id=` links becomes `/intake/{token}` shared from the new
   Intake Links page.
3. **Tell the doctors where the page is.** There is no menu link by request, so
   `/manage/intake-links` has to be shared directly, or bookmarked. Consider
   moving the token table into the `/manage/intake` view later if it gets
   crowded.
4. **Re-run `export_intake_blueprint.php` after any form change** and keep the
   three coverage checks green, then `npm run prebuild`/build in `frontend/`.
5. Lando may rebind the appserver port (currently `127.0.0.1:62249`); if the
   intake breaks, confirm with `docker ps` and update `frontend/.env.local`, then
   restart `npm run dev` — `.env.local` is read at server start, so a running dev
   server keeps using the old port. A stale port is silent and total: every
   server-side Drupal call throws, `/api/auth/me` answers **502**, and the
   symptom is a login that "does nothing" plus a dashboard that bounces to
   `/login`. Check `/api/auth/me` first when login breaks — 502 means the port,
   401 means the session.
6. **Fix the pre-existing `=`/`==` bug** in `custom_module.module:661` separately.
7. **Do the legacy-username data fix.** Tracked in
   [`known-issues.md`](known-issues.md) — 208 patients cannot log in with their
   email without `headless_auth`, and for those accounts per-account flood
   control does not apply. A one-time rename closes both.
8. ~~**Role-gate the portal.**~~ **Done** — routing and capability are in
   `src/lib/portal.ts`, enforced in the route layouts, with the shared
   `/login` staying role-agnostic. See "Portal access: two audiences, two
   capability levels".
9. **The chiropractor dashboard is a placeholder.** `/chiropractor` exists and
   guards correctly, but its panels are "Coming soon". `chiropractor_active_` is
   already the gate on `/manage/intake-links` and `/manage/intake`, and
   `user.field_clinic` scopes a chiropractor to one clinic — carry that scoping
   over rather than reinventing it.
10. **Wire `access.readOnly` into the components.** The flag is computed and
    tested but nothing consumes it yet, because the dashboards are placeholders.
    Each mutating control (and each read that would need a write token) should
    be disabled for a read-only account rather than relying on the server to
    reject the write — the server must still reject it either way.
11. **Decide what replaces `commerce_subscription` as the chiropractor
    active/inactive source of truth.** Being removed from headless, along with
    `commerce_licence`. Until something takes over, "both roles present" resolves
    to active in the frontend, which is right for the 50 accounts with a live
    subscription and wrong for the 40 without one. A data migration that strips
    the stranded `chiropractor_inactive_` would also fix it at the source, but
    that touches 92 live accounts and needs sign-off.
12. **Add "forgot password" to `/login`.** Password reset still lives on Drupal's
    `/user/password`; the portal either links out to it or gets a reset endpoint
    like the login one. Core's `user.pass.http` accepts a `mail` key as well as
    `name`, so email-based reset already works without any custom code.
13. **Revisit the classification before the chiropractor dashboard shows real
    patient data.** `chiropractor_inactive_` is granted to every new non-enrolled
    user, so a junk signup routes to the chiropractor dashboard. Harmless against a
    placeholder; not harmless against patient data.
14. **Revisit the gate when the roles change hands.** `enrolled_patient` is
    currently assigned by hand or by the enrollment webform handlers. If
    enrolment ever stops granting it, every new patient is locked out of the
    portal while still being able to log in — which reads as a bug report, not a
    permissions problem. Worth an explicit check that the enrollment path sets
    the role.
