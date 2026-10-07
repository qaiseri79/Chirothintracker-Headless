import "server-only";

import { cache } from "react";
import { drupalFetch } from "@/lib/drupal/client";
import type {
  ClinicLocation,
  EnrollmentAllowance,
  IntakeStatus,
  PatientRole,
  PatientsSnapshot,
  PhaseCode,
} from "@/lib/patients/types";

/**
 * Thrown when the patients list cannot be read.
 *
 * A distinct type so the page can tell a broken backend from an empty clinic,
 * the way `ProgressUnavailableError` does for "My Progress".
 */
export class PatientsUnavailableError extends Error {
  constructor(
    message: string,
    /** The upstream HTTP status, or 0 when the request never completed. */
    readonly status: number,
    options?: { cause?: unknown },
  ) {
    super(message, options);
    this.name = "PatientsUnavailableError";
  }
}

/**
 * Where the Patients page gets its four lists.
 *
 * **This is the seam.** The tabs, tables, filters and pager are written against
 * `PatientsSnapshot` and know nothing about where the rows came from.
 *
 * ## What the endpoint does
 *
 * Served by `headless_patients`, behind the shared policy classes in
 * `headless_access` (`ReadChiropractorAccess` for the reads, `WriteChiropractorAccess`
 * for the writes). The constraints below were the specification it was written
 * against and are kept because each one is a way the query could be got wrong:
 *
 * - **Scope by clinic, never by a client argument.** The chiropractor's clinic
 *   comes from `user.field_clinic` via `ClinicScope`, and the request is ignored
 *   for it. Every one of the headless endpoints is current-user-scoped; this one is
 *   the first to be list-shaped, so the clinic condition is the thing most likely
 *   to be left out.
 * - **Archived is a role, not a field** — `archived_patient`, per `types.ts`.
 *   Both patient roles are sent as the single code `patient`.
 * - **The roster is every unblocked account in the clinic**, not only patients.
 *   Chiropractors and coaches are listed too, and `row.role` distinguishes them,
 *   because the Roster tab's Role filter filters on it. Archived rows carry
 *   `role` too, for the Archived tab's own role filter, even though that table
 *   has no Role column.
 * - **The review state is not a field.** It is the `intake_processed_cs` flag on
 *   the submission — the same flag `custom_module`'s `FlagIntakeForm()` writes,
 *   so this endpoint and the chiropractor's existing intake screen agree. A
 *   submission with no flagging is implicitly `new`.
 * - **Takes no `clinic_id` from the query string**, unlike `/api/intake/submit`,
 *   which is anonymous and legitimately token-scoped.
 * - **Sends `enrolledCount` and `intakeNewCount`.** Two extra queries on the
 *   endpoint's side, so the header pill and the intake badge cannot disagree
 *   with counts the client worked out differently. Both queries apply the same
 *   `status = 1` filter as the roster, so the pill cannot count a patient the
 *   list below it is hiding.
 *
 * ## `null` versus `""`
 *
 * The endpoint returns JSON `null` for a field that is not recorded, because that
 * is what the database says. The types above use `""`, because that is what a
 * component can drop straight into an input or render as an em dash. The
 * conversion happens once, in `normalise()` below, so there is exactly one place
 * that knows about the difference and a component never has to.
 *
 * Paging is deliberately client-side here, matching the log history and the
 * message thread: one fetch, `PER`-sized slices. With 30k archived accounts in
 * this database that will need cursor paging server-side, which is a later
 * change and not a reason to hold the endpoint back.
 */
export async function getPatients(): Promise<PatientsSnapshot> {
  let response: Response;
  try {
    response = await drupalFetch("/api/headless/patients");
  } catch (cause) {
    throw new PatientsUnavailableError("Could not reach the patients service.", 0, { cause });
  }

  if (!response.ok) {
    throw new PatientsUnavailableError(
      `The patients service responded ${response.status}.`,
      response.status,
    );
  }

  let body: unknown;
  try {
    body = await response.json();
  } catch (cause) {
    throw new PatientsUnavailableError(
      "The patients service returned a response that could not be read.",
      response.status,
      { cause },
    );
  }

  return normalise(body);
}

/**
 * Turns the endpoint's JSON into the shape the components expect.
 *
 * A hand-written narrowing rather than a bare `as PatientsSnapshot`, because a
 * cast asserts nothing: a missing key or a `null` where a number was promised
 * would sail through it and surface later as `undefined.length` in a table
 * hundreds of rows down. Here a shape that does not match is an error while the
 * page can still say which field was wrong.
 *
 * Numbers arrive as `null` for "not recorded" and become `""`; `0` is left alone,
 * because a patient who has lost nothing is a real row, not a blank one.
 */
