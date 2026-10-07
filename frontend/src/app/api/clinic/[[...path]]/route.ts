import "server-only";
import { NextResponse } from "next/server";
import { drupalFetch } from "@/lib/drupal/client";

const headers = { "Cache-Control": "private, no-store" };
const fail = (message: string, status: number) => NextResponse.json({ message }, { status, headers });
async function proxy(request: Request, context: { params: Promise<{ path?: string[] }> }): Promise<NextResponse> {
  const path = (await context.params).path?.join("/") ?? "";
  const method = path === "" ? "GET"
    : /^(doctors|locations)$/.test(path) || /^doctors\/[1-9]\d*\/access$/.test(path) ? "POST"
    : /^(doctors|locations)\/[1-9]\d*$/.test(path) ? "PATCH" : null;
  if (!method) return fail("Unknown clinic operation.", 404);
  if (request.method !== method) return fail("Method not allowed.", 405);
  let body: string | undefined;
  if (method !== "GET") {
    const origin = request.headers.get("origin");
    if ((origin && origin !== new URL(request.url).origin) || request.headers.get("sec-fetch-site") === "cross-site") return fail("This request origin is not allowed.", 403);
    if (!request.headers.get("content-type")?.toLowerCase().startsWith("application/json")) return fail("Provide a JSON request.", 415);
    body = await request.text();
    if (body.length > 8192) return fail("The request is too large.", 413);
    try {
      const value: unknown = JSON.parse(body);
      if (!value || typeof value !== "object" || Array.isArray(value)) throw new Error();
    } catch { return fail("Provide a valid JSON object.", 400); }
  }
  try {
    const response = await drupalFetch("/api/headless/clinic" + (path ? "/" + path : ""), {
      method, ...(body === undefined ? {} : { body, headers: { "Content-Type": "application/json" } }),
    });
    if (response.status >= 300 && response.status < 400) return fail("Please sign in again.", 401);
    const data: unknown = await response.json().catch(() => null);
    if (!data || typeof data !== "object") return fail(response.status === 403 ? "Your account cannot perform this clinic operation." : "Clinic data is unavailable. Please try again.", response.status >= 400 ? response.status : 502);
    return NextResponse.json(data, { status: response.status, headers });
  } catch { return fail("Clinic data is unavailable. Please try again.", 502); }
}
export const GET = proxy;
export const POST = proxy;
export const PATCH = proxy;
