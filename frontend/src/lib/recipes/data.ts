import "server-only";

import { drupalFetch } from "@/lib/drupal/client";
import {
  toCapabilities,
  toRecipeOptions,
  toRecipes,
  type Recipe,
  type RecipeCapabilities,
  type RecipeOptions,
} from "@/lib/recipes/types";

/**
 * Thrown when the recipe library cannot be read.
 *
 * A distinct type so the page can tell a broken backend from an empty library, the
 * way `PatientsUnavailableError` does for the roster. Without it the page cannot
 * tell "this clinic has no recipes" from "Drupal is unreachable", and would render
 * the empty state for both.
 */
export class RecipesUnavailableError extends Error {
  constructor(
    message: string,
    /** The upstream HTTP status, or 0 when the request never completed. */
    readonly status: number,
    options?: { cause?: unknown },
  ) {
    super(message, options);
    this.name = "RecipesUnavailableError";
  }
}

/** Thrown when the caller's session no longer authorises the library. */
export class RecipesUnauthenticatedError extends RecipesUnavailableError {
  constructor(message = "Not signed in") {
    super(message, 401);
    this.name = "RecipesUnauthenticatedError";
  }
}

/**
 * The endpoint clamps `page_size` to this; asking for more returns this.
 *
 * The parameter name is `page_size` and it is load-bearing. `headless_content`'s
 * `ContentController::list()` reads `$request->query->get('page_size')` by that exact
 * name, so `?pageSize=200` is not an alias — it is dropped, with no error, and the
 * endpoint serves its own default of 50.
 *
 * Worth knowing *why* the controller reads the query itself: it cannot be a typed
 * `$page_size` argument. `page` and `page_size` are declared as route *defaults*, and
 * Symfony resolves controller arguments from request attributes, which a query string
 * does not populate. Live router, before the fix:
 *
 *     GET /api/headless/content/recipe?page=3&page_size=200
 *     matched page = '1'   matched page_size = '50'
 *
 * So every request returned page one and this loop assembled 13 copies of the same 50
 * rows. Kept as a note because the argument-resolution trap is invisible: every
 * response was well-formed, `pageCount` was truthful, and the status was 200 throughout.
 */
const MAX_PAGE_SIZE = 200;

/**
 * Hard ceiling on how many pages one request will walk.
 *
 * A runaway guard, not a limit anyone should reach: 20 pages at 200 rows is 4,000
 * recipes, and the library is 634. It exists so a wrong or hostile `pageCount`
 * cannot spin this loop forever. If it ever *does* bind, `fetchRecipes()` logs the
 * shortfall rather than quietly returning a short list.
 */
const MAX_PAGES = 20;

/**
 * Where the Recipes page gets its rows.
 *
 * **This is the seam.** The grouping, filters, search and cards are written against
 * `Recipe` and know nothing about the endpoint.
 *
 * ## What the endpoint does
 *
 * Served by `headless_custom/headless_content`, route `headless_content.list`, behind
 * the shared `PortalMemberAccess` policy. The constraints are kept here because each
 * is a way the request could be got wrong:
 *
 * - **Scope by clinic, never by a client argument.** The caller's clinic comes from
 *   `user.field_clinic`, and `field_published_to` is the tenant boundary on the
 *   Drupal side. There is deliberately no clinic, category or search parameter to
 *   forward: a client-supplied clinic would replace that boundary. Only `page` and
 *   `page_size` are passed through.
 * - **The clinic is never named in the response.** The payload carries clinic *ids*
 *   for the node's own audience, and nothing else identifying another clinic.
 * - **Pages are 1-indexed, and the endpoint enforces it.** `page=0` is a 400, as is a
 *   value that is not a whole number — `abc`, `1.5`, `1e3`, `-1`. An over-large
 *   `page_size` is still clamped to the maximum, because over-asking is a reasonable
 *   thing for a UI to do while it works out its layout.
 *
 *   `page=0` used to be clamped server-side to page one. That clamp is worth knowing
 *   about, because it hid a real bug rather than preventing one: a client walking
 *   `0..pageCount` instead of `1..pageCount` fetched page one *twice* and concatenated
 *   both, because `page=0` silently became page one. Measured on this site at
 *   `page_size=30`, a clinic with 116 recipes reached the UI as 146 rows — with
 *   `totalItems` truthful at 116, `pageCount` truthful at 4, and status 200 on every
 *   response. So: do not reintroduce the clamp, and do not "helpfully" 0-index the
 *   loop below. The 400 is the only thing that makes this mistake visible.
 *
 * ## Why the whole library is fetched
 *
 * The page groups every recipe under a category heading and filters and searches
 * client-side, so it needs all the rows before it can render anything. A single-page
 * fetch would show 50 of 634 recipes with no way to reach the other 584, and the
 * design has no pager to put them behind.
 *
 * The cost is real but much smaller than it was. A clinic's whole library now arrives
 * in a single page of 200 rows: measured at 113 KB of JSON for 116 recipes. That was
 * 311 KB until the audience `clinicIds` array came out of the payload — after the
 * legacy bulk import that field held 456 clinic ids per recipe, so the array was very
 * nearly the whole response and the frontend read none of it. It is still a lot to
 * serialise into the server-component payload. The alternative is a server-side filter
 * and a real pager, which is a different design from the one that was built — worth
 * doing deliberately rather than arriving at by accident.
 *
 * Rows are also keyed by id while accumulating. That guards against a duplicate
 * becoming a React key collision, but the ordering guarantee that prevents duplicates
 * belongs to the endpoint: see the `nid` tiebreaker in
 * `ContentService::listBundle()`.
 *
 * ## The 307
 *
 * Drupal's cookie authentication answers an unauthenticated request with a 307 to
 * `/user/login?destination=...`, an HTML page whatever `Accept` says. `drupalFetch`
 * sets `redirect: "manual"`, so that arrives as a 307 rather than being followed,
 * and parsing it as JSON would throw. It is mapped to
 * {@link RecipesUnauthenticatedError} so the page redirects to login instead of
 * reporting a broken library.
 */
