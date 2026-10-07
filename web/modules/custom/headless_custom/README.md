# Conventions for new headless modules

Read this before writing anything under `headless_custom/`. It records the rules
that the existing modules follow, and the reason each rule exists, so the next
module can follow them instead of rediscovering them.

Where a rule exists because something broke, that is said. A convention whose
reason is "the previous module did it this way" will be copied and then copied
again, and the copy is where the drift comes from.

---

## 1. Access

### 1.1 Never declare a custom permission for a portal endpoint

Gate routes with a policy class from `headless_access`. Do not create a
`permissions.yml` permission and do not grant roles from `hook_install()`.

The arrangement this replaces was live in three modules at once and had already
drifted: `headless_patients` declared `access headless patients`, `headless_progress`
declared `access headless progress` and granted it to the patient roles,
`headless_messages` declared `access headless messages` and granted it to all
four. Nothing in code held those three lists together, so each module was a
separate place for the answer to silently differ, and one had.

### 1.2 Pick the policy that matches the audience

| Class | Admits | Use for |
| --- | --- | --- |
| `ReadChiropractorAccess` | `chiropractor_active_`, `chiropractor_inactive_` | Reading the clinic roster |
| `WriteChiropractorAccess` | `chiropractor_active_` only | Changing anything in the clinic |
| `PortalMemberAccess` | all four roles | A caller's own data: own progress, own threads, own profile, and the shared content library |

Read-only is expressed by *which* list a policy names, not by a separate
permission. Cancelling a subscription removes the ability to change the clinic,
not the ability to see it. So `chiropractor_inactive_` and `archived_patient`
appear in the read list and not the write list, and `WriteChiropractorAccess`
names only the active role.

`PortalMemberAccess` is for endpoints where a chiropractor and a patient both
legitimately arrive and each sees something different. Progress is that case.

**No policy admits `administrator`.** `PortalRoles::PORTAL_MEMBERS` is the four
portal roles. Administrators do their work in `/admin`, and an admin session
carrying the same API as a patient would turn the tenant boundary into a policy
statement rather than a guarantee. See §5.6.

### 1.3 Role machine names live in `PortalRoles` only

Never type a role string in a module. Reference the constant:

```php
use Drupal\headless_access\PortalRoles;

// Wrong: 'chiropractor_active_'
if ($account->hasRole(PortalRoles::CHIROPRACTOR_ACTIVE)) { ... }
```

`patient_chirothin` is retired, appears on roughly 27k legacy accounts, and is
intentionally **not** in `PortalRoles`. A policy enumerates roles, so an
unlisted role is denied. Those accounts cannot reach headless endpoints until
they are moved to `enrolled_patient` or `archived_patient`. Adding it to
`PortalRoles` would silently re-open every gated endpoint at once — that is the
whole reason the list is one class.

### 1.4 A policy admits; it does not narrow

Holding an allowed role gets a caller to the controller. What the response
contains is decided by the query in the service that owns the endpoint,
because that is the only place that knows what "own" means for those records.

Do not put row filtering into an access class. `MessagesService::getThread()`
and `ProgressService::getProgress()` already scope to the caller; a policy that
also filtered would be a second, competing implementation of scoping, and the
two would drift.

A new module with own-records data must scope in its service. That is not
optional — `PortalMemberAccess` alone will return every patient's progress to
every patient.

### 1.5 The `Route` type hint is Symfony's

The access signature is inherited from `PortalRoleAccess`:

```php
public static function access(Route $route, AccountInterface $account): AccessResultInterface
```

`Route` means `Symfony\Component\Routing\Route`. There is no
`Drupal\Core\Routing\Route` — that directory holds RouteMatch, RouteProvider,
RouteBuilder, and no plain `Route`. Typing it as the Drupal one is plausible and
fails **at request time, not compile time**: the argument resolver runs
`new \ReflectionClass()` on whatever the type hint names, so an unresolvable name
throws "Class does not exist" from inside the access check, and every route
using that policy 500s with a trace pointing at core rather than at the
mistake.

Parameter names here are documentation; the **types** are the contract. Only
`Route`, `RouteMatchInterface`, `AccountInterface` and `Request` are supplied.

