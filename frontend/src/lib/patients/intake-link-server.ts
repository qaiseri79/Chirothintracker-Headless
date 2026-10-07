import "server-only";

import { NextResponse } from "next/server";
import { drupalFetch } from "@/lib/drupal/client";

/**
 * Forwards the two intake-link writes to Drupal.
 *
 * `ClinicIntakeLinkController` answers both with `{url}` or `{error}`, and both are
 * reached through `_auth: ['cookie']`, so the browser needs the Next route handlers
 * under `/api/intake-link` to carry its session — the same reason
 * `app/api/patients/intake/review/route.ts` exists.
 *
 * Shared because the status mapping is the part worth stating once. The three cases
 * that are not "it worked" are all different problems wearing the same 4xx:
 *
 * - **401/302/307** — the session is gone. The only status the UI can act on by
 *   sending the user somewhere else.
 * - **403** — the account holds a role the policy does not admit. A brand-new doctor
 *   is created `chiropractor_inactive_` and only becomes `chiropractor_active_` once a
 *   subscription grants portal write, and `ManageIntakeLinkAccess` admits the active
 *   role only. Drupal answers this with its access-denied page rather than a JSON
 *   body, so the wording here is ours and says what actually happened.
 * - **404** — regenerate was called with no link to replace. The endpoint treats that
 *   as a client bug on purpose, so it is passed through rather than papered over.
 *
 * Anything else that is not 2xx keeps Drupal's own `error` when it sent one, and falls
 * back to the caller's wording when it did not.
 */
export async function forwardIntakeLinkWrite(
  drupalPath: string,
  fallbackMessage: string,
): Promise<NextResponse> {
  try {
    const response = await drupalFetch(drupalPath, { method: "POST" });

    if (response.status === 302 || response.status === 307 || response.status === 401) {
      return NextResponse.json({ error: "Please sign in again." }, { status: 401 });
    }

    if (response.status === 403) {
      return NextResponse.json(
        {
          error:
            "This account cannot manage the clinic's intake link. Generating one needs an active chiropractor or administrator account.",
        },
        { status: 403 },
      );
    }

    const result = (await response.json().catch(() => null)) as {
      url?: unknown;
      error?: unknown;
    } | null;

    if (!response.ok) {
      return NextResponse.json(
        {
          error:
            typeof result?.error === "string" && result.error
              ? result.error
              : fallbackMessage,
        },
        { status: response.status },
      );
    }

    // `createLink` is idempotent and `regenerate` always writes one, so a success
    // without a URL means the shape changed. Reported rather than passed on as
    // `undefined`, which would render as an empty link and look like "no link yet".
    if (!result || typeof result.url !== "string" || !result.url) {
      return NextResponse.json({ error: fallbackMessage }, { status: 502 });
    }

    return NextResponse.json({ url: result.url }, { status: 200 });
  } catch {
    return NextResponse.json({ error: "Unable to reach the intake service." }, { status: 502 });
  }
}