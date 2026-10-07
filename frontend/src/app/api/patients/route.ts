import "server-only";
import { NextResponse } from "next/server";
import { drupalFetch } from "@/lib/drupal/client";

/**
 * POST /api/patients — create a patient account.
 *
 * Proxies `POST /api/headless/patients`. The body is passed through untouched, for
 * the same reason the favourite handler does it: the endpoint owns the write rules,
 * and re-encoding or filtering the payload here would be a second, weaker copy of
 * them that could disagree about which keys are legal.
 *
 * The endpoint serves both the dashboard's Add-patient form and intake enrolment,
 * so a caller only sends the keys its form actually has. In particular it accepts an
 * optional `clinicLocation`, an optional `laserStatus` and an optional
 * `intakeSubmission`, and `field_clinic` itself is never taken from the body.
 *
 * ## Status codes worth distinguishing
 *
 *   - 422 with `errors` keyed by payload field: a form the user can fix. Forwarded
 *     whole, because the Add form shows those messages against the offending inputs
 *     and re-deriving them here would mean a second set of validation messages.
 *   - 409: the chiropractor has hit their hard enrollment limit. Nothing was created
 *     and retrying will not help; the message says what to do instead.
 *   - 401: translated from Drupal's 307-to-login, as in the favourite handler.
 */
export async function POST(request: Request): Promise<NextResponse> {
  const body = await request.text();

  let response: Response;
  try {
    response = await drupalFetch("/api/headless/patients", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body,
    });
  } catch (error) {
    console.error("[api/patients] Drupal request failed", error);
    return NextResponse.json({ error: "unavailable" }, { status: 502 });
  }

  // `fetch` follows redirects, so Drupal's 302/307 to the login form arrives here
  // as a 200 with an HTML body. Catching it is what turns an expired session into
  // something the client can act on.
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
        // Only echoed back when the endpoint produced it, so "no field errors" and
        // "field errors I failed to parse" stay distinguishable.
        ...(data.errors && typeof data.errors === "object"
          ? { errors: data.errors }
          : {}),
      },
      { status: response.status },
    );
  }

  // `warning` rides along on the success response: it is the soft enrollment-limit
  // notice, which is not an error and must not turn a successful create into a
  // failure. Absent is normalised to null so the client has one shape to read.
  return NextResponse.json({
    patient: data.patient,
    warning: typeof data.warning === "string" && data.warning ? data.warning : null,
  });
}