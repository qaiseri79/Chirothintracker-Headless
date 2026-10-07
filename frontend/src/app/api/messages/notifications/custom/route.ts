import "server-only";
import { NextResponse } from "next/server";
import { drupalFetch } from "@/lib/drupal/client";

/**
 * POST /api/messages/notifications/custom — Save a doctor's notification.
 *
 * Stores a `review_messages` node scoped to the caller's clinic. `{name}` is
 * left intact so it personalises at send time.
 */
export async function POST(request: Request): Promise<NextResponse> {
  let payload: { title?: string; message?: string };
  try {
    payload = (await request.json()) as typeof payload;
  } catch {
    return NextResponse.json(
      { error: "bad_request", message: "Invalid JSON body." },
      { status: 400 },
    );
  }

  const title = typeof payload.title === "string" ? payload.title.trim() : "";
  const message = typeof payload.message === "string" ? payload.message.trim() : "";
  if (!title || !message) {
    return NextResponse.json(
      { error: "bad_request", message: "Add a name and a message first." },
      { status: 400 },
    );
  }

  let response: Response;
  try {
    response = await drupalFetch("/api/headless/messages/notifications/custom", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ title, message }),
    });
  } catch (error) {
    console.error("[api/messages/notifications/custom] Save failed", error);
    return NextResponse.json({ error: "unavailable" }, { status: 502 });
  }

  const data = await response.json().catch(() => ({}));
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