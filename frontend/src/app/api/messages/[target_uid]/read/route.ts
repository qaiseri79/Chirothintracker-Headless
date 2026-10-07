import "server-only";
import { NextResponse } from "next/server";
import { drupalFetch } from "@/lib/drupal/client";

/**
 * POST /api/messages/[target_uid]/read — Mark conversation as read.
 */
export async function POST(
  _request: Request,
  { params }: { params: Promise<{ target_uid: string }> },
): Promise<NextResponse> {
  const { target_uid } = await params;
  const uid = parseInt(target_uid, 10);
  if (isNaN(uid)) {
    return NextResponse.json({ error: "bad_request" }, { status: 400 });
  }

  let response: Response;
  try {
    response = await drupalFetch(`/api/headless/messages/${uid}/read`, { method: "POST" });
  } catch (error) {
    console.error("[api/messages/[target_uid]/read] Drupal request failed", error);
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