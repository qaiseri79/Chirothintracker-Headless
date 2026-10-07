import "server-only";
import { NextResponse } from "next/server";
import { drupalFetch, sessionCookieHeader } from "@/lib/drupal/client";

/**
 * One intake submission, read or deleted.
 *
 * ## Why this proxy exists at all
 *
 * `GET /api/headless/patients/intake/{message}` has existed on Drupal for a while
 * and nothing ever called it. The intake table's "View intake form" button was
 * wired straight to a click handler that mutated a copy of the row, so clicking
 * it did nothing a chiropractor could use. This is the missing browser-side half
 * of a pair that already had a server side.
 *
 * The same reasoning as `/api/progress/entry/[messageId]`, which this is modelled
 * on: the browser holds no Drupal session cookie, so every authenticated read and
 * write is forwarded from here rather than from the component.
 *
 * ## `id` is interpolated into the Drupal path
 *
 * Constrained to digits by the Drupal route requirement. `encodeURIComponent` is
 * belt-and-braces so a crafted segment cannot escape the path, and a non-numeric
 * id is refused before the request is made at all rather than relying on the
 * upstream to notice.
 */

const DIGITS = /^\d+$/;

/** The Drupal path for one submission. Shared by both verbs. */
function drupalPath(id: string): string | null {
  if (!DIGITS.test(id)) return null;
  return `/api/headless/patients/intake/${encodeURIComponent(id)}`;
}

/**
 * Maps a Drupal failure onto a status the client can act on.
 *
 * The 307 → /user/login case is the one worth spelling out: Drupal answers an
 * unauthenticated request to a `_auth: cookie` route with a redirect, not a 401,
 * because the browser is expected to follow it. `drupalFetch` sets
 * `redirect: "manual"`, so that 307 arrives here. Mapping it to 401 is what lets
 * the UI say "your session has expired" rather than "unable to delete this
 * submission".
 */
async function failure(response: Response, label: string): Promise<NextResponse> {
  if (response.status >= 300 && response.status < 400) {
    console.error(`[patients/intake] Drupal redirect on ${label} (unauthenticated)`);
    return NextResponse.json(
      { error: "unauthenticated", message: "Your session has expired. Please sign in again." },
      { status: 401 },
    );
  }

  const body = (await response.json().catch(() => null)) as {
    error?: string;
    message?: string;
  } | null;

  console.error(`[patients/intake] Drupal ${label} responded ${response.status}`, body?.message ?? body);

  const status = response.status >= 400 && response.status < 600 ? response.status : 502;
  return NextResponse.json(
    {
      // 404 is passed through verbatim rather than given a friendlier wording
      // here: "not found" is true, and the client already has something specific
      // to say about a submission that is gone.
      error: body?.error ?? "request_failed",
      message: body?.message ?? "The intake service could not complete that request.",
    },
    { status },
  );
}

export async function GET(
  _request: Request,
  { params }: { params: Promise<{ id: string }> },
): Promise<NextResponse> {
  const { id } = await params;
  const path = drupalPath(id);
  if (!path) {
    return NextResponse.json({ error: "bad_request", message: "Submission id must be a number." }, { status: 400 });
  }

  let response: Response;
  try {
    response = await drupalFetch(path);
  } catch (error) {
    console.error("[patients/intake] Drupal read request failed", error);
    return NextResponse.json({ error: "unavailable", message: "The intake service is unreachable." }, { status: 502 });
  }

  if (!response.ok) return failure(response, "read");

  const data = await response.json();
  return NextResponse.json(data);
}

/**
 * Permanently deletes one intake submission.
 *
 * A hard delete on Drupal: the `contact_message` row and its field data go, and
 * there is no archived state to recover from. The confirm dialog is the only
 * thing standing between a misclick and a patient's own words, which is why this
 * route takes no confirmation flag from the client — a caller who has not asked a
 * human still gets the same irreversible write.
 */
export async function DELETE(
  _request: Request,
  { params }: { params: Promise<{ id: string }> },
): Promise<NextResponse> {
  const { id } = await params;
  const path = drupalPath(id);
  if (!path) {
    return NextResponse.json({ error: "bad_request", message: "Submission id must be a number." }, { status: 400 });
  }

  let response: Response;
  try {
    const cookie = await sessionCookieHeader();
    response = await drupalFetch(path, { method: "DELETE", headers: { Cookie: cookie } });
  } catch (error) {
    console.error("[patients/intake] Drupal delete request failed", error);
    return NextResponse.json({ error: "unavailable", message: "The intake service is unreachable." }, { status: 502 });
  }

  if (!response.ok) return failure(response, "delete");

  const result = (await response.json().catch(() => null)) as { id?: number } | null;
  return NextResponse.json({ id: result?.id ?? Number(id), deleted: true });
}