function normalise(body: unknown): PatientsSnapshot {
  if (!isRecord(body)) {
    throw new PatientsUnavailableError(
      "The patients service returned something that is not a roster.",
      200,
    );
  }

  const active = asArray(body.active, "active").map((row, i) => {
    const r = asRecord(row, `active[${i}]`);
    return {
      id: asNumber(r.id, `active[${i}].id`),
      role: asRole(r.role, `active[${i}].role`),
      name: asString(r.name, `active[${i}].name`),
      email: asString(r.email, `active[${i}].email`),
      phase: asPhase(r.phase, `active[${i}].phase`),
      programStart: asText(r.programStart, `active[${i}].programStart`),
      programDay: asNumberOrZero(r.programDay, `active[${i}].programDay`),
      // `null` when never recorded, so the column can render an em dash.
      startWeight: asNullableNumber(r.startWeight, `active[${i}].startWeight`),
      // Kept as `null` rather than folded into 0. The column sorts on this value
      // and colours it by sign, so collapsing unknown into zero put unrecorded
      // patients in the middle of the list, painted as a genuine zero-pound
      // result. A chiropractor reading the sort order cannot tell a real 0 from a
      // missing figure, and those are different facts about the patient.
      netLoss: asNullableNumber(r.netLoss, `active[${i}].netLoss`),
    };
  });

  const archived = asArray(body.archived, "archived").map((row, i) => {
    const r = asRecord(row, `archived[${i}]`);
    return {
      id: asNumber(r.id, `archived[${i}].id`),
      role: asRole(r.role, `archived[${i}].role`),
      name: asString(r.name, `archived[${i}].name`),
      email: asString(r.email, `archived[${i}].email`),
      programStart: asText(r.programStart, `archived[${i}].programStart`),
      programDay: asNumberOrZero(r.programDay, `archived[${i}].programDay`),
      // Which location they were at before they archived. Kept nullable because it
      // usually is null — see types.ts — and the re-enrollment step needs to tell
      // "no location on file" apart from "location 0".
      clinicLocation: asNullableNumber(r.clinicLocation, `archived[${i}].clinicLocation`),
      // The re-enroll form's prefill. Each is read defensively for the same reason
      // `clinicLocation` is: the endpoint may be older than this file during a
      // rolling deploy, and a missing key has to degrade to an empty field rather
      // than take the whole Patients tab down with it.
      phone: asText(r.phone, `archived[${i}].phone`),
      // Read through the same `asPhase` as the roster, deliberately: a phase code
      // this build does not recognise is a contract change worth failing on rather
      // than quietly presenting as "no phase". A patient who genuinely has none
      // sends null, and that still reads as "".
      phase: asPhase(r.phase, `archived[${i}].phase`),
      startWeight: asNullableNumber(r.startWeight, `archived[${i}].startWeight`),
      goalWeight: asNullableNumber(r.goalWeight, `archived[${i}].goalWeight`),
      // Subscribed is the default, not `false`. A response that predates this field,
      // or a patient with nothing in `field_email_optout`, both mean "never opted
      // out", and defaulting to unsubscribed would show the chiropractor a toggle
      // that disagrees with what the patient would actually receive.
      emailNotifications: r.emailNotifications === false ? false : true,
    };
  });

  const intake = asArray(body.intake, "intake").map((row, i) => {
    const r = asRecord(row, `intake[${i}]`);
    const firstName = asText(r.firstName, `intake[${i}].firstName`);
    const lastName = asText(r.lastName, `intake[${i}].lastName`);
    return {
      id: asNumber(r.id, `intake[${i}].id`),
      firstName,
      lastName,
      name: `${firstName} ${lastName}`.trim(),
      email: asText(r.email, `intake[${i}].email`),
      phone: asText(r.phone, `intake[${i}].phone`),
      // The design sorts and compares this column, and uses `0` for a form left
      // blank, so unknown collapses to 0 here exactly as the sample data does.
      goalWeight: asWeightOrZero(r.goalWeight),
      // The endpoint sends a Unix timestamp; the design column is minute precision.
      submitted: asTimestamp(asNumber(r.submitted, `intake[${i}].submitted`)),
      programStart: asText(r.programStart, `intake[${i}].programStart`),
      status: asIntakeStatus(r.status),
    };
  });

  return {
    active,
    archived,
    intake,
    clinicLocations: normaliseLocations(body.clinicLocations),
    intakeLink: asText(body.intakeLink, "intakeLink"),
    enrolledCount: asNumberOrZero(body.enrolledCount, "enrolledCount"),
    enrollmentAllowance: normaliseAllowance(body.enrollmentAllowance),
    intakeNewCount: asNumberOrZero(body.intakeNewCount, "intakeNewCount"),
  };
}

/**
 * The clinic's locations, as id/name pairs.
 *
 * Missing is treated as empty rather than as an error. The key is new, so a
 * frontend deployed against an older backend would otherwise fail the whole
 * snapshot on a field that only affects two dropdowns — and the failure would be
 * indistinguishable from the page being broken. An empty list is also a genuine
 * answer, so the two are the same thing to every caller anyway: no dropdown
 * options, and the backend's default of leaving the location alone applies.
 *
 * A row that is present but malformed is still an error, per the rest of this
 * function. Tolerating a missing key is not the same as tolerating a broken one.
 */