### 1.6 Adding a new policy

Subclass `PortalRoleAccess`, declare `allowedRoles()` and `policySubject()`,
nothing else:

```php
final class PortalSchedulerAccess extends PortalRoleAccess {
  protected static function allowedRoles(): array {
    return PortalRoles::CHIROPRACTOR_READ;
  }
  protected static function policySubject(): string {
    return 'chiropractor';
  }
}
```

Prefer adding a list in `PortalRoles` to writing a new class. A new class is for
a genuinely new audience; a new set of existing roles is a new constant.

---

## 2. Routing

### 2.1 `_custom_access` goes in `requirements`

`requirements` is the only block Drupal evaluates for access. `_auth` is an
**option**, read by AuthenticationManager, so it belongs in `options`.

```yaml
requirements:
  _custom_access: '\Drupal\headless_access\Access\PortalMemberAccess::access'
  _method: 'POST'
options:
  _auth: ['cookie']
  no_cache: TRUE
```

### 2.2 Never hand-write `_access: 'TRUE'`

AccessAwareRouter adds that requirement to every route. Writing it yourself
only obscures whether a gate is actually present. If a route has no
`_custom_access` and is not public, that is a bug.

### 2.3 One path, methods disambiguated

Drupal matches one route per path. Two routes on the same path with different
methods cannot coexist: the first wins for every method. This broke
`headless_messages`, where `conversations` shadowed `sendMessage` and a POST
returned the list.

Either one route with `_method: 'GET|POST'` branching in the controller, or
distinct paths.

### 2.4 Declare literal routes before placeholder ones

`messages/mass` before `messages/{target_uid}`. They cannot clash if the
placeholder is constrained to `\d+` — write that constraint regardless — but
ordering makes the intent obvious.

### 2.5 No CSRF token on POST routes, as things stand

The session cookie is forwarded from the Next.js server and is HttpOnly, so a
cross-site form post cannot carry it, and no browser JS here holds a cookie.
Adding a token check without first giving the client a way to obtain a valid
token makes every write 403 in a way that reads as a permissions problem.

This is a property of the current deployment, not a general rule. Revisit if
browser-side JS ever touches the session cookie.

---

## 3. Controllers

### 3.1 Every endpoint returns JSON

`JsonResponse` on all paths, including errors. A Drupal 500 HTML page reaches
the frontend as an opaque parse failure; `{"error": ..., "message": ...}` reaches
it as something loggable.

### 3.2 Every action catches `\Throwable` and logs it

This is not decoration. `ProgressController::current()` had no handler, and the
result was that one malformed field on one weigh-in took down the entire
dashboard as "the website encountered an unexpected error", with nothing in the
response saying which field. The handler added there is the reason the next
occurrence was diagnosable in one command instead of three rounds of guessing.

```php
try {
  $service = \Drupal::service('headless_module.my_service');
  $data = $service->doThing();
}
catch (\Throwable $e) {
  \Drupal::logger('headless_module')->error(
    'Thing failed for uid @uid: @message (@class: @line)',
    [
      '@uid' => \Drupal::currentUser()->id(),
      '@message' => $e->getMessage(),
      '@class' => get_class($e),
      '@line' => $e->getLine(),
    ]
  );
  return new JsonResponse(['error' => 'server_error', 'message' => $e->getMessage()], 500);
}
```

Log under the module's own channel, so `watchdog:show` filters to one module.
Include uid, class and line — a bare message costs a round trip.

### 3.3 Check existence before ownership

`load()` the entity, 404 if absent, then compare owner, 403 if it is not the
caller's. The other order lets a caller use the 403 to confirm that somebody
else's record exists.

`currentUser()->id()` returns a **string**. Cast both sides to int before a
strict `!==`, or every comparison fails.

`Message` has no `getOwnerId()` — that is `EntityOwnerField` and
`contact_message` does not use it. Read the owner from the `uid` field:

```php
$owner_id = (int) ($message->get('uid')->getValue()[0]['target_id'] ?? 0);
```

### 3.4 Writes are an allowlist, never a catch-all

