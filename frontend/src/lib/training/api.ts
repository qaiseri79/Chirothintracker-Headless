/**
 * The browser-side calls the Training page makes to add a training item.
 *
 * Both go to Next.js route handlers that forward the visitor's cookie and check
 * the session's chiropractor role — never direct calls to Drupal, because the
 * browser holds no Drupal session cookie. The library itself is fetched on the
 * server and passed in as a prop, the same way the recipes page does it.
 *
 * A training create is two requests, not one. The video (when there is one) is
 * uploaded first, through `/api/content/training/upload`, which forwards a
 * multipart body to Drupal and answers with the new file's id. The create then
 * goes to `/api/content/training` naming that id as `video`. A training item may
 * have no video at all — several of the published ones are body text and an
 * embed — so the create is never required to name one.
 *
 * ## The 401
 *
 * The handlers translate Drupal's 307-to-login into a 401, so the branch here
 * is on a status that means "signed out" rather than on a redirect the browser
 * would follow. `fetch` follows redirects by default and would turn the login
 * page into a 200 with an HTML body.
 */

import { toTrainingList, type TrainingNode } from "@/lib/training/types";

/** Thrown for a transport or server failure, carrying a message fit to show. */
export class TrainingRequestError extends Error {}

/** Raised when the session is gone, so the caller can send the user to log in. */
export class TrainingAuthError extends TrainingRequestError {}

/**
 * The fields a doctor sends to add a training item.
 *
 * `body` is optional plain text — the training card renders the field as HTML,
 * and the end to store it under a restricted format is the backend's. `video`
 * is the browser-side `File` (or null when the item has no video); the
 * upload handler turns it into a multipart part and returns an id the create
 * names as `video`.
 */
export interface NewTrainingInput {
  title: string;
  body: string;
  video: File | null;
}

/**
 * Uploads a training video and returns what the create payload needs from it.
 *
 * The file is sent as multipart/form-data in a `files[attachment]` part, the
 * same contract as the messages attachment upload and the resource upload.
 * Drupal validates the extension against the training allowlist — MP4 only,
 * the bundle's `field_video` setting — and a 100 MB cap, stores the bytes under
 * public://, and answers with the new managed file's `fid`, which a subsequent
 * create names as `video`.
 */
export async function uploadTrainingVideo(file: File): Promise<{ fid: number }> {
  const form = new FormData();
  form.append("files[attachment]", file);

  const response = await fetch("/api/content/training/upload", {
    method: "POST",
    body: form,
  });

  const body = (await response.json().catch(() => ({}))) as {
    fid?: unknown;
    message?: unknown;
  };

  if (response.status === 401) {
    throw new TrainingAuthError("Your session has expired. Please sign in again.");
  }
  if (!response.ok) {
    throw new TrainingRequestError(
      typeof body.message === "string" && body.message
        ? body.message
        : "The video could not be uploaded.",
    );
  }

  if (typeof body.fid !== "number") {
    throw new TrainingRequestError("The video was uploaded but something was lost.");
  }

  return { fid: body.fid };
}

/**
 * Adds a training item in the caller's clinic and returns the row to list.
 *
 * Goes through the Next.js route handler at `/api/content/training`, which
 * checks the session's chiropractor role before forwarding, and then to
 * Drupal's `POST /api/headless/content/training/create`. The audience is the
 * doctor's own clinic, decided server-side; the payload sent here deliberately
 * carries no clinic field.
 *
 * A 401 is a lost session, reported as {@link TrainingAuthError} so the page
 * can send the user to log in. A 400 carries the endpoint's own message
 * ("title is required", "The \"video\" file does not exist") straight back into
 * the form.
 */
export async function createTraining(input: NewTrainingInput): Promise<TrainingNode> {
  let video: number | null = null;
  if (input.video) {
    const uploaded = await uploadTrainingVideo(input.video);
    video = uploaded.fid;
  }

  const response = await fetch("/api/content/training", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({
      title: input.title,
      body: input.body,
      video,
    }),
  });

  const body = (await response.json().catch(() => ({}))) as {
    message?: unknown;
  };

  if (response.status === 401) {
    throw new TrainingAuthError("Your session has expired. Please sign in again.");
  }
  if (!response.ok) {
    throw new TrainingRequestError(
      typeof body.message === "string" && body.message
        ? body.message
        : "Unable to add this training item.",
    );
  }

  // The handler returns the endpoint's serialised row, so the same mapper the
  // list uses turns it into a TrainingNode. A created item that does not map
  // is a server disagreement worth surfacing rather than a silent no-op.
  const trainingNode = toTrainingList([body])[0];
  if (!trainingNode) {
    throw new TrainingRequestError(
      "The training item was created but could not be shown.",
    );
  }

  return trainingNode;
}

/** Re-exported so the page does not import from two places for one type. */
export type { TrainingNode };
export { toTrainingList };