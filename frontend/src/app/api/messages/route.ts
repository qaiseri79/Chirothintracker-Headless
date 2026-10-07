import "server-only";
import { NextResponse } from "next/server";
import { drupalFetch } from "@/lib/drupal/client";

/**
 * GET /api/messages — List conversations for the current user.
 *
 * `drupalFetch` forwards the session cookie by default, so the cookie is not
 * assembled here. Passing `headers: { Cookie: cookie }` explicitly was sending
 * an empty `Cookie` header when there was no session, because drupalFetch only
 * overwrites the header when it has a non-empty value to set.
 */
export async function GET(request: Request): Promise<NextResponse> {
  const summary = new URL(request.url).searchParams.get("summary") === "1";
  let response: Response;
  try {
    response = await drupalFetch(
      summary ? "/api/headless/messages?summary=1" : "/api/headless/messages",
    );
  } catch (error) {
    console.error("[api/messages] Drupal request failed", error);
    return NextResponse.json({ error: "unavailable" }, { status: 502 });
  }

  if (!response.ok) {
    const body = await response.json().catch(() => ({}));
    return NextResponse.json(
      { error: "drupal_error", message: body.message ?? `Drupal responded ${response.status}` },
      { status: response.status },
    );
  }

  const data = await response.json();
  return NextResponse.json(Array.isArray(data) ? data : []);
}

/**
 * POST /api/messages — Send a new message.
 *
 * Accepts both JSON (text only) and multipart/form-data (text + optional file).
 * Text is passed through as typed. Drupal's MessagesService::sanitizeText() is
 * the only thing that normalises it, because the value is stored rather than
 * only displayed, so sanitising it in a proxy would just be a second, weaker
 * copy of the same rule.
 */
export async function POST(request: Request): Promise<NextResponse> {
  const contentType = request.headers.get("content-type") ?? "";

  let target_uid: number | null = null;
  let text = "";
  let file: File | null = null;

  if (contentType.includes("multipart/form-data")) {
    const form = await request.formData();
    target_uid = form.get("target_uid") ? Number(form.get("target_uid")) : null;
    text = (form.get("text") as string)?.trim() ?? "";
    file = (form.get("files[attachment]") as File) ?? null;
  } else {
    let payload: { target_uid?: number; text?: string };
    try {
      payload = (await request.json()) as typeof payload;
    } catch {
      return NextResponse.json(
        { error: "bad_request", message: "Invalid JSON body." },
        { status: 400 },
      );
    }
    target_uid = typeof payload.target_uid === "number" ? payload.target_uid : null;
    text = typeof payload.text === "string" ? payload.text.trim() : "";
  }

  if (!target_uid || (!text && !file)) {
    return NextResponse.json(
      { error: "bad_request", message: "target_uid and (text or file) are required." },
      { status: 400 },
    );
  }

  // Forward to Drupal as multipart/form-data
  const form = new FormData();
  form.append("target_uid", String(target_uid));
  if (text) form.append("text", text);
  if (file) form.append("files[attachment]", file);

  let response: Response;
  try {
    response = await drupalFetch("/api/headless/messages", {
      method: "POST",
      body: form,
    });
  } catch (error) {
    console.error("[api/messages] Send failed", error);
    return NextResponse.json({ error: "unavailable" }, { status: 502 });
  }

  const data = await response.json().catch(() => ({}));

  console.debug("[api/messages] Drupal response:", { ok: response.ok, status: response.status, data });

  if (!response.ok) {
    return NextResponse.json(
      {
        error: data.error ?? "drupal_error",
        message: data.message_text ?? data.message ?? `Drupal responded ${response.status}`,
      },
      { status: response.status },
    );
  }

  return NextResponse.json(data, { status: response.status });
}