export async function fetchRecipes(): Promise<{
  recipes: Recipe[];
  capabilities: RecipeCapabilities;
}> {
  // Keyed by id so a row landing on two pages cannot become two React elements.
  //
  // This is a guard, NOT the fix, and it is worth being precise about why, because two
  // candidate causes look identical from here:
  //
  // 1. The `nid` tiebreaker in `ContentService::LIST_SORT`. Independently real: the
  //    table has 634 rows but only 221 distinct titles, and `LIMIT`/`OFFSET` over a
  //    non-total order was measured to overlap and skip. That duplicates rows now that
  //    paging genuinely works, so it stays fixed.
  // 2. The paging bug, which is what the duplicate-key crash actually was. Every
  //    request returned page one, so this loop concatenated 13 identical copies of 50
  //    rows. Nothing to do with ordering.
  //
  // (2) was mistaken for (1), and this dedupe is what made that expensive: it turned
  // "13 copies of page one" into a clean-looking 50-recipe page — precisely the wrong
  // answer to give. A guard that hides the fault it was added against is worse than no
  // guard. Kept because it is cheap and the ordering guarantee is genuinely the
  // endpoint's job, but the truncation warning at the end is what must surface a wrong
  // count, and it did not.
  //
  // First write wins: on a genuine conflict the earlier page's row is kept, which
  // is the one the user was most likely to already have seen.
  const byId = new Map<number, unknown>();

  // Pages are 1-indexed, matching the endpoint, and this is a do/while so the first
  // request is page 1. Both halves matter: the endpoint 400s a `page=0`, and it used
  // to clamp one to page 1 instead, which turned a 0-indexed loop into a loop that
  // fetched page 1 twice. See the paging note in the docblock above.
  let page = 1;
  let pageCount = 1;
  let totalItems = 0;

  // The capabilities block is the same on every page; the first readable one is
  // taken and the rest ignored. It is what the "Add recipe" button is gated on,
  // and it reaches the page with the rows rather than as a second request.
  let capabilities: RecipeCapabilities | null = null;

  // Bounded by pageCount from the response, and by MAX_PAGES below, so a wrong or
  // hostile pageCount cannot spin this forever.
  do {
    const path = `/api/headless/content/recipe?page=${page}&page_size=${MAX_PAGE_SIZE}`;

    let response: Response;
    try {
      response = await drupalFetch(path);
    } catch (cause) {
      throw new RecipesUnavailableError(
        "The recipe library could not be reached.",
        0,
        { cause },
      );
    }

    if (response.status === 307 || response.status === 302) {
      throw new RecipesUnauthenticatedError();
    }

    if (!response.ok) {
      throw new RecipesUnavailableError(
        `The recipe library responded ${response.status}.`,
        response.status,
      );
    }

    const body = (await response.json().catch(() => null)) as {
      items?: unknown;
      pagination?: { pageCount?: number; totalItems?: number };
      capabilities?: unknown;
    } | null;

    console.log("[recipes] API response:", body);

    if (body === null) {
      throw new RecipesUnavailableError(
        "The recipe library returned a response that was not JSON.",
        response.status,
      );
    }

    if (Array.isArray(body.items)) {
      for (const item of body.items) {
        const id = (item as { id?: unknown } | null)?.id;
        if (typeof id === "number" && !byId.has(id)) byId.set(id, item);
      }
    }

    if (capabilities === null) {
      capabilities = toCapabilities(body.capabilities);
    }

    const reported = body.pagination?.pageCount;
    pageCount = typeof reported === "number" && reported > 0 ? reported : 1;

    const reportedTotal = body.pagination?.totalItems;
    if (typeof reportedTotal === "number" && reportedTotal > 0) {
      totalItems = reportedTotal;
    }

    page++;
  } while (page <= pageCount && page <= MAX_PAGES);

  // A library that grew past the cap would render short and say nothing, which is
  // the same silent failure the unstable sort caused. Logged so it is visible in
  // the server log rather than only as a user noticing a recipe is missing.
  const fetched = byId.size;
  if (totalItems > fetched) {
    console.warn(
      `[recipes] Fetched ${fetched} of ${totalItems} recipes: the library needs more than the ${MAX_PAGES}-page cap at ${MAX_PAGE_SIZE} rows a page. ` +
        `The list below is incomplete.`,
    );
  }

  // A capability block the payload never carried must render no button, so the
  // "can't create" shape is the default rather than a throw. A workable shape
  // arrives with every real response; this fallback is only for a response so
  // malformed it had no capabilities block at all.
  const settled = capabilities ?? {
    canCreate: false,
    canDelete: false,
    canManageOwnContent: false,
    canFavourite: false,
    audience: "",
  };

  return {
    recipes: toRecipes([...byId.values()]),
    capabilities: settled,
  };
}

