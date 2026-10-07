/**
 * The browser-side calls the Resources page makes to add a resource.
 *
 * Both go to Next.js route handlers that forward the visitor's cookie and check
 * the session's chiropractor role — never direct calls to Drupal, because the
 * browser holds no Drupal session cookie. The library itself is fetched on the
 * server and passed in as a prop, the same way the recipes page does it.
 *
 * A resource create is two requests, not one. The file (when there is one) is
 * uploaded first, through `/api/content/resource/upload`, which forwards a
 * multipart body to Drupal and answers with the new file's id. The create then
 * goes to `/api/content/resource` naming that id as `resource`. A resource may
 * have no file at all — 12 of the 138 published ones do not — so the create is
 * never required to name one.
 *
 * ## The 401
 *
 * The handlers translate Drupal's 307-to-login into a 401, so the branch here
 * is on a status that means "signed out" rather than on a redirect the browser
 * would follow. `fetch` follows redirects by default and would turn the login
 * page into a 200 with an HTML body.
 */

import { toResources, type ResourceNode } from "@/lib/resources/types";

/** Thrown for a transport or server failure, carrying a message fit to show. */
export class ResourceRequestError extends Error {}

/** Raised when the session is gone, so the caller can send the user to log in. */
export class ResourceAuthError extends ResourceRequestError {}

/**
 * The fields a doctor sends to add a resource.
 *
 * `resourceTypeIds` holds exactly one term id from the options payload — the
 * field is cardinality 1 and the form only ever offers one selection. `file`
 * is the browser-side `File` (or null when the resource has no file); the
 * upload handler turns it into a multipart part and returns an id the create
 * names as `resource`.
 */
export interface NewResourceInput {
  title: string;
  description: string;
  resourceTypeIds: number[];
  file: File | null;
}

/**
 * Uploads a resource file and returns what the create payload needs from it.
 *
 * The file is sent as multipart/form-data in a `files[attachment]` part, the
 * same contract as the messages attachment upload. Drupal validates the
 * extension against the resource allowlist (PDF, images, office documents,
 * video) and a 20 MB cap, stores the bytes under public://, and answers with the
 * new managed file's `fid`, which a subsequent create names as `resource`.
 */
export async function uploadResourceFile(file: File): Promise<{ fid: number }> {
  const form = new FormData();
  form.append("files[attachment]", file);

  const response = await fetch("/api/content/resource/upload", {
    method: "POST",
    body: form,
  });

  const body = (await response.json().catch(() => ({}))) as {
    fid?: unknown;
    message?: unknown;
  };

  if (response.status === 401) {
    throw new ResourceAuthError("Your session has expired. Please sign in again.");
  }
  if (!response.ok) {
    throw new ResourceRequestError(
      typeof body.message === "string" && body.message
        ? body.message
        : "The file could not be uploaded.",
    );
  }

  if (typeof body.fid !== "number") {
    throw new ResourceRequestError("The file was uploaded but something was lost.");
  }

  return { fid: body.fid };
}

/**
 * Adds a resource in the caller's clinic and returns the row to list.
 *
 * Goes through the Next.js route handler at `/api/content/resource`, which
 * checks the session's chiropractor role before forwarding, and then to
 * Drupal's `POST /api/headless/content/chirothin_resource/create`. The
 * audience is the doctor's own clinic, decided server-side; the payload sent
 * here deliberately carries no clinic field.
 *
 * A 401 is a lost session, reported as {@link ResourceAuthError} so the page
 * can send the user to log in. A 400 carries the endpoint's own message
 * ("title is required", "resourceTypes accepts at most 1 terms") straight back
 * into the form.
 */
export async function createResource(input: NewResourceInput): Promise<ResourceNode> {
  let resource: number | null = null;
  if (input.file) {
    const uploaded = await uploadResourceFile(input.file);
    resource = uploaded.fid;
  }

  const response = await fetch("/api/content/resource", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({
      title: input.title,
      description: input.description,
      resourceTypes: input.resourceTypeIds,
      resource,
    }),
  });

  const body = (await response.json().catch(() => ({}))) as {
    message?: unknown;
  };

  if (response.status === 401) {
    throw new ResourceAuthError("Your session has expired. Please sign in again.");
  }
  if (!response.ok) {
    throw new ResourceRequestError(
      typeof body.message === "string" && body.message
        ? body.message
        : "Unable to add this resource.",
    );
  }

  // The handler returns the endpoint's serialised row, so the same mapper the
  // list uses turns it into a ResourceNode. A created resource that does not map
  // is a server disagreement worth surfacing rather than a silent no-op.
  const resourceNode = toResources([body])[0];
  if (!resourceNode) {
    throw new ResourceRequestError("The resource was created but could not be shown.");
  }

  return resourceNode;
}

/** Re-exported so the page does not import from two places for one type. */
export type { ResourceNode };
export { toResources };