function normaliseAllowance(value: unknown): EnrollmentAllowance | null {
  if (value === null || value === undefined) return null;
  const row = asRecord(value, "enrollmentAllowance");
  const limit = asNullableNumber(row.limit, "enrollmentAllowance.limit");
  const used = asNumber(row.used, "enrollmentAllowance.used");
  if (!Number.isSafeInteger(used) || used < 0 || (limit !== null && (!Number.isSafeInteger(limit) || limit < 0))) {
    throw new PatientsUnavailableError("The enrollment allowance contains an invalid count.", 200);
  }
  return {
    planName: asString(row.planName, "enrollmentAllowance.planName"),
    scheduledPlanName: typeof row.scheduledPlanName === "string" ? row.scheduledPlanName : null,
    limit,
    used,
    remaining: limit === null ? null : Math.max(0, limit - used),
  };
}

function normaliseLocations(value: unknown): ClinicLocation[] {
  if (value === null || value === undefined) return [];

  return asArray(value, "clinicLocations").map((row, i) => {
    const r = asRecord(row, `clinicLocations[${i}]`);
    return {
      id: asNumber(r.id, `clinicLocations[${i}].id`),
      // The backend never sends a blank name — it falls back to "Location {id}" —
      // so a blank here means the contract changed, not that the location is
      // unnamed. Silently substituting would hide that.
      name: asString(r.name, `clinicLocations[${i}].name`),
    };
  });
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === "object" && value !== null && !Array.isArray(value);
}

function asRecord(value: unknown, field: string): Record<string, unknown> {
  if (!isRecord(value)) {
    throw new PatientsUnavailableError(`The roster's ${field} is not an object.`, 200);
  }
  return value;
}

function asArray(value: unknown, field: string): unknown[] {
  if (!Array.isArray(value)) {
    throw new PatientsUnavailableError(`The roster's ${field} is not a list.`, 200);
  }
  return value;
}

function asString(value: unknown, field: string): string {
  if (typeof value !== "string") {
    throw new PatientsUnavailableError(`The roster's ${field} is not text.`, 200);
  }
  return value;
}

/** A required string, where absent is an error rather than an empty cell. */
function asText(value: unknown, field: string): string {
  if (value === null || value === undefined) return "";
  return asString(value, field);
}

/** A number that must actually be there: an id, a program day. */
function asNumber(value: unknown, field: string): number {
  if (typeof value !== "number" || !Number.isFinite(value)) {
    throw new PatientsUnavailableError(`The roster's ${field} is not a number.`, 200);
  }
  return value;
}

/** A count or a day, where a missing value is harmless enough to default. */
function asNumberOrZero(value: unknown, field: string): number {
  if (value === null || value === undefined) return 0;
  return asNumber(value, field);
}

/** A measurement that can be unknown, keeping `null` as `null`. */
function asNullableNumber(value: unknown, field: string): number | null {
  if (value === null || value === undefined) return null;
  return asNumber(value, field);
}

/** A measurement, where unknown is indistinguishable from zero. */
function asWeightOrZero(value: unknown): number {
  if (value === null || value === undefined) return 0;
  if (typeof value !== "number" || !Number.isFinite(value)) return 0;
  return value;
}

const PHASE_CODES = new Set(["L", "D", "Z", "C", "M"]);

function asPhase(value: unknown, field: string): PhaseCode | "" {
  // Staff rows have no program, so the endpoint sends null. A value that is
  // present but unrecognised is a real contract change and is worth failing on.
  if (value === null || value === undefined || value === "") return "";
  if (typeof value !== "string" || !PHASE_CODES.has(value)) {
    throw new PatientsUnavailableError(`The roster's ${field} is not a known phase.`, 200);
  }
  return value as PhaseCode;
}

/**
 * An unknown status becomes `new`, never `checked`.
 *
 * Safe because `new` is the one that makes the row visible in the unreviewed
 * filter and the badge. Defaulting the other way would hide a submission
 * somebody has not read yet, which is the failure this endpoint is least
 * allowed to have.
 */
function asIntakeStatus(value: unknown): IntakeStatus {
  return value === "checked" ? "checked" : "new";
}

function asRole(value: unknown, field: string): PatientRole {
  if (value === "patient" || value === "coach" || value === "doctor") return value;
  throw new PatientsUnavailableError(`The roster's ${field} is not a known role.`, 200);
}

function asTimestamp(seconds: number): string {
  const date = new Date(seconds * 1000);
  const pad = (n: number) => String(n).padStart(2, "0");
  return `${date.getUTCFullYear()}-${pad(date.getUTCMonth() + 1)}-${pad(date.getUTCDate())} ${pad(
    date.getUTCHours(),
  )}:${pad(date.getUTCMinutes())}`;
}

/**
 * The memoised entry point — use this one.
 *
 * Both the route layout and the page need this snapshot: the layout draws the
 * header's enrolled pill from it and the page draws the four tabs. `cache` keys
 * on the argument list, and both call sites pass none, so a single upstream
 * request serves the render rather than one per consumer. It is scoped to the
 * request, so nothing leaks between users.
 */
export const getPatientsCached = cache(getPatients);

/** Rows per page, the design's `PER`. */
export const PER_PAGE = 10;