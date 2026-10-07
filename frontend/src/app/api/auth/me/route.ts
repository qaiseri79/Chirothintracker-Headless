import "server-only";
import { NextResponse } from "next/server";
import { drupalFetch } from "@/lib/drupal/client";

/**
 * Restores the signed-in user on page load. Drupal core can report only
 * logged-in vs logged-out (`GET /user/login_status`), so the account itself
 * comes from the headless_custom module's `GET /api/headless/session`.
 */
export async function GET(): Promise<NextResponse> {
  let response: Response;
  try {
    response = await drupalFetch("/api/headless/session", { forwardSession: true });
  } catch (error) {
    console.error("[auth/me] Drupal session lookup failed", error);
    return NextResponse.json({ error: "unavailable" }, { status: 502 });
  }
  const body = (await response.json().catch(() => null)) as unknown;
  return NextResponse.json(body, { status: response.status });
}