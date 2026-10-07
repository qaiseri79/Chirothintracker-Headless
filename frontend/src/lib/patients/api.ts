/**
 * The browser-side call the Add-patient form makes.
 *
 * Only the create. The roster itself is fetched on the server and handed in as a prop
 * (`app/(portal)/chiropractor/(patients)/patients/page.tsx`), so the first render
 * needs no round trip — which is why this file exists at all for exactly one call.
 *
 * It goes to the Next.js route handler under `/api/patients`, never to Drupal
 * directly: the browser holds no Drupal session cookie, and the handler is what
 * forwards it. See `lib/recipes/api.ts` for the same shape on the recipes side.
 */

import type { IntakeStatus, IntakeSubmission, PhaseCode } from "@/lib/patients/types";

/** Thrown for a transport or server failure, carrying a message fit to show. */
export class PatientRequestError extends Error {
  /**
   * Messages keyed by payload field, from a 422.
   *
   * Kept on the error rather than flattened into the message because the form shows
   * these against the offending inputs. Empty for every other failure.
   */
  readonly fields: Record<string, string>;

  /** The HTTP status, so a caller can tell a quota block from a validation error. */
  readonly status: number;

  constructor(message: string, fields: Record<string, string> = {}, status = 0) {
    super(message);
    this.name = "PatientRequestError";
    this.fields = fields;
    this.status = status;
  }
}

/** Raised when the session is gone, so the caller can send the user to log in. */
export class PatientAuthError extends PatientRequestError {}

/** What the Add form collects, in the endpoint's vocabulary. */
export interface CreatePatientInput {
  name: string;
  email: string;
  phone?: string;
  /** YYYY-MM-DD. Blank lets the endpoint pick the next Saturday. */
  programStart?: string;
  goalWeight?: number;
  /** The form asks whether to send them; the field records the opposite. */
  emailNotifications?: boolean;
  phase?: PhaseCode;
  /**
   * A clinic location id, from the snapshot's `clinicLocations`.
   *
   * A number because that is what an entity id is everywhere else in this file, and
   * because a string here would be ambiguous: an empty string is not a location, and
   * omitting the key is how "no choice made" is expressed. The endpoint accepts either
   * form, so this is a type question rather than a wire one.
   */
  clinicLocation?: number;
  /**
   * The contact_message this enrolment came from, when it came from one.
   *
   * Only set on the prefilled path. The endpoint flags the submission as
   * processed and links it to the account, which is what stops it still sitting
   * in the intake table as unreviewed after the patient exists.
   */
  intakeSubmission?: number;
}

/** The row the endpoint wrote, and the soft quota warning if there was one. */
export interface CreatedPatient {
  patient: unknown;
  warning: string | null;
}

/**
 * POSTs to a patients write endpoint and unwraps the shared response shape.
 *
 * Both writes answer the same way — `{patient, warning}` on success, `{error,
 * errors?}` on a failure — so the unwrapping lives here rather than twice. The
 * status is kept on the error because the two callers read it for different
 * reasons: the Add form wants 409 to mean "at the limit", and the archived tab
 * wants it for the same reason.
 *
 * @param fallbackMessage
 *   Used when the response carries no `error` of its own, so the wording matches
 *   the action the clinician took.
 *
 * @throws PatientAuthError when the session has expired.
 * @throws PatientRequestError with `fields` populated on a 422.
 */
async function postPatientWrite(
  url: string,
  payload: unknown,
  fallbackMessage: string,
): Promise<CreatedPatient> {
  const response = await fetch(url, {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    // Undefined-valued keys are dropped by JSON.stringify, so an untouched optional
    // field is absent rather than sent as null. The endpoint treats absent and null
    // alike, but sending fewer keys is easier to reason about when reading a log.
    body: JSON.stringify(payload),
  });

  const body = (await response.json().catch(() => ({}))) as {
    patient?: unknown;
    warning?: unknown;
    error?: unknown;
    errors?: unknown;
  };

  if (response.status === 401) {
    throw new PatientAuthError("Your session has expired. Please sign in again.", {}, 401);
  }

  if (!response.ok) {
    const fields =
      body.errors && typeof body.errors === "object"
        ? (body.errors as Record<string, string>)
        : {};
    throw new PatientRequestError(
      typeof body.error === "string" && body.error ? body.error : fallbackMessage,
      fields,
      response.status,
    );
  }

  return {
    patient: body.patient,
    warning: typeof body.warning === "string" && body.warning ? body.warning : null,
  };
}

