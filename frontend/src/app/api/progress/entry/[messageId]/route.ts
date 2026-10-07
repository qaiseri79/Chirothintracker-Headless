import "server-only";
import { NextResponse } from "next/server";
import { drupalFetch, sessionCookieHeader } from "@/lib/drupal/client";

export async function GET(
  _request: Request,
  { params }: { params: Promise<{ messageId: string }> },
): Promise<NextResponse> {
  const { messageId } = await params;

  let response: Response;
  try {
    response = await drupalFetch(`/api/headless/progress/entry/${messageId}`);
  } catch (error) {
    console.error("[progress/entry] Drupal request failed", error);
    return NextResponse.json({ error: "unavailable" }, { status: 502 });
  }

  if (!response.ok) {
    const body = await response.json().catch(() => ({}));
    return NextResponse.json(
      { error: body.error ?? "drupal_error", message: body.message ?? `Drupal responded ${response.status}` },
      { status: response.status },
    );
  }

  const data = await response.json();
  return NextResponse.json(data);
}

/**
 * Deletes one tracking entry.
 *
 * GET and DELETE share this path on purpose: an entry is one resource, so the
 * proxy mirrors the Drupal route rather than inventing a separate delete URL.
 * `messageId` is interpolated into the Drupal path and is constrained to digits
 * by the Drupal route requirement; `encodeURIComponent` is belt-and-braces so a
 * crafted id cannot escape the path segment.
 *
 * The upstream status is passed through for the same reason as the update
 * route: 403 (somebody else's log), 404 (already deleted) and 400 are ordinary
 * outcomes that the confirm dialog needs in order to say something useful, and
 * flattening them to 500 would leave the patient guessing.
 */
export async function DELETE(
  _request: Request,
  { params }: { params: Promise<{ messageId: string }> },
): Promise<NextResponse> {
  const { messageId } = await params;
  const path = `/api/headless/progress/entry/${encodeURIComponent(messageId)}`;

  let response: Response;
  try {
    const cookie = await sessionCookieHeader();
    response = await drupalFetch(path, {
      method: "DELETE",
      headers: { Cookie: cookie },
    });
  } catch (error) {
    console.error("[progress/entry] Drupal delete request failed", error);
    return NextResponse.json({ error: "unavailable" }, { status: 502 });
  }

  if (!response.ok) {
    // Drupal answers an unauthenticated request to a `_auth: cookie` route
    // with a 307 to /user/login, not a 401, because the browser is expected to
    // follow it. `drupalFetch` sets `redirect: "manual"`, so that 307 arrives
    // here. Mapping it to 401 lets the confirm dialog say "your session
    // expired" instead of the useless "unable to delete this progress log"
    // that the 4xx/5xx-only rule below would produce.
    if (response.status >= 300 && response.status < 400) {
      console.error("[progress/entry] Drupal redirect on delete (unauthenticated)");
      return NextResponse.json(
        { error: "unauthenticated", message: "Your session has expired. Please sign in again." },
        { status: 401 },
      );
    }

    let body: unknown = null;
    try {
      body = (await response.json()) as unknown;
    } catch {
    }
    const detail = (body ?? {}) as { error?: string; message?: string };
    console.error(
      `[progress/entry] Drupal delete responded ${response.status}`,
      detail.message ?? body,
    );
    const status = response.status >= 400 && response.status < 600 ? response.status : 502;
    return NextResponse.json(
      {
        error: detail.error ?? "delete_failed",
        message: detail.message ?? "Unable to delete this progress log.",
      },
      { status },
    );
  }

  const result = (await response.json().catch(() => null)) as { id?: number } | null;
  return NextResponse.json({ id: result?.id ?? Number(messageId), deleted: true });
}
