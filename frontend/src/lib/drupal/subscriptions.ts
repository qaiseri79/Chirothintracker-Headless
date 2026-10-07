import "server-only";
import { drupalJson } from "./client";
import type { SubscriptionCatalog, SubscriptionManagement } from "@/lib/subscriptions/types";

export function fetchSubscriptionCatalog(): Promise<SubscriptionCatalog> {
  return drupalJson<SubscriptionCatalog>("/api/headless/subscriptions/plans", { forwardSession: false });
}

export function fetchSubscriptionManagement(): Promise<SubscriptionManagement> {
  return drupalJson<SubscriptionManagement>("/api/headless/subscriptions/management");
}