/**
 * Creates a patient and returns what the endpoint settled on.
 *
 * Deliberately not optimistic. A row added to the list before the server agreed
 * would be a patient who does not exist, and the clinician would go looking for an
 * account behind it — the exact failure the form's stub was written to avoid. The
 * wait is short and it is the only honest option for a create.
 */
export function createPatient(input: CreatePatientInput): Promise<CreatedPatient> {
  return postPatientWrite("/api/patients", input, "Unable to add this patient.");
}

/**
 * The changes a re-enrollment may carry.
 *
 * Deliberately a partial: every key is optional and a key that is absent is not
 * sent, which the endpoint reads as "leave this field alone". That is what lets the
 * form submit only what the chiropractor actually edited — so a field the browser
 * does not render, or a value the client could not read off the row, cannot come
 * back as a blank and wipe a real record.
 *
 * `emailNotifications` is the positive form ("send them"), matching the toggle's
 * label; the endpoint stores the inverted flag.
 */
export interface EnrollPatientInput {
  name?: string;
  email?: string;
  phone?: string;
  /** `YYYY-MM-DD`. */
  programStart?: string;
  startWeight?: number;
  goalWeight?: number;
  emailNotifications?: boolean;
  phase?: PhaseCode;
  clinicLocation?: number | null;
}

/**
 * Re-enrols an archived patient, putting them back into the program.
 *
 * A separate call from {@link createPatient} because it is a different operation,
 * not a variant of the same one: the account already exists, so nothing is being
 * collected for the first time. The caller passes the values the chiropractor
 * confirmed on a form prefilled from the patient's own record, so an untouched
 * confirmation sends back exactly what was already stored.
 *
 * It carries the same enrollment-limit check, so this can come back 409 when the
 * clinic is at its cap.
 *
 * @param userId The archived account's user id.
 * @param changes
 *   Only the fields to write. Empty — or `{}` — re-enrolls without touching
 *   anything but the role, which remains the right thing for a client that has not
 *   been updated to ask.
 */
export function enrollPatient(
  userId: number,
  changes: EnrollPatientInput = {},
): Promise<CreatedPatient> {
  // Blank strings and nulls are dropped rather than sent. An empty text field is a
  // value the chiropractor cleared, and the endpoint treats a blank as "no change"
  // too — sending it would be a no-op that only made the request harder to read in
  // a log.
  const payload: Record<string, unknown> = {};
  for (const [key, value] of Object.entries(changes)) {
    if (value === undefined || value === null || value === "") continue;
    payload[key] = value;
  }

  return postPatientWrite(
    `/api/patients/${userId}/enroll`,
    payload,
    "Unable to re-enroll this patient.",
  );
}

/**
 * Reads one intake submission in full.
 *
 * The second intake call, and the one that was missing: the Drupal endpoint has
 * existed all along, but nothing in the browser could reach it, so the intake
 * table's View button had nothing to call. This is that call.
 *
 * Normalisation is deliberately thin. The stored fields are Drupal's shape and are
 * passed through as they arrive; `intake-fields.ts` turns them into labelled
 * answers, because the labels belong to the intake blueprint rather than to this
 * transport.
 *
 * @throws PatientAuthError when the session has expired.
 * @throws PatientRequestError when the submission is not in the caller's clinic,
 *   or does not exist — which are one answer on purpose, so a guessed id cannot
 *   be used to find out which submissions another clinic has.
 */
export async function fetchIntakeSubmission(id: number): Promise<IntakeSubmission> {
  const response = await fetch(`/api/patients/intake/${id}`, { method: "GET" });
  const body = (await response.json().catch(() => ({}))) as {
    intake?: unknown;
    error?: unknown;
    message?: unknown;
  };

  if (response.status === 401) {
    throw new PatientAuthError("Your session has expired. Please sign in again.", {}, 401);
  }

  if (!response.ok) {
    throw new PatientRequestError(
      typeof body.message === "string" && body.message
        ? body.message
        : "Unable to read this intake submission.",
      {},
      response.status,
    );
  }

  const intake = body.intake as Partial<IntakeSubmission> | undefined;
  if (!intake || typeof intake !== "object" || typeof intake.id !== "number") {
    throw new PatientRequestError(
      "The intake service returned something that is not a submission.",
      {},
      502,
    );
  }

  return {
    id: intake.id,
    submitted: typeof intake.submitted === "number" ? intake.submitted : 0,
    status: intake.status === "checked" ? "checked" : "new",
    fields:
      intake.fields && typeof intake.fields === "object"
        ? (intake.fields as Record<string, unknown>)
        : {},
  };
}

