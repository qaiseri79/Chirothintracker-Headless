import "server-only";
import { NextResponse } from "next/server";
import { drupalFetch } from "@/lib/drupal/client";

const actions: Record<string, { method: string; keys?: string[] }> = {
  plans: { method: "GET" }, cart: { method: "GET" }, current: { method: "GET" },
  "cart/create": { method: "POST", keys: ["planId"] },
  register: { method: "POST", keys: ["email", "password", "fullName", "clinicName", "acceptTerms"] },
  checkout: { method: "POST", keys: ["cartId", "idempotencyKey", "opaqueData", "billing"] },
  management: { method: "GET" },
  refresh: { method: "POST", keys: [] },
  cancel: { method: "POST", keys: [] },
  resume: { method: "POST", keys: [] },
  "change-plan": { method: "POST", keys: ["planId", "quoteId"] },
  "change-plan/quote": { method: "POST", keys: ["planId"] },
  "change-plan/patients": { method: "GET" },
  "change-plan/archive": { method: "POST", keys: ["planId", "patientIds"] },
  "payment-method": { method: "POST", keys: ["opaqueData"] },
};
const headers = { "Cache-Control": "private, no-store" };
const fail = (message: string, status: number, error = "invalid_request") => NextResponse.json({ error, message }, { status, headers });

async function proxy(request: Request, context: { params: Promise<{ path: string[] }> }): Promise<NextResponse> {
  const action = (await context.params).path.join("/");
  const config = actions[action];
  if (!config) return fail("Unknown subscription operation.", 404);
  if (request.method !== config.method) return fail("Method not allowed.", 405);
  let body: string | undefined;
  if (config.method === "POST") {
    const origin = request.headers.get("origin");
    if ((origin && origin !== new URL(request.url).origin) || request.headers.get("sec-fetch-site") === "cross-site") {
      return fail("This request origin is not allowed.", 403);
    }
    if (!request.headers.get("content-type")?.toLowerCase().startsWith("application/json")) return fail("Provide a JSON request.", 415);
    const text = await request.text();
    if (text.length > 16384) return fail("The request is too large.", 413);
    try {
      const data: unknown = JSON.parse(text);
      if (!data || typeof data !== "object" || Array.isArray(data)) throw new Error();
      if (Object.keys(data).some(key => !config.keys?.includes(key))) throw new Error();
      const payload = data as Record<string, unknown>;
      if (action === "checkout" || action === "payment-method") {
        const opaque = payload.opaqueData as Record<string, unknown> | null;
        if (!opaque || typeof opaque !== "object" || Array.isArray(opaque) || Object.keys(opaque).some(key => !["dataDescriptor", "dataValue"].includes(key))) throw new Error();
        if (action === "checkout") {
          const billing = payload.billing as Record<string, unknown> | null;
          if (!billing || typeof billing !== "object" || Array.isArray(billing) || Object.keys(billing).some(key => !["firstName", "lastName", "company", "address", "city", "state", "zip", "country"].includes(key))) throw new Error();
        }
      }
      body = JSON.stringify(data);
    } catch { return fail("Provide a valid subscription request.", 400); }
  }
  const query = action === "cart" ? `?id=${encodeURIComponent(new URL(request.url).searchParams.get("id") ?? "")}` : "";
  try {
    const response = await drupalFetch(`/api/headless/subscriptions/${action}${query}`, {
      method: config.method, forwardSession: !["plans", "register"].includes(action),
      ...(body === undefined ? {} : { body, headers: { "Content-Type": "application/json" } }),
    });
    if (response.status >= 300 && response.status < 400) return fail("Please sign in again.", 401, "unauthenticated");
    const data: unknown = await response.json().catch(() => null);
    if (!data || typeof data !== "object") return fail("Subscriptions are temporarily unavailable. Please try again.", 502, "unavailable");
    return NextResponse.json(data, { status: response.status, headers });
  } catch { return fail("Subscriptions are temporarily unavailable. Please try again.", 502, "unavailable"); }
}
export const GET = proxy;
export const POST = proxy;
