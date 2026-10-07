import "server-only";
import { NextResponse } from "next/server";
import { drupalFetch } from "@/lib/drupal/client";
import { fetchSession } from "@/lib/drupal/session";
import { resolvePortalAccess } from "@/lib/portal";

/**
 * POST /api/content/training — add a training item in the caller's clinic.
 *
 * Proxies `POST /api/headless/content/training/create`, but it is not a blind
 * forward. The Drupal route is gated by `WriteChiropractorAccess` (active
 * chiropractors only); this handler repeats that check from the session data so
 * the page's button and the endpoint behind it are enforced in both places, and
 * so a caller cannot reach the write by POSTing here without the role.
 *
 * The body is passed through untouched. The endpoint's own validation owns the
 * field rules ("title is required", "The \"video\" file does not exist") and its
 * messages are what the form shows, so re-encoding the body here would be a
 * second, weaker copy of that rule. A training video, when there is one, is
 * uploaded first to `/api/content/training/upload`, which returns the fid this
 * payload names as `video`.
 */
export async function POST(request: Request): Promise<NextResponse> {
  let session;
  try {
    session = await fetchSession();
  } catch (error) {
    // The session endpoint itself is unreachable, so identity cannot be
    // established at all — a 502, not a "sign in again" lie.
    console.error("[api/content/training] session check failed", error);
    return NextResponse.json(
      { error: "unavailable", message: "The server could not be reached." },
      { status: 502 },
    );
  }

  if (!session) {
    return NextResponse.json(
      { error: "unauthenticated", message: "Please sign in again." },
      { status: 401 },
    );
  }

  if (session.capabilities?.billingOnly) {
    return NextResponse.json(
      { error: "billing", message: "Finish subscription setup before adding training." },
      { status: 403 },
    );
  }

  const access = resolvePortalAccess(
    session.roles,
    session.capabilities,
    session.portalAccess,
  );

  // The one caller the create route admits: an active chiropractor. An inactive
  // chiropractor is read-only everywhere, and a patient cannot write content no
  // matter how they reach this handler.
  if (!access || access.audience !== "chiropractor" || access.readOnly) {
    return NextResponse.json(
      { error: "forbidden", message: "Only an active doctor may add training." },
      { status: 403 },
    );
  }

  let payload: unknown;
  try {
    payload = await request.json();
  } catch {
    return NextResponse.json(
      { error: "bad_request", message: "The request body must be JSON." },
      { status: 400 },
    );
  }

  if (typeof payload !== "object" || payload === null || Array.isArray(payload)) {
    return NextResponse.json(
      { error: "bad_request", message: "The request body must be a JSON object." },
      { status: 400 },
    );
  }

  const body = JSON.stringify(payload);

  let response: Response;
  try {
    response = await drupalFetch("/api/headless/content/training/create", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body,
    });
  } catch (error) {
    console.error("[api/content/training] Drupal request failed", error);
    return NextResponse.json(
      { error: "unavailable", message: "The training item could not be added." },
      { status: 502 },
    );
  }

  if (response.status === 307 || response.status === 302) {
    return NextResponse.json(
      { error: "unauthenticated", message: "Please sign in again." },
      { status: 401 },
    );
  }

  const data = (await response.json().catch(() => ({}))) as {
    error?: unknown;
  };

  if (!response.ok) {
    // 400 = a field rule the form's own validation may have missed; 403 = an
    // account the role gate caught (should not happen, both gates agree). The
    // endpoint's message is passed through because the form shows it.
    return NextResponse.json(
      {
        error: typeof data.error === "string" ? data.error : "drupal_error",
        message:
          typeof data.error === "string" && data.error
            ? data.error
            : `Drupal responded ${response.status}`,
      },
      { status: response.status },
    );
  }

  return NextResponse.json(data, { status: 201 });
}