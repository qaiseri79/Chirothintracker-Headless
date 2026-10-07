import "server-only";

import { drupalFetch } from "@/lib/drupal/client";
import {
  toCapabilities,
  toTrainingList,
  type TrainingCapabilities,
  type TrainingListResponse,
  type TrainingNode,
} from "@/lib/training/types";

/**
 * Thrown when the training library cannot be read.
 *
 * A distinct type so the page can tell a broken backend from an empty library,
 * the way `RecipesUnavailableError` does for the recipe library. Without it the
 * page renders "No training materials available" for both, which reads as a
 * content fact when it is an outage — and the caller cannot act on either.
 */
export class TrainingUnavailableError extends Error {
  constructor(
    message: string,
    /** The upstream HTTP status, or 0 when the request never completed. */
    readonly status: number,
    options?: { cause?: unknown },
  ) {
    super(message, options);
    this.name = "TrainingUnavailableError";
  }
}

/** Thrown when the caller's session no longer authorises the library. */
export class TrainingUnauthenticatedError extends TrainingUnavailableError {
  constructor(message = "Not signed in") {
    super(message, 401);
    this.name = "TrainingUnauthenticatedError";
  }
}

/**
 * Rows per request, and the ceiling on pages walked.
 *
 * The endpoint clamps `page_size` to this — it is `ContentService::MAX_PAGE_SIZE`,
 * so asking for more returns this and asking for less is honoured. The parameter
 * name is `page_size` exactly: `ContentController::list()` reads it from the query
 * string by that name, and `?pageSize=200` is dropped silently and served at the
 * endpoint's default of 50 instead.
 *
 * These two bounds and the paging loop below are deliberately the same as
 * `lib/recipes/data.ts` and `lib/resources/data.ts`. Training carries no search or
 * filter bar, so the whole library is fetched in one pass the way the other two
 * pages do, and a clinic's training set is far inside one page — `MAX_PAGES` is a
 * runaway guard rather than a limit anyone should reach.
 */
const MAX_PAGE_SIZE = 200;
const MAX_PAGES = 20;

/**
 * The training materials this caller may read, in the endpoint's order, plus the
 * capability flags the page is gated on.
 *
 * Served by `headless_custom/headless_content`, route `headless_content.list`, on
 * the `training` bundle, behind the same `PortalMemberAccess` policy as recipes
 * and resources. The caller's clinic comes from `user.field_clinic` and
 * `field_published_to` is the tenant boundary on the Drupal side, so there is
 * deliberately no clinic parameter to forward. Only `page` and `page_size` are
 * passed.
 *
 * Pages are 1-indexed and the endpoint enforces it: `page=0` is a 400. This is a
 * `do...while` so the first request is page 1 — see the longer note in
 * `lib/recipes/data.ts` for why that clamp was ever a hazard.
 *
 * The `capabilities` block is the same on every page; the first readable one is
 * taken. It is what the "Add training" button is gated on, and it reaches the
 * page with the rows rather than as a second request.
 *
 * @throws TrainingUnavailableError
 *   When the library cannot be read at all — Drupal unreachable, a non-2xx, or a
 *   body that is not JSON.
 * @throws TrainingUnauthenticatedError
 *   When the session no longer authorises it, so the caller can send the visitor
 *   to `/login` rather than rendering a failure.
 */
export async function fetchTraining(): Promise<{
  nodes: TrainingNode[];
  capabilities: TrainingCapabilities;
}> {
  // First write wins on a genuine conflict: the earlier page's row is the one the
  // caller was most likely to already be looking at. The ordering guarantee that
  // should make this unnecessary belongs to the endpoint — see the `nid`
  // tiebreaker in `ContentService::LIST_SORT`.
  const byId = new Map<number, TrainingNode>();
  let page = 1;
  let pageCount = 1;
  let totalItems = 0;

  // The capabilities block is the same on every page; the first readable one is
  // taken and the rest ignored. It is what the "Add training" button is gated on.
  let capabilities: TrainingCapabilities | null = null;

  // Bounded by pageCount from the response and by MAX_PAGES below, so a wrong or
  // hostile pageCount cannot spin this forever.
  do {
    const path = `/api/headless/content/training?page=${page}&page_size=${MAX_PAGE_SIZE}`;

    let response: Response;
    try {
      response = await drupalFetch(path);
    } catch (cause) {
      throw new TrainingUnavailableError(
        "The training library could not be reached.",
        0,
        { cause },
      );
    }

    if (response.status === 307 || response.status === 302) {
      throw new TrainingUnauthenticatedError();
    }

    if (!response.ok) {
      throw new TrainingUnavailableError(
        `The training library responded ${response.status}.`,
        response.status,
      );
    }

    const body = (await response.json().catch(() => null)) as TrainingListResponse | null;

    if (body === null) {
      throw new TrainingUnavailableError(
        "The training library returned a response that was not JSON.",
        response.status,
      );
    }

    if (Array.isArray(body.items)) {
      for (const node of toTrainingList(body.items)) {
        if (!byId.has(node.id)) byId.set(node.id, node);
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

  // A library that outgrew the cap would render short and say nothing, which is the
  // same silent failure an unstable sort causes. Logged so it is visible in the
  // server log rather than only as a user noticing a page is missing.
  const fetched = byId.size;
  if (totalItems > fetched) {
    console.warn(
      `[training] Fetched ${fetched} of ${totalItems} training nodes: the library needs more than the ${MAX_PAGES}-page cap at ${MAX_PAGE_SIZE} rows a page. ` +
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
    nodes: [...byId.values()],
    capabilities: settled,
  };
}
