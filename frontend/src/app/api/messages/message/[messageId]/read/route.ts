import "server-only";
import { NextResponse } from "next/server";
import { drupalFetch } from "@/lib/drupal/client";

/**
 * POST /api/messages/message/[messageId]/read — Mark a single message as read.
 */
export async function POST(
  _request: Request,
  { params }: { params: Promise<{ messageId: string }> },
): Promise<NextResponse> {
  const { messageId } = await params;
  const mid = parseInt(messageId, 10);
  if (isNaN(mid)) {
    return NextResponse.json({ error: "bad_request" }, { status: 400 });
  }

  let response: Response;
  try {
    response = await drupalFetch(`/api/headless/messages/message/${mid}/read`, { method: "POST" });
  } catch (error) {
    console.error("[api/messages/message/[messageId]/read] Drupal request failed", error);
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
  return NextResponse.json(data);
}