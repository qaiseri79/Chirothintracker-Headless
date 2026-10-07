# Known issues

Deferred work that is deliberately **not** implemented yet. Each entry says what
is wrong, what is currently covering for it, and what "done" looks like.

---

## Legacy patient usernames are not email addresses (data fix wanted)

- **Status:** open — deferred by decision on 2026-09-26. The interim resolver
  (`headless_custom/headless_auth`) is in place; the data itself is untouched.
- **Area:** Drupal user data, not the frontend.

### What is wrong

Drupal matches logins against the account **name**. Accounts created by the
current enrollment flow already have `name == mail`, so those patients log in
with their email address. Accounts migrated from Drupal 7 do not: they are named
after the person, with the uid appended — `wakracke_218`,
`Wiestfamily_220`, `drjohn_174`, `ajojohnson2003_195`, `bgauslow_223`.

The mass email sent to patients says to use "the same login email address and
password", so the affected patients are told to type an address that is not
their username.

### Measured impact

| Measure | Count |
| --- | --- |
| Distinct patients (any patient role) with `name <> mail` | **208** |
| …of the 27,678 `patient_chirothin` accounts | 0.7% |
| …active (`status = 1`) | 200 |
| …with a password set | 198 |
| **…active with a password — real logins that will fail** | **190** |
| Blocked users among all 652 mismatched accounts site-wide | 190 |

Per patient role: `patient_chirotin` 208, `enrolled_patient` 41,
`archived_patient` 173, `patient_white_label` 3, `patient_mind_set` 4. The
distinct total is 208 because every mismatched patient carries
`patient_chirothin`; the other roles are subsets.

Site-wide, 652 accounts have `name <> mail`: 487 match the D7 `<x>_<uid>`
migration pattern, 8 have a name that is a prefix of the mail, and 157 are
unrelated values (mostly junk signups, e.g. `slasisi@pm.me <=
bjs0c2e5molc@opayq.com`).

### Interim mitigation (shipped)

`web/modules/custom/headless_custom/headless_auth/` overrides core's
`user.auth` service so `UserAuthenticationInterface::lookupAccount()` falls back
to the mail column. Core still does every part of the authentication. Because
that seam is used by the JSON login route, the core login form and basic_auth,
all of them now accept an email address.

One consequence to keep in mind: core's JSON login route keys its **per-account**
flood-control window off a *name* lookup
(`UserAuthenticationController::getLoginFloodIdentifier()`), so for these 208
accounts only the IP-based limit applies (default 50 attempts / 6 h) instead of
the per-account limit (5 / 6 h). Accounts with `name == mail` — that is, all
newer patients — are unaffected, since their name lookup succeeds. **The data fix
below closes this gap as well**, which is the strongest argument for doing it.

### Proposed fix (one-time data update)

Rename the affected accounts so `name == mail`:

- Select users holding a patient role where `name <> mail`.
- Skip any whose `mail` is already another account's name. There are exactly
  **3** such accounts, and they need a human decision:
  | uid | current name | mail | collides with |
  | --- | --- | --- | --- |
  | 22654 | `dr.ashleyemel@gmail.com` | `dr.ashleyengle@gmail.com` | uid 28456 `dr.ashleyemel_28456` |
  | 73384 | `loudenbeckdc@gmail.com` | `loudenbeckdc1@gmail.com` | uid 77060 `loudenbeckdc_77060` |
  | 90287 | `golddawg2@verizon.net` | `armbrustn72@gmail.com` | uid 90288 `golddawg2@version.net` |
- Blocked accounts can be renamed too, or left alone; they cannot log in either
  way.
- Run it as a queued operation in
  `web/modules/custom/headless_custom/headless_username_repair/` with an
  `hook_update_N`, writing a `uid → old name` mapping to keyvalue first so the
  change is reversible.

**Do not** rename the 157 unrelated accounts (junk signups) as part of a patient
cleanup; they are a separate data-hygiene question.

### Why this is the better end state

After the rename, core alone handles email login — on the JSON route, on the
Drupal login form, in password-reset flows, and in any future tool. The resolver
becomes a no-op safety net rather than a requirement, and the flood-control gap
disappears.

### Risks

- It is a production data change. Uid-keyed references (intake submissions,
  commerce orders, messages) are unaffected; only the displayed account name
  changes. Audit logs and any report that prints `%user.name` will show the new
  name.
- Passwords are not touched, so no patient is locked out by the rename itself.
- If a patient's email later changes, the account would drift again — but that is
  already true for every modern account.

### Acceptance criteria

1. `SELECT COUNT(*) FROM users_field_data u WHERE u.name <> u.mail` returns 0 for
   accounts holding a patient role, excluding the 3 decided exceptions.
2. A patient with a legacy name can log in with their email address at
   `POST /user/login?_format=json`.
3. Per-account flood control applies to those accounts again
   (`user.http_login` keyed by uid).
4. `headless_auth` can be uninstalled and login still works.

### Useful queries

```sql
-- Distinct affected patients.
SELECT COUNT(DISTINCT ur.entity_id)
FROM user__roles ur
JOIN users_field_data u ON u.uid = ur.entity_id
WHERE ur.roles_target_id IN
  ('patient_chirothin', 'enrolled_patient', 'archived_patient',
   'patient_white_label', 'patient_mind_set')
  AND u.name <> u.mail AND u.uid > 1;

-- Rename collisions.
SELECT a.uid, a.name, a.mail, b.uid, b.name
FROM users_field_data a
JOIN users_field_data b ON a.name = b.mail AND a.uid <> b.uid
WHERE a.name <> a.mail;
```

Note when writing SQL against this schema: `user__roles` stores the role in
`roles_target_id` (plural) and the language in `langcode`; roles are config
entities, so there is no `role` table to join.