`$entity->set()` accepts any base field as well as any real field. Without an
allowlist a client can post `uid`, `ip_address` or `created` and rewrite them —
the `uid` one being a straight reassignment of the record to another account.
See `ProgressController::mapFields()` for the shape.

Skip empty values rather than writing `""`, or a cleared field becomes an
empty-string value instead of staying empty.

### 3.5 Post-save work failures are logged, not reported

If a record is already saved and follow-up work then fails, report success and
log the failure. Reporting a failure invites a resubmit, which produces a
duplicate. See `ProgressController::submit()` around `runEvaluations()`.

---

## 4. Services

### 4.1 `load()` takes one ID; `loadMultiple()` takes a list

Both read as `->load(` at a glance and take the same argument shape in the
source, which is exactly why this gets confused. The signatures are different:

```php
$entities = $this->loadMultiple([$id]);
return $entities[$id] ?? NULL;      // EntityStorageBase::load(), line 263
```

`load()` uses its argument as an array **key**. Pass it an array and PHP raises
`TypeError: Illegal offset type`, before the `?? NULL` can apply. The
`Illegal offset type` in a watchdog log is this, and it names core line 263
rather than the module that caused it.

This cost a full debugging round on the progress dashboard, where the message
and the line number were identical to a *different* `Illegal offset type` that
had already been fixed. The two are distinguished only by the argument shape.
Worth knowing next time the same log line appears.

### 4.2 Read every field the same way

`getFieldValue()` falls through to returning the raw value array when a field
holds more than one item, so a single-value read returns a nested array for a
field that in practice has one selection. That asymmetry is what broke
`field_{prefix}_protein` and 500'd the dashboard.

Pass the multi-value flag explicitly and consistently for every field in a
family, even where the field "only ever has one value".

### 4.3 Tolerate field shape, do not assume it

Field values reach PHP as arrays, as `FieldItemList` objects, or as
`NULL`, depending on whether the field exists, is empty, or has cardinality.
Normalise at the boundary and coerce to `string` at the point of use.

A defensive read that cannot crash is better than a correct read that assumes
one shape. Date parsing in particular: return empty rather than throw.

### 4.4 Drop IDs that no longer resolve

Term loads resolve labels; a term that has been deleted since submission should
be omitted, not emitted to the frontend as a bare number.

Batch the load (`loadMultiple`) — one query for the list rather than one per ID.

### 4.5 Null and empty mean different things

Decide per field and write it down in the docblock. `note` empty → `null`;
`other` empty → `''`. A frontend that renders `"null"` or hides a real zero value
is usually tracing back to a service that picked the wrong one.

Related: decide whether an absent field is sent as a missing key or as `null`.
`headless_content` omits it. "This bundle has no such field" and "the field
exists and is empty" are different states, and a missing key can only mean one of
them.

### 4.6 Serialise by allowlist, never dump the entity

`$entity->toArray()` returns everything on the entity, including `uid`,
`revision_user`, and whatever field the site adds next year. Fine for a private
admin API; wrong for anything a patient reads. `ContentService` therefore maps
payload key → field name per bundle (`TEXT_FIELDS`, `TERM_FIELDS`, `FILE_FIELDS`,
`STRING_FIELDS`) and reads only those.

Per bundle rather than one flat list, because a resource has no `body` at all and a
recipe has no `field_resource`. A single map would return `null` forever for fields
that cannot exist, and a key that is always null is indistinguishable from a bug.

### 4.7 Re-check visibility in PHP even when the query filtered

`ContentService::listBundle()` filters with a query *and* calls
`scope->canSee()` on each result. That looks redundant, and it is deliberate:
the query is the fast path, `canSee()` is the definition, and they are two
implementations of one rule. The re-check is what makes a mistake in the query
harmless rather than a leak.

### 4.8 Distinguishing "not yours" from "does not exist"

Same 404 for both, same as `PatientsException::notFound()`. Separate responses
let a caller probe the uid space.

### 4.9 `EntityQuery::count()` is destructive — clone before counting

`count()` sets `$this->count = TRUE` and nothing ever resets it. A query that has
been counted stays in count mode, so the **next** `execute()` on the same object
returns the integer again instead of an array of ids:

