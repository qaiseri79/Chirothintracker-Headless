import "server-only";

import { drupalFetch } from "@/lib/drupal/client";
import {
  toCapabilities,
  toResourceOptions,
  toResources,
  type ResourceCapabilities,
  type ResourceNode,
  type ResourceOptions,
} from "@/lib/resources/types";

/**
 * Thrown when the resource library cannot be read.
 *
 * A distinct type so the page can tell a broken backend from an empty library, as
 * `RecipesUnavailableError` does for the recipe page. Without it the page cannot
 * say "this clinic has no resources" and "Drupal is unreachable" apart, and would
 * show the same empty state for both.
 */
export class ResourcesUnavailableError extends Error {
  constructor(
    message: string,
    /** The upstream HTTP status, or 0 when the request never completed. */
    readonly status: number,
    options?: { cause?: unknown },
  ) {
    super(message, options);
    this.name = "ResourcesUnavailableError";
  }
}

/** Thrown when the caller's session no longer authorises the library. */
export class ResourcesUnauthenticatedError extends ResourcesUnavailableError {
  constructor(message = "Not signed in") {
    super(message, 401);
    this.name = "ResourcesUnauthenticatedError";
  }
}

/**
 * The endpoint clamps `page_size` to this; asking for more returns this.
 *
 * The parameter name is `page_size` and it is load-bearing:
 * `headless_content`'s `ContentController::list()` reads
 * `$request->query->get('page_size')` by that exact name, so `?pageSize=200` is
 * dropped without error and the endpoint serves its own default of 50.
 *
 * `page` and `page_size` are route *defaults*, and Symfony resolves controller
 * arguments from request attributes, which a query string does not populate — so
 * the controller has to read the query itself. That was the bug behind the recipe
 * pager, fixed in the same place; the note is kept here because the trap is
 * invisible: the response is well-formed, `pageCount` is truthful and the status
 * stays 200 while every page is page one.
 */
const MAX_PAGE_SIZE = 200;

/**
 * Hard ceiling on how many pages one request will walk.
 *
 * A runaway guard, not a limit anyone should reach: 20 pages at 200 rows is 4,000
 * resources, and the library is 138. It stops a wrong or hostile `pageCount` from
 * spinning this loop forever. If it ever does bind, `fetchResources()` logs the
 * shortfall rather than returning a short list silently.
 */
const MAX_PAGES = 20;

/**
 * Where the Resources page gets its rows.
 *
 * **This is the seam.** The grouping, search, filter bar and viewer are written
 * against `ResourceNode` and know nothing about the endpoint.
 *
 * ## The endpoint
 *
 * Served by `headless_custom/headless_content`, route `headless_content.list`, on
 * the `chirothin_resource` bundle, behind the same `PortalMemberAccess` policy as
 * recipes. As with recipes, the caller's clinic comes from `user.field_clinic` and
 * `field_published_to` is the tenant boundary on the Drupal side, so there is
 * deliberately no clinic, type or search parameter to forward — a client-supplied
 * clinic would replace that boundary. Only `page` and `page_size` are passed.
 *
 * ## Why the whole library is fetched
 *
 * The page groups every resource under a type heading and searches and filters
 * client-side, so it needs all the rows before it can render anything. A single
 * request at the endpoint's own default of 50 would show 50 of 138 with no way to
 * reach the other 88, and the design has no pager to put them behind. The cost is
 * far smaller than the recipe library's — 138 rows of mostly a title and a
 * filename, not a 1 MB payload.
 *
 * ## A note on what a row may be
 *
 * 12 of the 138 published resources have no file attached: their
 * `field_resource` is an empty reference, and the bundle has no link field to fall
 * back to. They are returned by the API like any other row and mapped to a
 * `ResourceNode` with `hasFile: false`. The page renders them as a title with no
 * download or preview, because dropping them would make a real gap in the library
 * look like a smaller library.
 *
 * ## The 307
 *
 * Drupal's cookie authentication answers an unauthenticated request with a 307 to
 * `/user/login?destination=...` — an HTML page whatever `Accept` says. `drupalFetch`
 * sets `redirect: "manual"`, so that arrives as a 307 rather than being followed,
 * and parsing it as JSON would throw. It is mapped to
 * {@link ResourcesUnauthenticatedError} so the page redirects to login instead of
 * reporting a broken library.
 */
