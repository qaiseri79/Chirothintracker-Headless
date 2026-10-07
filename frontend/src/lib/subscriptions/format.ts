import type { SubscriptionPlan } from "./types";

export function billingDate(timestamp?: number | null): string {
  return timestamp
    ? new Intl.DateTimeFormat("en-US", { dateStyle: "medium", timeZone: "America/Los_Angeles" }).format(new Date(timestamp * 1000))
    : "\u2014";
}
export function billingMoney(amountMinor: number, currency: string): string {
  return new Intl.NumberFormat("en-US", { style: "currency", currency }).format(amountMinor / 100);
}
export function billingPrice(plan: SubscriptionPlan): string {
  return billingMoney(plan.amountMinor, plan.currency) + " / " + plan.per;
}