```php
$query = ...->condition('type', $bundle);
$total = $query->count()->execute();     // int
$ids   = $query->sort('title')->execute();  // STILL the int, not ids
$nodes = $storage->loadMultiple($ids);   // TypeError: Illegal offset type
```

That last line is the same `Illegal offset type` as §4.1, from the same cause
class — a query object treated as if it were a value. Core hits this too and solves
it the same way; `QueryBase::initializePager()` does `$count_query = clone $this`
before counting.

Clone **before** adding `sort()` and `range()`, so the count covers the whole match
set rather than one page of it. This is what `ContentService::listBundle()` does,
and it is why the count is a clone rather than a second hand-written set of
conditions — the second set would be a second implementation of "which rows match",
and it is the one the pagination numbers come from.

### 4.10 A cap that truncates silently is worse than no cap

The recipe library is 634 published rows. `ContentService::LIST_LIMIT` was 200, and
the response's `total` reported the count of what came back rather than what
existed. So 434 recipes were unreachable by any request, and the response said
`total: 200` as though that were all of them. Nothing looked broken.

Pagination replaced it: `page` and `pageSize` query parameters, `pageCount` of at
least 1 so a frontend never divides by zero, and `totalItems` meaning what exists.

The two ends of the range get different answers, and the asymmetry is the lesson.
`pageSize` above `MAX_PAGE_SIZE` is **clamped**: that is what stops a client turning a
bounded query into an unbounded one, and over-asking is a reasonable thing for a UI to
do while it works out its layout. A value **below 1 is a 400**, because it once
clamped to 1 and the clamp was the bug — see §4.13.

### 4.11 Route placeholders match controller arguments by name

`{page_size}` supplies `$page_size`, not `$pageSize`. A `$pageSize` parameter
against a `{page_size}` placeholder is simply not supplied and falls back to its
default, so every page size a client asked for is silently ignored and it looks like
pagination is broken rather than like a typo. This is the mirror of §1.5: there the
*type* is the contract, here the *name* is.

### 4.12 Mirroring a legacy View means copying its filter, not its label

`headless_content` replaces three Views, and each one had to be read out of
`config/sync` rather than inferred from its block name. The blocks are called
"Shared block" and "Clinic Block", which is exactly the kind of label that
invites a rule the config does not contain:

| View / display | Actual row filter |
| --- | --- |
| `recipes` `block_2` ("Clinic Block") | `field_published_to` → clinic → reverse `user.field_clinic` → `uid_current`. Relationship required. |
| `recipes` `block_3` ("Shared block") | Identical filters. Plus exposed search on title, `field_recipe_category`, `field_recipe_type`, and the `favorites` flag. |
| `training` `block_1` / `block_2` | Identical to each other: same `uid_current` filter, same required relationships. |
| `resources` `embed_1` ("Clinic Resources") | `field_published_to` = an `entity_target_id` argument; `resources_test` passes the row's clinic id. |
| `resources` `embed_2` ("Shared Resources") | Handed `arguments: ''`, which Views treats as no argument — **no clinic filter at all**. Every published resource. |

Three consequences, all encoded in `ContentScope`:

- `clinic.field_hide_shared_resources` changes *which block renders* for recipes
  and training, and *which rows come back* only for resources. Encoding it as a
  row filter everywhere would invent a difference the site does not have.
- "Shared" is not an empty `field_published_to`. It is a display with no clinic
  filter. No node in any of the three bundles has an empty audience field, so a
  service that modelled shared as "no targets" would return nothing.
- A node with no clinic on it matches **neither** recipes display, because the
  relationship is `required: true`. `canSee()` fails closed for the same reason.

Two deliberate divergences, both worth stating rather than hiding:

- **Archived patients.** All three Views gate on `administrator`,
  `chiropractor_active_`, `enrolled_patient` — `archived_patient` is absent. The API
  does not reproduce that; `PortalMemberAccess` admits them and they read the same
  rows as an enrolled patient. If that is wrong, the fix is a bundle check in
  `ContentScope::visibility()`, not a route change.