/**
 * One recipe, or null when the caller may not see it.
 *
 * Unused by the page — the list already carries every field the card renders, so
 * there is no detail view to feed. Kept because `headless_content.get` exists and a
 * recipe detail page is the obvious next thing to build on it.
 *
 * A 404 here means the node is missing, unpublished, another bundle, or another
 * clinic's — all four are the same answer on purpose, so a caller cannot use this
 * to discover which.
 */
export async function fetchRecipe(id: number): Promise<Recipe | null> {
  try {
    const body = await drupalFetch(`/api/headless/content/recipe/${id}`);
    if (body.status === 404) return null;
    if (body.status === 307 || body.status === 302) {
      throw new RecipesUnauthenticatedError();
    }
    if (!body.ok) {
      throw new RecipesUnavailableError(
        `Recipe ${id} responded ${body.status}.`,
        body.status,
      );
    }
    return toRecipes([await body.json()])[0] ?? null;
  } catch (cause) {
    if (cause instanceof RecipesUnavailableError) throw cause;
    throw new RecipesUnavailableError(
      `Recipe ${id} could not be reached.`,
      0,
      { cause },
    );
  }
}

/**
 * The options the "Add recipe" form renders, from the recipe vocabularies.
 *
 * Called server-side (like fetchRecipes) and only on the chiropractor's own
 * page render: the button it feeds is gated on `canCreate`, and the options are
 * of no use to a caller without it. Endpoint: `GET /api/headless/content/recipe/options`
 * (`headless_content.options`), a portal-member read.
 *
 * Failures are read the same way as the library — 307 maps to a session loss,
 * anything else to an unavailable error — so the page treats "no options" as
 * either a login problem or a broken backend, never as an empty form.
 */
export async function fetchRecipeOptions(): Promise<RecipeOptions> {
  let response: Response;
  try {
    response = await drupalFetch("/api/headless/content/recipe/options");
  } catch (cause) {
    throw new RecipesUnavailableError(
      "The recipe categories and types could not be reached.",
      0,
      { cause },
    );
  }

  if (response.status === 307 || response.status === 302) {
    throw new RecipesUnauthenticatedError();
  }

  if (!response.ok) {
    throw new RecipesUnavailableError(
      `The recipe options responded ${response.status}.`,
      response.status,
    );
  }

  const body = await response.json().catch(() => null);
  const options = toRecipeOptions(body);
  if (options === null) {
    throw new RecipesUnavailableError(
      "The recipe options returned a response that was not usable.",
      response.status,
    );
  }

  return options;
}
