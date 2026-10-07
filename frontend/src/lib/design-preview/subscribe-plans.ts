/** Presentation model shared by homepage pricing and the subscription funnel.
 * Commercial values are adapted from Drupal by lib/subscriptions/plans.ts. */
export interface SubscribePlan {
  id: number;
  name: string;
  price: number;
  per: "month" | "year";
  currency: string;
  activationFeeMinor: number;
  sub: string;
  pts: string;
  ecom: boolean;
  trial: string;
  note: string;
  features: string[];
}
export const SUBSCRIBE_STEPS = ["Plan", "Account", "Checkout"] as const;
export function formatMoney(amount: number, currency = "USD"): string {
  return new Intl.NumberFormat("en-US", { style: "currency", currency, maximumFractionDigits: Number.isInteger(amount) ? 0 : 2 }).format(amount);
}
export function planFeatures(plan: SubscribePlan): string[] { return plan.features; }