- **Text formats.** Recipes and training bodies carry `allowed_formats: {}`, so any
  format is storable. `RENDERABLE_FORMATS` lists only the restricted ones and
  escapes everything else to plain text, same as `MessagesService`. If a recipe
  renders as visible markup, its format is not on that list.

### 4.13 Folding `page=0` into page 1 hides the bug it causes

`normalisePaging()` clamped `page` below 1 to 1, on the reasoning that a pager UI
which has not computed its own page yet should get a working page rather than a 400.
Measured on this site, that reasoning was backwards.

`listBundle()` turns the page into `range(($page - 1) * $pageSize, $pageSize)`, so
`page=0` is `range(-30, 30)`. MySQL reads the negative offset as zero, so `page=0`
returns page one. A client that indexes pages from 0 and walks `0..pageCount`
therefore asks for page one twice and concatenates both:

| `page_size` | `pageCount` | rows the client ends up with |
| --- | --- | --- |
| 10 | 12 | 126 |
| 25 | 5 | 141 |
| **30** | **4** | **146** |
| 50 | 3 | 166 |

A clinic with 116 published recipes arrived in the frontend as 146 rows. Every
individual response was well-formed, `totalItems` said 116, `pageCount` said 4, and
the status was 200 throughout — which is why this presented as "the API returns the
wrong number" and looked like a query problem. It was a 400-shaped client bug, and the
clamp was what stopped it from being visible.

So `page < 1` and `pageSize < 1` are now 400s
(`ContentException::pagingOutOfRange()`), while an oversized `pageSize` is still
clamped. The rule: **adjust a value that has a sensible substitute, refuse one that
does not.** `pageSize=99999` has an obvious intended meaning; `page=0` names nothing.

The lesson generalises past paging. A clamp is not a lenient answer, it is a decision
about what the caller meant — and when the caller was wrong, the clamp converts a loud
mistake into a silent wrong number. Prefer the status code.

### 4.14 The audience field is 456 clinics wide, so it is not serialised

`audience` carried `clinicIds`: every clinic the node was published to, per row. A
legacy bulk import left `field_published_to` holding **456** targets on a single
recipe — node 21 spans clinic 1 to 620 — and 450 of the 456 clinics resolve to the
same 116 recipes. So the array was not just large, it was uninformative: it told the
reader almost nothing their own scope had not already told them.

Measured payload, before and after, clinic 13, `page_size` at the default-ish sizes:

| | Before | After |
| --- | --- | --- |
| 10 items | 27.1 KB | 10.0 KB |
| 30 items | — | 29.8 KB |
| 200 items | 311.2 KB | 112.7 KB |

Per row that is 2.7 KB → 1.0 KB, so the saving scales with the page rather than being
a fixed one-off.

`audience` is now `mode` alone. `ContentScope::publishedClinicIds()` stays — it is how
`canSee()` reads the audience — but it is a decision input, not a response field.

---

## 5. Module structure

```
headless_custom/
├── README.md                  ← this file
├── headless_access/           ← shared policies; do not add module logic here
├── check_yaml.sh              ← parses every module YAML; `php -l` treats YAML as HTML
├── headless_auth/
├── headless_content/     ← recipes, training, resources (read-only + a favourite + doctors add recipes)
├── headless_intake/
├── headless_messages/
├── headless_patients/
├── headless_progress/
└── headless_session/
```

A new module declares its dependency on `headless_access` in its `.info.yml`:

```yaml
dependencies:
  - drupal:user
  - headless_custom:headless_access
```

### 5.1 Naming

`headless_<area>`; routes under `/api/headless/<area>`; logger channel the
module machine name; tests in `tests/src/Unit/`.

### 5.2 `headless_intake` is the exception, on purpose

Its two public routes use `_access: 'TRUE'` because they are token-addressed
and consumed by the intake app **before** a session exists. That is a genuine
public surface, not an oversight. Its admin routes use core permissions
(`administer site configuration`, `manage intake invite links`) because those
are staff tools, not portal endpoints. Do not treat these as precedent for
gating a portal endpoint with `_access: 'TRUE'`.

### 5.3 Explain the non-obvious in comments, at the point of use

