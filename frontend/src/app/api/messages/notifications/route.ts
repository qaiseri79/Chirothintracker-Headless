import "server-only";
import { NextResponse } from "next/server";
import { drupalFetch } from "@/lib/drupal/client";

/**
 * GET /api/messages/notifications — The doctor's notification bank.
 *
 * Drupal resolves each predefined template's body for the caller's own clinic
 * (the constipation message is brand-aware), so the body the dialog previews
 * and the one a send delivers are identical.
 */
export async function GET(): Promise<NextResponse> {
  let response: Response;
  try {
    response = await drupalFetch("/api/headless/messages/notifications");
  } catch (error) {
    console.error("[api/messages/notifications] Drupal request failed", error);
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
  return NextResponse.json({
    templates: Array.isArray(data.templates) ? data.templates : [],
    custom: Array.isArray(data.custom) ? data.custom : [],
  });
}

/**
 * POST /api/messages/notifications — Send a notification.
 *
 * The body is `{ operation, target_uid }`. Drupal runs the full legacy set of
 * side-effects (review-flag clear, submission tag, role transition) with the
 * send, so nothing happens in the browser except forwarding the operation.
 */
export async function POST(request: Request): Promise<NextResponse> {
  let payload: { operation?: string; target_uid?: number };
  try {
    payload = (await request.json()) as typeof payload;
  } catch {
    return NextResponse.json(
      { error: "bad_request", message: "Invalid JSON body." },
      { status: 400 },
    );
  }

  const operation = typeof payload.operation === "string" ? payload.operation : "";
  const target_uid = typeof payload.target_uid === "number" ? payload.target_uid : 0;
  if (!operation || !target_uid) {
    return NextResponse.json(
      { error: "bad_request", message: "operation and target_uid are required." },
      { status: 400 },
    );
  }

  let response: Response;
  try {
    response = await drupalFetch("/api/headless/messages/notifications", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ operation, target_uid }),
    });
  } catch (error) {
    console.error("[api/messages/notifications] Send failed", error);
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