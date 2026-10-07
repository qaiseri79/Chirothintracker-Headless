import "server-only";
import { NextResponse } from "next/server";
import { drupalFetch, sessionCookieHeader } from "@/lib/drupal/client";

interface SubmitBody {
  fields: Record<string, unknown>;
}

/**
 * Submits the tracking_weight contact form to Drupal.
 * Calls the custom headless_progress endpoint.
 */
export async function POST(request: Request): Promise<NextResponse> {
  let payload: SubmitBody;
  try {
    payload = (await request.json()) as SubmitBody;
  } catch {
    return NextResponse.json(
      { error: "bad_request", message: "Invalid JSON body." },
      { status: 400 },
    );
  }

  const fields = payload.fields ?? {};

  // Forward to Drupal's custom endpoint
  let response: Response;
  try {
    const cookie = await sessionCookieHeader();
    response = await drupalFetch("/api/headless/progress/submit", {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        Cookie: cookie,
      },
      body: JSON.stringify({ fields }),
    });
  } catch (error) {
    console.error("[tracking/submit] Drupal submit request failed", error);
    return NextResponse.json({ error: "unavailable" }, { status: 502 });
  }

  if (!response.ok) {
    let body: unknown = null;
    try {
      body = (await response.json()) as unknown;
    } catch {
      // Non-JSON error body
    }
    return NextResponse.json(body ?? { error: "Unable to save progress." }, { status: response.status });
  }

  const result = (await response.json().catch(() => null)) as
    | { id?: number; uuid?: string }
    | null;

  return NextResponse.json({
    id: result?.id ?? null,
    uuid: result?.uuid ?? null,
  });
}