/**
 * Permanently deletes one intake submission.
 *
 * ## Not optimistic
 *
 * The intake table behind the confirm dialog comes from the page's server
 * component, so removing the row here would need hand-written undo for the case
 * where the request fails. Instead the row stays, the dialog shows progress, and
 * the list refreshes only after the server has confirmed — the same ordering
 * `DeleteEntryDialog` uses for a progress log.
 *
 * ## No confirmation flag
 *
 * There is no `?confirm=` to set and nothing for the caller to opt into. Drupal
 * deletes on receipt, and a flag would be one more thing for a client to get wrong
 * in the direction of not sending it, which fails safe, or of sending it from
 * somewhere that did not ask a human, which does not. The confirmation belongs
 * entirely in the dialog.
 *
 * @throws PatientAuthError when the session has expired.
 * @throws PatientRequestError when the submission is gone or is not this
 *   clinic's — one answer for both.
 */
export async function deleteIntakeSubmission(id: number): Promise<void> {
  const response = await fetch(`/api/patients/intake/${id}`, { method: "DELETE" });
  const body = (await response.json().catch(() => ({}))) as {
    error?: unknown;
    message?: unknown;
  };

  if (response.status === 401) {
    throw new PatientAuthError("Your session has expired. Please sign in again.", {}, 401);
  }

  if (!response.ok) {
    throw new PatientRequestError(
      typeof body.message === "string" && body.message
        ? body.message
        : "Unable to delete this intake submission.",
      {},
      response.status,
    );
  }
}
/** Persists single-row and bulk review actions using Drupal's existing intake flag. */
export async function reviewIntakeSubmissions(
  ids: number[],
  status: IntakeStatus,
): Promise<{ updated: number[]; unchanged: number[]; rejected: number[] }> {
  const response = await fetch("/api/patients/intake/review", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ ids, status }),
  });
  const body = await response.json().catch(() => null);
  if (response.status === 401) {
    throw new PatientAuthError("Your session has expired. Please sign in again.", {}, 401);
  }
  if (!response.ok) {
    throw new PatientRequestError(
      typeof body?.error === "string" ? body.error : "Unable to save intake review state.",
      body?.errors ?? {},
      response.status,
    );
  }
  if (!body || !["updated", "unchanged", "rejected"].every((key) =>
    Array.isArray(body[key]) && body[key].every((id: unknown) => Number.isSafeInteger(id) && Number(id) > 0),
  )) {
    throw new PatientRequestError("The intake service returned an invalid response.");
  }
  return body;
}

/**
 * POSTs one of the two intake-link writes and unwraps the URL it returns.
 *
 * Both answer `{url}` on success and `{error}` on failure, so the unwrapping lives
 * here once. The 403 case is worth calling out: the backend admits an active
 * chiropractor or an administrator, and a brand-new doctor is created
 * `chiropractor_inactive_` until a subscription grants portal write — so "no button"
 * and "button that is refused" are two different states and the message has to say
 * which one happened. It does, because the route handler words it specifically.
 *
 * @throws PatientAuthError when the session has expired.
 * @throws PatientRequestError carrying the server's message otherwise.
 */
async function postIntakeLink(url: string, fallbackMessage: string): Promise<string> {
  const response = await fetch(url, { method: "POST" });
  const body = (await response.json().catch(() => null)) as {
    url?: unknown;
    error?: unknown;
  } | null;

  if (response.status === 401) {
    throw new PatientAuthError("Your session has expired. Please sign in again.", {}, 401);
  }

  if (!response.ok) {
    throw new PatientRequestError(
      typeof body?.error === "string" && body.error ? body.error : fallbackMessage,
      {},
      response.status,
    );
  }

  if (!body || typeof body.url !== "string" || !body.url) {
    // A success with no URL is not "no link yet" — the caller asked for one and the
    // endpoint claims to have made it. Reported rather than returned as "".
    throw new PatientRequestError(fallbackMessage, {}, 502);
  }

  return body.url;
}

/**
 * Generates the clinic's intake link, if it does not have one.
 *
 * Idempotent, so a double-click or a retried request is harmless: the endpoint
 * returns the existing URL rather than issuing a second token. Not optimistic — the
 * link is the clinic's only route to a valid submission, so a URL shown before the
 * server agreed would be a link that does not work.
 */
export function generateIntakeLink(): Promise<string> {
  return postIntakeLink("/api/intake-link", "Unable to generate your intake link.");
}

/**
 * Replaces the clinic's intake link with a new one.
 *
 * Destructive: the old URL stops working immediately, and patients may have it
 * printed. Never merged into {@link generateIntakeLink} for that reason — the caller
 * confirms first.
 */
export function regenerateIntakeLink(): Promise<string> {
  return postIntakeLink(
    "/api/intake-link/regenerate",
    "Unable to regenerate your intake link.",
  );
}
