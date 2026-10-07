import "server-only";
import { NextResponse } from "next/server";
import { drupalFetch } from "@/lib/drupal/client";

/**
 * POST /api/messages/notifications/custom/{nid} — Delete a saved notification.
 */
export async function POST(
  _request: Request,
  { params }: { params: Promise<{ nid: string }> },
): Promise<NextResponse> {
  const { nid } = await params;

  let response: Response;
  try {
    response = await drupalFetch(`/api/headless/messages/notifications/custom/${nid}`, {
      method: "POST",
    });
  } catch (error) {
    console.error(`[api/messages/notifications/custom/${nid}] Delete failed`, error);
    return NextResponse.json({ error: "unavailable" }, { status: 502 });
  }

  const data = await response.json().catch(() => ({}));
  if (!response.ok) {
    return NextResponse.json(
      { error: data.error ?? "drupal_error", message: `Drupal responded ${response.status}` },
      { status: response.status },
    );
  }

  return NextResponse.json(data, { status: response.status });
}