import type { PlanChangeQuote, PlanChangePatient, SubscriptionCart, SubscriptionStatus, SubscriptionManagement, BillingAddress, OpaquePayment } from "./types";

export class SubscriptionApiError extends Error {
  constructor(message: string, readonly code: string, readonly status: number, readonly fields: Record<string, string> = {}) {
    super(message);
  }
}

async function request<T>(path: string, body?: unknown): Promise<T> {
  let response: Response;
  try {
    response = await fetch(`/api/subscriptions/${path}`, {
      method: body === undefined ? "GET" : "POST",
      ...(body === undefined ? {} : { headers: { "Content-Type": "application/json" }, body: JSON.stringify(body) }),
      cache: "no-store",
    });
  } catch {
    throw new SubscriptionApiError("Unable to connect. Please try again.", "network_error", 0);
  }
  const data = await response.json().catch(() => null);
  if (!response.ok || !data) {
    throw new SubscriptionApiError(data?.message ?? "Subscriptions are temporarily unavailable. Please try again.", data?.error ?? "unavailable", response.ok ? 502 : response.status, data?.fields ?? {});
  }
  return data as T;
}

export const subscriptionApi = {
  createCart: (planId: number) => request<SubscriptionCart>("cart/create", { planId }),
  cart: (id: string) => request<SubscriptionCart>(`cart?id=${encodeURIComponent(id)}`),
  register: (body: { email: string; password: string; fullName: string; clinicName: string; acceptTerms: boolean }) => request<{ id: number; clinicId: number }>("register", body),
  current: () => request<SubscriptionStatus>("current"),
  management: () => request<SubscriptionManagement>("management"),
  refresh: () => request<SubscriptionManagement>("refresh", {}),
  cancel: () => request<SubscriptionStatus>("cancel", {}),
  quotePlanChange: (planId: number) => request<PlanChangeQuote>("change-plan/quote", { planId }),
  planChangePatients: () => request<{ patients: PlanChangePatient[] }>("change-plan/patients"),
  archiveForPlan: (planId: number, patientIds: number[]) => request<PlanChangeQuote>("change-plan/archive", { planId, patientIds }),
  changePlan: (planId: number, quoteId: string) => request<SubscriptionStatus>("change-plan", { planId, quoteId }),
  updatePayment: (opaqueData: OpaquePayment) => request<{ updated: boolean }>("payment-method", { opaqueData }),
  checkout: (body: { cartId: string; idempotencyKey: string; billing: BillingAddress; opaqueData: OpaquePayment }) => request<SubscriptionStatus>("checkout", body),
};
