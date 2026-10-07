import "server-only";
import { NextResponse } from "next/server";
import { drupalFetch } from "@/lib/drupal/client";

export async function passwordResetProxy(request: Request, action: "request" | "reset"): Promise<NextResponse> {
  const headers = { "Cache-Control": "no-store" };
  // Do not accept cross-origin browser submissions to this anonymous endpoint.
  const origin = request.headers.get("origin");
  if (origin && origin !== new URL(request.url).origin) {
    return NextResponse.json({ message: "Invalid request origin." }, { status: 403, headers });
  }
  let data: Record<string, unknown>;
  try {
    const text = await request.text();
    if (text.length > 4096) throw new Error("size");
    const parsed: unknown = JSON.parse(text);
    if (!parsed || typeof parsed !== "object" || Array.isArray(parsed)) throw new Error("object");
    data = parsed as Record<string, unknown>;
  } catch {
    return NextResponse.json({ message: "Invalid request." }, { status: 400, headers });
  }
  const payload = action === "request"
    ? { email: typeof data.email === "string" ? data.email.trim() : "" }
    : { uid: data.uid, timestamp: data.timestamp, hash: data.hash, password: data.password };
  try {
    const response = await drupalFetch(`/api/headless/auth/password/${action}`, {
      forwardSession: false,
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(payload),
    });
    const body = await response.json().catch(() => null) as { message?: unknown } | null;
    if (!body || typeof body.message !== "string" || response.status >= 500) {
      return NextResponse.json({ message: "Password recovery is unavailable. Please try again later or contact support." }, { status: 502, headers });
    }
    return NextResponse.json({ message: body.message }, { status: response.status, headers });
  } catch {
    return NextResponse.json({ message: "Password recovery is unavailable. Please try again later." }, { status: 502, headers });
  }
}
