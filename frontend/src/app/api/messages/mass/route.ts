import "server-only";
import { NextResponse } from "next/server";
import { drupalFetch } from "@/lib/drupal/client";

/**
 * POST /api/messages/mass — Bulk-send one message to many patients.
 *
 * The browser sends multipart/form-data (uids as a JSON array, text, and
 * repeated `files[]` entries) because it is the only way to stream file uploads
 * from the client. The body is forwarded to Drupal untouched: re-encoding it
 * here would mean buffering every upload in memory for no benefit, since
 * `drupalFetch` can send a FormData body straight through.
 *
 * The `files[]` bracket is load-bearing. PHP collapses repeated keys that share
 * a name, so a bare `files` key would arrive at Drupal holding only the last
 * upload.
 */
export async function POST(request: Request): Promise<NextResponse> {
  let body: FormData;
  try {
    const form = await request.formData();
    const uids = form.get("uids");
    const text = (form.get("text") as string) ?? "";

    let parsed: unknown;
    try {
      parsed = typeof uids === "string" ? JSON.parse(uids) : uids;
    } catch {
      return NextResponse.json(
        { error: "bad_request", message: "uids must be a JSON array of user ids" },
        { status: 400 },
      );
    }

    if (!Array.isArray(parsed) || parsed.length === 0) {
      return NextResponse.json(
        { error: "bad_request", message: "At least one recipient is required" },
        { status: 400 },
      );
    }

    const forward = new FormData();
    forward.append("uids", JSON.stringify(parsed.map((uid) => Number(uid) || 0)));
    forward.append("text", text);
    for (const file of form.getAll("files[]")) {
      if (file instanceof File) {
        forward.append("files[]", file);
      }
    }
    body = forward;
  } catch {
    return NextResponse.json(
      { error: "bad_request", message: "Expected a multipart/form-data body" },
      { status: 400 },
    );
  }

  let response: Response;
  try {
    response = await drupalFetch("/api/headless/messages/mass", {
      method: "POST",
      body,
    });
  } catch (error) {
    console.error("[api/messages/mass] Drupal request failed", error);
    return NextResponse.json({ error: "unavailable" }, { status: 502 });
  }

  // A partial send comes back 201 with a `failed` list, so this passes the
  // status through and lets the caller report per-recipient outcomes.
  const data = await response.json().catch(() => ({}));
  return NextResponse.json(data, { status: response.status });
}
