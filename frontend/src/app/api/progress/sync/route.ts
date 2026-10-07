import "server-only";
import { NextResponse } from "next/server";
import { drupalFetch, sessionCookieHeader } from "@/lib/drupal/client";

/** Session-scoped proxy: the client supplies no user ID or log IDs. */
export async function POST(request: Request): Promise<NextResponse> {
  const headers = { "Cache-Control": "no-store" };
  const origin = request.headers.get("origin");
  if ((origin && origin !== new URL(request.url).origin) || request.headers.get("sec-fetch-site") === "cross-site") {
    return NextResponse.json({ message: "Invalid request origin." }, { status: 403, headers });
  }
  let data: { token: string | null; processed: number };
  try {
    const text = await request.text();
    if (text.length > 1024) throw new Error("size");
    const parsed: unknown = JSON.parse(text);
    if (!parsed || typeof parsed !== "object" || Array.isArray(parsed)) throw new Error("object");
    const body = parsed as Record<string, unknown>;
    if ((body.token !== null && (typeof body.token !== "string" || !/^[a-f0-9]{64}$/.test(body.token)))
      || !Number.isSafeInteger(body.processed) || (body.processed as number) < 0) throw new Error("fields");
    data = { token: body.token as string | null, processed: body.processed as number };
  } catch {
    return NextResponse.json({ message: "Invalid sync request." }, { status: 400, headers });
  }
  const cookie = await sessionCookieHeader();
  if (!cookie) return NextResponse.json({ message: "Please sign in again." }, { status: 401, headers });
  try {
    const response = await drupalFetch("/api/headless/progress/sync", {
      method: "POST", headers: { "Content-Type": "application/json", Cookie: cookie }, body: JSON.stringify(data),
    });
    const body: unknown = await response.json().catch(() => null);
    if (!body) return NextResponse.json({ message: "Unable to sync your logs. Please try again." }, { status: response.ok ? 502 : response.status, headers });
    return NextResponse.json(body, { status: response.status, headers });
  } catch {
    return NextResponse.json({ message: "We could not reach the server. Please try again." }, { status: 502, headers });
  }
}