The routing files and access classes carry long comments explaining *why* an
arrangement is what it is — the shadowed route, the `_auth` placement, the
Symfony `Route` type. That is deliberate. This codebase has no other record of
these decisions, and the comments are what stop the next reader from
"tidying" a bug back in.

### 5.4 Read-only means no write route, not a disabled one

`headless_content` has no update or delete route and no permission for one.
Content appears through two writes: a chiropractor adding a recipe
(`headless_content.create`, gated by `WriteChiropractorAccess`), and the
per-caller favourite (§5.5). Everything else is authored in the Drupal admin UI,
which is a different surface from `/api/headless`. `ContentCapabilities` reports
`canCreate` as TRUE only for `chiropractor_active_` on the `recipe` bundle — the
exact caller and bundle the create route serves — and `canDelete` and
`canManageOwnContent` as FALSE for **every** role, including `administrator`.

Keying those flags in the backend rather than hard-coding them in the frontend
matters: a client-side condition offers a button whose endpoint returns 404, and
that reads to a user as a broken page rather than as a missing feature.

The create flag changed direction twice, and the lesson from the first change is
what rules the second. An earlier draft reported `canCreate: true` for
`administrator`, on the reasoning that it described the Drupal-side permission
rather than a route. That was wrong in a way that mattered: it rendered an "Add
recipe" button for a user who cannot reach the endpoint behind it. **A capability
block that describes a permission the API does not expose is a lie the frontend
will act on.** When the create route was then added deliberately,
`ContentCapabilities` gained a real answer — keyed to the route that backs it.
If a capability cannot be backed by a route in this module, it is FALSE.

The create route and the capability stay together: the route regex is pinned to
`recipe`, `ContentService::CREATE_BUNDLES` is the service-side half of that, and
`ContentCapabilities::CREATE_BUNDLES`/`CREATE_ROLES` mirror both. Change them
together.

### 5.5 The writes are per-caller state and a chiropractor's own recipe

`headless_content.create` is
`POST /api/headless/content/recipe/create` — active chiropractor only. It sets
`field_published_to` to the caller's own clinic (`user.field_clinic`) and ignores
any client-supplied audience field, the same rule the read side applies. The new
recipe is published immediately and authored by the caller. There is no edit or
unpublish route, so authors cannot change their recipe after submitting — a
decision to revisit with the edit work.

`headless_content.favourite` is `POST /api/headless/content/{bundle}/{node}/favourite`.
It creates a `flagging` belonging to the caller rather than changing a node, which
is why it sits in an otherwise read-only module.

Two properties worth knowing before touching it:

- **The route gate is not the permission check.** `PortalMemberAccess` admits all
  four portal roles, because a route cannot know which bundles the `favorites`
  flag covers. `ContentFavorites::canFlag()` asks the flag itself via
  `actionAccess()` — the flag module's own check — and refuses with 403 without
  `flag favorites`. `ContentCapabilities::canFavourite` is the frontend's hint and
  is bundle-dependent; the service is authoritative.
- **The body is optional and an explicit state is idempotent.** No body flips the
  current state, which is what a star button wants. `{"favourite": true|false}` is
  a "make it so", so a client retrying after a dropped response does not invert
  the star. The flag module throws a `LogicException` on a redundant flag, which is
  why `ContentFavorites::set()` short-circuits rather than letting it through.

### 5.6 Administrators are not an audience

`PortalRoles::ADMINISTRATOR` exists so a module can *name* the role — to report
`audience: 'administrator'` in a payload, or to assert in a test that such an
account is refused — and is deliberately in none of the lists an access policy
reads. `PORTAL_MEMBERS` is the four portal roles and no administrator.

An admin session is refused by every `headless_content` route. That is the point:
the tenant boundary in `ContentScope` is a guarantee about who can reach the
library, and an admin token carrying the same API as a patient would reduce it to a
policy statement.

The constant was added on 2026-10-01 after `ContentCapabilities::audience()`
referenced it without it existing. **Referencing an undeclared class constant is an
`Error` at runtime, not a notice**, so the capability block — which every list
response carries — would have thrown on every request rather than failing at build
time. PHP will not tell you about this statically, and there is no test that catches
it except one that actually calls the method.

