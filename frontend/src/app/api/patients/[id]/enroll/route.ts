import "server-only";
import { NextResponse } from "next/server";
import { drupalFetch } from "@/lib/drupal/client";
import { weightProblem as sharedWeightProblem } from "@/lib/patients/weight";

/**
 * The re-enroll form's fields, and what counts as a legal value for each.
 *
 * This is the allowlist. A key that is not in it is never forwarded to Drupal, so
 * this handler cannot be used as a general-purpose write proxy onto a patient
 * record — `field_clinic`, the chiropractor, and the roles in particular are not
 * reachable from here at all.
 *
 * Each entry returns a message when the value is unacceptable and `null` when it is
 * fine, so the loop that applies them can stay a single `for`. These are shape
 * checks only, deliberately: whether a clinic location belongs to *this* clinic, or
 * whether an email is already taken, is a question about the database and is
 * answered by the Drupal endpoint, which owns those rules. Duplicating them here
 * would give two places to keep in step and a client able to disagree with the
 * server about what is valid.
 */
const ENROLL_FIELDS: Record<string, (value: unknown) => string | null> = {
  name: (value) =>
    typeof value === "string" && value.trim() !== ""
      ? null
      : "Patient name must be text.",
  email: (value) =>
    typeof value === "string" && /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value.trim())
      ? null
      : "Email address is not valid.",
  // Kept loose on purpose: phone formatting is the site's business and the legacy
  // form was equally permissive, so anything non-empty is passed through for Drupal
  // to clean rather than being rejected for looking unusual.
  phone: (value) =>
    typeof value === "string" ? null : "Phone number must be text.",
  programStart: (value) => {
    if (typeof value !== "string" || !/^\d{4}-\d{2}-\d{2}$/.test(value)) {
      return "Program start must be a YYYY-MM-DD date.";
    }
    // Past dates are refused, and this is only safe because the form omits the key
    // when the date is untouched: the row is prefilled with the date the patient
    // originally started, so for exactly the patients this endpoint exists for, the
    // stored date *is* in the past. A date that arrives here is one the chiropractor
    // has just chosen, and a start date in the past is not a start date.
    if (value < todayUtc()) {
      return "Program start cannot be in the past.";
    }
    return null;
  },
  startWeight: (value) => weightProblem(value, "Start weight"),
  goalWeight: (value) => weightProblem(value, "Goal weight"),
  emailNotifications: (value) =>
    typeof value === "boolean" ? null : "Email notifications must be true or false.",
  phase: (value) =>
    typeof value === "string" && PHASE_CODES.has(value)
      ? null
      : "Phase is not a known phase.",
  clinicLocation: (value) =>
    typeof value === "number" && Number.isInteger(value) && value > 0
      ? null
      : "Clinic location must be a clinic location id.",
};

/**
 * The stored phase codes, mirroring `PHASE_LABELS` in `lib/patients/types`.
 *
 * A duplicated set rather than an import, because `types.ts` pulls in `server-only`
 * transitively through `data.ts` and a route handler cannot import it. The cost is
 * one line that has to change alongside the phase list; the alternative is a route
 * that cannot be built.
 */
const PHASE_CODES = new Set(["L", "D", "Z", "C", "M"]);

/**
 * Today, as `YYYY-MM-DD`, for the program start comparison.
 *
 * UTC because a route handler has no Drupal bootstrap to ask the site for its
 * timezone. The risk is one boundary hour where a start date the chiropractor
 * considers today is read as tomorrow, which passes; the reverse — a date read as
 * today that they consider yesterday — is the only one that costs a confusing 422,
 * and it is the client's job to catch it first.
 */
function todayUtc(): string {
  return new Date().toISOString().slice(0, 10);
}

function weightProblem(value: unknown, label: string): string | null {
  if (typeof value !== "number" || !Number.isFinite(value)) {
    return `${label} must be a number.`;
  }
  // The bounds live in `lib/patients/weight.ts` so this cannot drift from what the
  // forms accept. What is added here is the reason 0 survives: zero is a value the
  // archive already holds (`startWeight` is `number | null` precisely to keep 0
  // ("measured, and the reading was 0") apart from null ("never recorded"), and this
  // form is prefilled from that archive. Rejecting 0 would make an untouched
  // confirmation of such a record fail on a number the record legitimately contains —
  // the blank-box problem again, reached by a different route.
  return sharedWeightProblem(value, label);
}

/**
 * POST /api/patients/[id]/enroll — re-enrol an archived patient.
 *
 * Proxies `POST /api/headless/patients/{user}/enroll`.
 *
 * ## Why this is not the create endpoint
 *
 * Re-enrolling is a different operation, not a variant of creating one. The account
 * already exists and already has a program behind it; this swaps
 * `archived_patient` for `enrolled_patient` and nothing else.
 *
 * What it shares with create is the enrollment limit: the cap counts accounts
 * holding `enrolled_patient`, and re-enrolling hands that role back, so it spends a
 * slot exactly as creating a patient does. 409 therefore means the same thing here
 * as it does there, and the client reads it the same way.
 *
 * ## The fields it takes
 *
 * Any of `name`, `email`, `phone`, `programStart`, `startWeight`, `goalWeight`,
 * `emailNotifications`, `phase` and `clinicLocation` — the same set the Add Patient
 * form collects, because a returning patient is edited by the same form that
 * created them. All of them are prefilled from the archived row, so confirming
 * without changing anything leaves the record exactly as it was.
 *
 * `programStart` is the one field with a rule of its own, and the prefilling is why:
 * an archived patient's stored start date is in the past by definition, so a
 * blanket "no past dates" check would refuse an untouched confirmation for exactly
 * the people this endpoint is for. The form therefore sends the key only when the
 * date was changed, which makes any date that arrives here a newly chosen one — and
 * that is the only case checked against today.
 *
 * `clinicLocation` is the one this operation originally grew: re-enrolling never
 * touched the location before, so the patient came back onto whatever branch they
 * had left — and most archived patients have none, so there was often nothing to
 * come back onto. Which branch a returning patient belongs to is a decision only
 * the chiropractor can make, so it is asked for at the point of the action rather
 * than derived.
 *
 * An absent key means "no change", and the endpoint leaves that field alone. That
 * distinction is the reason this does not simply forward the whole body: a missing
 * key and a null would both have to mean "leave it", and a client that sends `{}`
 * must not clear the patient's real phone number or measurements.
 */
