import "server-only";
import { NextResponse } from "next/server";
import { drupalFetch, sessionCookieHeader } from "@/lib/drupal/client";

interface UpdateBody {
  fields: Record<string, unknown>;
}

export async function POST(
  request: Request,
  { params }: { params: Promise<{ messageId: string }> },
): Promise<NextResponse> {
  const { messageId } = await params;

  let payload: UpdateBody;
  try {
    payload = (await request.json()) as UpdateBody;
  } catch {
    return NextResponse.json(
      { error: "bad_request", message: "Invalid JSON body." },
      { status: 400 },
    );
  }

  const fields = payload.fields ?? {};

  let response: Response;
  try {
    const cookie = await sessionCookieHeader();
    response = await drupalFetch(`/api/headless/progress/update/${messageId}`, {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        Cookie: cookie,
      },
      body: JSON.stringify({ fields }),
    });
  } catch (error) {
    console.error("[tracking/update] Drupal update request failed", error);
    return NextResponse.json({ error: "unavailable" }, { status: 502 });
  }

  if (!response.ok) {
    let body: unknown = null;
    try {
      body = (await response.json()) as unknown;
    } catch {
    }
    const detail = (body ?? {}) as { error?: string; message?: string };
    console.error(
      `[tracking/update] Drupal responded ${response.status}`,
      detail.message ?? body,
    );
    // Pass the upstream status through instead of throwing. The failure modes
    // here are ordinary and expected — 403 for somebody else's log, 404 for a
    // deleted one, 400 for a malformed body — and collapsing them all into an
    // opaque 500 told the patient their edit failed for no reason.
    const status = response.status >= 400 && response.status < 600 ? response.status : 502;
    return NextResponse.json(
      {
        error: detail.error ?? "update_failed",
        message: detail.message ?? "Unable to update this progress log.",
      },
      { status },
    );
  }

  const result = (await response.json().catch(() => null)) as
    | { id?: number; uuid?: string }
    | null;

  return NextResponse.json({
    id: result?.id ?? null,
    uuid: result?.uuid ?? null,
  });
}