### 5.7 Verify field assumptions against config, then the database

Configuration is exported to `config/sync`, so field and View definitions are
readable in the repo: `field.storage.node.field_published_to.yml` says what the
audience field targets, and the `views.view.{recipes,training,resources}.yml`
displays carry the filters a replacement has to reproduce. Start there.

For what config cannot answer — which values are actually populated, how many rows
point at a clinic that no longer exists — there is one diagnostic left,
`headless_content/check_clinic.php`:

```
docker exec chirothintrackerreact_appserver_1 drush php:script \
  /app/web/modules/custom/headless_custom/headless_content/check_clinic.php
```

Earlier drafts of that script assumed the audience field was `field_publish_to`
and that "shared" meant an empty field. Both were wrong and both cost a round of
investigation; see §4.12. If you extend the script, the field name is
`field_published_to`.

---

## 6. Tests

- Unit tests in `tests/src/Unit/`, Prophecy, one class per unit.
- Cover the case that broke, with the shape that broke it, so the test fails
  against a regression.
- `shouldNotBeCalled()` is worth more than a comment. `ProgressServiceTermLabelsTest`
  asserts `load()` is never called, which is a real assertion about the
  contract; a comment saying "use loadMultiple here" is not.

### 6.1 Match the arity of what the code actually calls

A Prophecy stub pinned to fewer arguments than the real call **does not fail the
test** — it returns NULL, and the failure surfaces as a fatal error on
`NULL->method()` several lines later, pointing nowhere near the stub:

```php
// ContentFavorites calls $flag->actionAccess($action, $account, $node).
$flag->actionAccess('flag', $account);      // never matches: arity differs
$flag->actionAccess('flag', Argument::cetera());   // matches
```

Same trap as §1.5 in a different place: the signature you stubbed is not the
signature that runs.

### 6.2 `phpunit` needs PHP 8.2 here, and the host has 8.1

`vendor/bin/phpunit` refuses to run on the WSL host PHP:

```
Composer detected issues in your platform:
Your Composer dependencies require a PHP version ">= 8.2.0".
You are running 8.1.2-1ubuntu2.26.
```

The container has 8.2, the host does not, so unit tests are a `lando` step and not
a local one. `lint.sh` and `check_yaml.sh` are the local gates — `php -l` validates
syntax on 8.1, and the syntax in use here is 8.1-compatible (no `readonly class`,
which is 8.2).

### 6.3 Reading `config/sync` beats inferring from a label

The favourite matrix in `ContentCapabilities` is not a design decision, it is a
reading of `config/sync/user.role.*.yml` and `flag.flag.favorites.yml`. When a
question about who can do what comes up, the role and flag configs answer it:

```bash
grep -A2 'favorites' config/sync/user.role.*.yml
```

Assume nothing. `chiropractor_active_` holds eleven flags and not this one.

---

## 7. Before you finish

```
# every PHP file in headless_content, found not listed
bash web/modules/custom/headless_custom/headless_content/lint.sh

# every module YAML in headless_custom, plus php -l across the tree
bash web/modules/custom/headless_custom/check_yaml.sh

# frontend, from frontend/ — type-check if you touched TS, lint either way
npm run type-check
npm run lint
```

`lint.sh` discovers its files with `find` precisely so a new class cannot be added
without being checked. `check_yaml.sh` exists because `php -l` treats YAML as inline
HTML and passes happily on a routing file with a typo in it; a routing error is then
only found by a cache rebuild, which is the user's step and not ours.

Both frontend scripts exist in `frontend/package.json`. `lint` was the only script
there until `type-check` was added on 2026-10-01; if you are following an older copy
of this file and the command 404s, it was never there, not something you broke.

Unit tests need PHP 8.2, which the container has and the WSL host does not — see
§6.2. Drush, Docker and the application test suite are the user's steps.

Report what you verified and what you did not. Specifically: `php -l` proves
syntax, not behaviour. An undeclared class constant, a Prophecy stub with the wrong
arity, and a wrong route placeholder name all pass `php -l` cleanly.
