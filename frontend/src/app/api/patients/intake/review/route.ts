import "server-only";
import { NextResponse } from "next/server";
import { drupalFetch } from "@/lib/drupal/client";

export async function POST(request: Request): Promise<NextResponse> {
  let payload: unknown;
  try {
    payload = await request.json();
  } catch {
    return NextResponse.json({ error: "Invalid JSON body." }, { status: 400 });
  }
  const body = payload as { ids?: unknown; status?: unknown } | null;
  if (!body || !Array.isArray(body.ids) || body.ids.length === 0
    || !body.ids.every((id) => Number.isSafeInteger(id) && id > 0)
    || (body.status !== "new" && body.status !== "checked")) {
    return NextResponse.json(
      { error: "Send submission IDs and a review state of new or checked." },
      { status: 400 },
    );
  }

  try {
    const response = await drupalFetch("/api/headless/patients/intake/review", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ ids: [...new Set(body.ids)], status: body.status }),
    });
    if (response.status === 302 || response.status === 307 || response.status === 401) {
      return NextResponse.json({ error: "Please sign in again." }, { status: 401 });
    }
    const result = await response.json().catch(() => null);
    if (!result) {
      return NextResponse.json({ error: "Unable to save intake review state." }, { status: 502 });
    }
    return NextResponse.json(result, { status: response.status });
  } catch {
    return NextResponse.json({ error: "Unable to reach the intake service." }, { status: 502 });
  }
}
