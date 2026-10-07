import type { SubscribePlan } from "@/lib/design-preview/subscribe-plans";
import type { SubscriptionPlan } from "./types";
import {
  CLINIC_SUBSCRIPTION_DESCRIPTION,
  SUBSCRIPTION_AVAILABILITY_NOTE,
  SUBSCRIPTION_SUPPORT_EMAIL,
} from "./copy";

/** Adapt server-owned commercial terms to the existing design components. */
export function displayPlan(plan: SubscriptionPlan): SubscribePlan {
  const pts = plan.patientLimit === null
    ? "Unlimited enrolled patients"
    : `Enroll up to ${plan.patientLimit} patients`;
  const trial = plan.trialPolicy === "request_only"
    ? "Free trial available by request"
    : plan.trialPolicy === "addon_request"
      ? "Red Light/Laser tracking bonus on request"
      : "No free trial available";
  const notes = [
    CLINIC_SUBSCRIPTION_DESCRIPTION,
    ...(!plan.ecommerce ? ["E-commerce integration is not included in this plan."] : []),
    ...(plan.trialPolicy === "request_only"
      ? [`Email ${SUBSCRIPTION_SUPPORT_EMAIL} to request a trial. Checkout starts a paid subscription.`]
      : plan.trialPolicy === "addon_request"
        ? [`Email ${SUBSCRIPTION_SUPPORT_EMAIL} to request the Red Light/Laser tracking bonus. It is not enabled automatically at checkout.`]
        : []),
    SUBSCRIPTION_AVAILABILITY_NOTE,
  ];
  return {
    id: plan.id,
    name: plan.name,
    price: plan.amountMinor / 100,
    per: plan.per,
    currency: plan.currency,
    activationFeeMinor: plan.activationFeeMinor,
    sub: plan.per === "year" ? "Billed annually" : "Billed monthly",
    pts,
    ecom: plan.ecommerce,
    trial,
    note: notes.join(" "),
    features: [
      pts,
      "Patient progress tracking",
      "Patient messaging",
      "Unlimited data",
      "Multiple clinic locations",
      "Multiple doctors in one clinic",
      plan.activationFeeMinor === 0
        ? "No activation or build fee"
        : `Activation fee: ${planPrice(plan, plan.activationFeeMinor)}; no build fee`,
      plan.per === "month" ? "No long-term contract" : "Billed once a year",
      ...(plan.ecommerce ? ["E-commerce integration included"] : ["E-commerce not included"]),
      ...(plan.laser ? ["Red Light/Laser tracking included"] : []),
      trial,
    ],
  };
}

export function planPrice(plan: SubscriptionPlan, amountMinor = plan.amountMinor): string {
  return new Intl.NumberFormat("en-US", {
    style: "currency",
    currency: plan.currency,
    maximumFractionDigits: amountMinor % 100 ? 2 : 0,
  }).format(amountMinor / 100);
}
