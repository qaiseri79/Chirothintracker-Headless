import type { CatalogItem, CustomProduct, PaymentSettingsPayload } from "./types";

export class CommerceApiError extends Error {
  constructor(message: string, readonly code: string, readonly status: number, readonly fields: Record<string, string> = {}) {
    super(message);
  }
}

async function request<T>(path: string, body?: unknown, options: { method?: string } = {}): Promise<T> {
  let response: Response;
  const method = options.method ?? (body === undefined ? "GET" : "POST");
  try {
    response = await fetch(`/api/commerce/${path}`, {
      method,
      ...(body === undefined ? {} : { headers: { "Content-Type": "application/json" }, body: JSON.stringify(body) }),
      cache: "no-store",
    });
  } catch {
    throw new CommerceApiError("Unable to connect. Please try again.", "network_error", 0);
  }
  const data = await response.json().catch(() => null);
  if (!response.ok || !data) {
    throw new CommerceApiError(data?.message ?? "Commerce API temporarily unavailable. Please try again.", data?.error ?? "unavailable", response.ok ? 502 : response.status, data?.fields ?? {});
  }
  return data as T;
}

export const commerceApi = {
  savePaymentSettings: (payload: PaymentSettingsPayload) => request<{ success: boolean }>("payment-settings", payload),
  saveFulfillmentSettings: (type: "shipping" | "pickup" | "both") => request<{ success: boolean; type: string }>("fulfillment-settings", { type }),
  getCatalog: () => request<{ catalog: CatalogItem[] }>("catalog"),
  saveCatalogOverride: (variationId: number, priceMinor: number | null, status: boolean) => request<{ success: boolean }>("catalog/override", { variationId, priceMinor, status }),
  getCustomProducts: () => request<{ products: CustomProduct[] }>("custom-products"),
  createCustomProduct: (title: string, sku: string, priceMinor: number) => request<{ success: boolean; id: number }>("custom-products/create", { title, sku, priceMinor }),
  deleteCustomProduct: (id: number) => request<{ success: boolean }>("custom-products/" + id, undefined, { method: "DELETE" }),
  addToCart: (variation_id: number, quantity: number) => request<{ message: string, cart_id: number, item_id: number }>("add-to-cart", { variation_id, quantity }),
};