export async function POST(
  request: Request,
  context: { params: Promise<{ id: string }> },
): Promise<NextResponse> {
  const { id } = await context.params;

  // Validated before it reaches the path, for the reason the favourite handler
  // documents: the id is interpolated into a URL Drupal matches against `\d+`, and a
  // non-numeric value should be a 400 from here rather than a 404 from the matcher.
  if (!/^\d+$/.test(id)) {
    return NextResponse.json(
      { error: "bad_request", message: "Patient id must be numeric." },
      { status: 400 },
    );
  }

  // An absent or empty body is the documented way to say "no choice made". The
  // create endpoint rejects an empty body because it has fields to receive; this one
  // does not, so it must not start rejecting clients that send none.
  //
  // That distinction is why this reads the raw text first. `request.json()` throws for
  // an empty body and for broken JSON alike, so parsing inside a try/catch would treat
  // a client that sent malformed JSON as one that sent nothing — and re-enrol the
  // patient on the old location after telling the chiropractor their choice was sent.
  // Being able to tell those two apart is the whole point of the check below.
  const raw = await request.text();
  let changes: Record<string, unknown> = {};

  if (raw.trim() !== "") {
    let parsed: unknown;
    try {
      parsed = JSON.parse(raw);
    } catch {
      return NextResponse.json(
        { error: "bad_request", message: "Request body must be JSON." },
        { status: 400 },
      );
    }

    // A JSON array, string or number is not the object shape this endpoint takes. It
    // is rejected rather than ignored, for the same reason a malformed value is: a
    // client that sent something it thought was a body should be told, not have it
    // quietly become a re-enrollment that changed nothing.
    if (parsed === null || typeof parsed !== "object" || Array.isArray(parsed)) {
      return NextResponse.json(
        { error: "bad_request", message: "Request body must be a JSON object." },
        { status: 400 },
      );
    }

    const body = parsed as Record<string, unknown>;
    const forwarded: Record<string, unknown> = {};

    // Each field is checked here as well as in `enrollPatient()`, because this
    // handler is the boundary: anything can POST to it, and the Drupal endpoint is
    // not reachable from the browser. A key is copied only if it passes, so an
    // unknown key is dropped and a known key with the wrong type is a 400 — never
    // both forwarded.
    for (const [key, check] of Object.entries(ENROLL_FIELDS)) {
      const value = body[key];
      // Absent, null and "" all mean "leave this field alone", which is also what
      // the endpoint would do with them, so they are dropped here rather than
      // forwarded as an instruction to write nothing.
      if (value === undefined || value === null || value === "") continue;

      const problem = check(value);
      if (problem) {
        return NextResponse.json(
          { error: "bad_request", message: problem, errors: { [key]: problem } },
          { status: 400 },
        );
      }
      forwarded[key] = value;
    }

    changes = forwarded;
  }

  let response: Response;
  try {
    response = await drupalFetch(`/api/headless/patients/${id}/enroll`, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      // Always an object, never a bare value: Drupal matches the body as JSON and
      // `{}` is the honest encoding of "nothing to change".
      body: JSON.stringify(changes),
    });
  } catch (error) {
    console.error("[api/patients/enroll] Drupal request failed", error);
    return NextResponse.json({ error: "unavailable" }, { status: 502 });
  }

  // `fetch` follows redirects, so Drupal's redirect to the login form arrives as a
  // 200 with an HTML body. Turning that into a 401 is what lets the client send the
  // chiropractor back to sign in.
  if (response.status === 307 || response.status === 302) {
    return NextResponse.json(
      { error: "unauthenticated", message: "Please sign in again." },
      { status: 401 },
    );
  }

  const data = (await response.json().catch(() => ({}))) as {
    patient?: unknown;
    warning?: unknown;
    error?: unknown;
    errors?: unknown;
  };

  if (!response.ok) {
    return NextResponse.json(
      {
        error:
          typeof data.error === "string" && data.error
            ? data.error
            : `Drupal responded ${response.status}`,
        ...(data.errors && typeof data.errors === "object" ? { errors: data.errors } : {}),
      },
      { status: response.status },
    );
  }

  // Same shape as the create handler: the soft-limit warning rides along on the
  // success, because a chiropractor who has just re-enrolled and is about to be told
  // they are near their cap should still be told.
  return NextResponse.json({
    patient: data.patient,
    warning: typeof data.warning === "string" && data.warning ? data.warning : null,
  });
}