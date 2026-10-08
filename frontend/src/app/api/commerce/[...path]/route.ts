import "server-only";
import { NextResponse } from "next/server";
import { drupalFetch } from "@/lib/drupal/client";

const ENDPOINTS: Record<string, { method: "GET" | "POST" | "PATCH" | "DELETE", keys?: string[] }> = {
  "payment-settings": { method: "POST", keys: ["apiLoginId", "transactionKey", "publicClientKey"] },
  "fulfillment-settings": { method: "POST", keys: ["type"] },
  catalog: { method: "GET" },
  "catalog/override": { method: "POST", keys: ["variationId", "priceMinor", "status"] },
  "custom-products": { method: "GET" },
  "custom-products/create": { method: "POST", keys: ["title", "sku", "priceMinor"] },
  "add-to-cart": { method: "POST", keys: ["variation_id", "quantity"] },
};

async function proxy(request: Request, path: string, payload?: unknown) {
  const def = ENDPOINTS[path];
  const url = `/api/headless/commerce/${path}`;
  if (!def || def.method !== request.method) return new NextResponse("Not found", { status: 404 });

  const options: { method: string, body?: string, headers?: Record<string, string> } = { method: def.method };
  if (payload) {
    const clean: Record<string, unknown> = {};
    for (const key of def.keys ?? []) clean[key] = (payload as any)[key];
    options.body = JSON.stringify(clean);
    options.headers = { "Content-Type": "application/json" };
  }

  const res = await drupalFetch(url, options);
  const data = await res.json().catch(() => null);
  return NextResponse.json(data ?? { error: "invalid_json" }, { status: res.status });
}

export async function GET(request: Request, { params }: { params: Promise<{ path: string[] }> }) {
  const path = (await params).path.join("/");
  return proxy(request, path);
}

export async function POST(request: Request, { params }: { params: Promise<{ path: string[] }> }) {
  const path = (await params).path.join("/");
  return proxy(request, path, await request.json().catch(() => ({})));
}

export async function DELETE(request: Request, { params }: { params: Promise<{ path: string[] }> }) {
  const path = (await params).path.join("/");

  if (path.startsWith("custom-products/")) {
    const res = await drupalFetch(`/api/headless/commerce/${path}`, { method: "DELETE" });
    const data = await res.json().catch(() => null);
    return NextResponse.json(data ?? {}, { status: res.status });
  }

  return new NextResponse("Not found", { status: 404 });
}
