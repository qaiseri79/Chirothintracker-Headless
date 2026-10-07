export interface SubscriptionPlan {
  id: number;
  variationId: number;
  name: string;
  amountMinor: number;
  price: number;
  currency: string;
  intervalMonths: number;
  per: "month" | "year";
  patientLimit: number | null;
  ecommerce: boolean;
  laser: boolean;
  trialPolicy: "none" | "request_only" | "addon_request";
  activationFeeMinor: number;
  sponsoredDoctors?: boolean;
  clinicModel?: "shared";
}

export type BillingConfiguration = {
  provider: "authorize_net";
  available: false;
} | {
  provider: "authorize_net";
  available: true;
  environment: "sandbox" | "live";
  apiLoginId: string;
  publicClientKey: string;
  acceptJsUrl: string;
};

export interface SubscriptionCapabilities {
  portalRead: boolean;
  portalWrite: boolean;
  store: boolean;
  laser: boolean;
  patientLimit: number | null;
  paidThrough: number | null;
  inGracePeriod: boolean;
  billingOnly: boolean;
}

export interface DoctorSubscription {
  id: string;
  status: string;
  plan: SubscriptionPlan;
  paidThrough: number | null;
  cancelAtPeriodEnd: boolean;
  autoRenew: boolean;
  needsReview: boolean;
  startedAt?: number;
  pendingPlan?: { plan: SubscriptionPlan; effectiveAt: number; state: string; kind?: "upgrade" | "downgrade" } | null;
}

export interface SubscriptionStatus {
  subscription: DoctorSubscription | null;
  capabilities: SubscriptionCapabilities;
  billing: BillingConfiguration;
}
export interface SubscriptionCatalog {
  plans: SubscriptionPlan[];
  billing: BillingConfiguration;
}
export interface SubscriptionCart {
  id: string;
  plan: SubscriptionPlan;
  uid: number;
  expiresAt: number;
}
export interface OpaquePayment { dataDescriptor: string; dataValue: string }
export interface BillingAddress {
  firstName: string; lastName: string; company: string; address: string;
  city: string; state: string; zip: string; country: string;
}

export interface SubscriptionManagement extends SubscriptionStatus {
  paymentMethod: { brand: string; lastFour: string | null; expires: string | null } | null;
  paymentMethodUnavailable: boolean;
  payments: { id: string; amountMinor: number; currency: string; planName: string; status: string; paidAt: number; renewal: boolean; kind?: "initial" | "renewal" | "upgrade" }[];
  history: DoctorSubscription[];
  actions: { updatePayment: boolean; cancel: boolean; changePlan: boolean; resubscribe: boolean };
}


export interface PlanChangeQuote {
  id: string;
  kind: "upgrade" | "downgrade";
  currentPlan: SubscriptionPlan;
  plan: SubscriptionPlan;
  amountMinor: number;
  currency: string;
  expiresAt: number;
  periodStart: number;
  effectiveAt: number;
  renewalAt: number;
  enrolledCount: number;
  mustArchive: number;
  eligible: boolean;
}
export interface PlanChangePatient { id: number; name: string; email: string }