export async function fetchResources(): Promise<{
  nodes: ResourceNode[];
  capabilities: ResourceCapabilities;
}> {
  // Keyed by id so a row landing on two pages cannot become two React elements.
  // The ordering guarantee that prevents duplicates belongs to the endpoint — see
  // the `nid` tiebreaker in `ContentService::listBundle()` — so this is a guard, not
  // the fix. First write wins: on a genuine conflict the earlier page's row is the
  // one the user was most likely to have already seen.
  const byId = new Map<number, unknown>();

  // Pages are 1-indexed, matching the endpoint, and this is a do/while so the first
  // request is page 1. The endpoint 400s a `page=0`; it used to clamp one to page 1,
  // which turned a 0-indexed loop into one that fetched page 1 twice and concatenated
  // both. Same invariant as `lib/recipes/data.ts`, and for the same reason.
  let page = 1;
  let pageCount = 1;
  let totalItems = 0;

  // The capabilities block is the same on every page; the first readable one is
  // taken and the rest ignored. It is what the "Add resource" button is gated on,
  // and it reaches the page with the rows rather than as a second request.
  let capabilities: ResourceCapabilities | null = null;

  // Bounded by pageCount from the response and by MAX_PAGES below, so a wrong or
  // hostile pageCount cannot spin this forever.
  do {
    const path = `/api/headless/content/chirothin_resource?page=${page}&page_size=${MAX_PAGE_SIZE}`;

    let response: Response;
    try {
      response = await drupalFetch(path);
    } catch (cause) {
      throw new ResourcesUnavailableError(
        "The resource library could not be reached.",
        0,
        { cause },
      );
    }

    if (response.status === 307 || response.status === 302) {
      throw new ResourcesUnauthenticatedError();
    }

    if (!response.ok) {
      throw new ResourcesUnavailableError(
        `The resource library responded ${response.status}.`,
        response.status,
      );
    }

    const body = (await response.json().catch(() => null)) as {
      items?: unknown;
      pagination?: { pageCount?: number; totalItems?: number };
      capabilities?: unknown;
    } | null;

    if (body === null) {
      throw new ResourcesUnavailableError(
        "The resource library returned a response that was not JSON.",
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

  // A library that grew past the cap would render short and say nothing. Logged so
  // it is visible in the server log rather than only as a user noticing a missing
  // document.
  const fetched = byId.size;
  if (totalItems > fetched) {
    console.warn(
      `[resources] Fetched ${fetched} of ${totalItems} resources: the library needs more than the ${MAX_PAGES}-page cap at ${MAX_PAGE_SIZE} rows a page. ` +
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
    nodes: toResources([...byId.values()]),
    capabilities: settled,
  };
}

/**
 * One resource, or null when the caller may not see it.
 *
 * Unused by the page — the list already carries every field the viewer renders, so
 * there is no detail view to feed. Kept because `headless_content.get` exists and a
 * resource detail page is the obvious next thing to build on it.
 *
 * A 404 here means the node is missing, unpublished, another bundle, or another
 * clinic's — all four are the same answer on purpose, so a caller cannot use this
 * to discover which.
 */
export async function fetchResource(id: number): Promise<ResourceNode | null> {
  try {
    const response = await drupalFetch(`/api/headless/content/chirothin_resource/${id}`);
    if (response.status === 404) return null;
    if (response.status === 307 || response.status === 302) {
      throw new ResourcesUnauthenticatedError();
    }
    if (!response.ok) {
      throw new ResourcesUnavailableError(
        `Resource ${id} responded ${response.status}.`,
        response.status,
      );
    }
    return toResources([await response.json()])[0] ?? null;
  } catch (cause) {
    if (cause instanceof ResourcesUnavailableError) throw cause;
    throw new ResourcesUnavailableError(
      `Resource ${id} could not be reached.`,
      0,
      { cause },
    );
  }
}

/**
 * The options the "Add resource" form renders, from the `resource_category`
 * vocabulary.
 *
 * Called server-side (like fetchResources) and only on the chiropractor's own
 * page render: the button it feeds is gated on `canCreate`, and the options are
 * of no use to a caller without it. Endpoint:
 * `GET /api/headless/content/chirothin_resource/options`
 * (`headless_content.options`), a portal-member read.
 *
 * Failures are read the same way as the library — 307 maps to a session loss,
 * anything else to an unavailable error — so the page treats "no options" as
 * either a login problem or a broken backend, never as an empty form.
 */
export async function fetchResourceOptions(): Promise<ResourceOptions> {
  let response: Response;
  try {
    response = await drupalFetch("/api/headless/content/chirothin_resource/options");
  } catch (cause) {
    throw new ResourcesUnavailableError(
      "The resource categories could not be reached.",
      0,
      { cause },
    );
  }

  if (response.status === 307 || response.status === 302) {
    throw new ResourcesUnauthenticatedError();
  }

  if (!response.ok) {
    throw new ResourcesUnavailableError(
      `The resource options responded ${response.status}.`,
      response.status,
    );
  }

  const body = await response.json().catch(() => null);
  const options = toResourceOptions(body);
  if (options === null) {
    throw new ResourcesUnavailableError(
      "The resource options returned a response that was not usable.",
      response.status,
    );
  }

  return options;
}
