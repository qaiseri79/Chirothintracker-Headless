"use client";

import { useRef, useState, type FormEvent, type ReactNode } from "react";
import Link from "next/link";
import { CreditCard, RefreshCw, ShieldCheck } from "lucide-react";
import { useAuth } from "@/lib/auth";
import { Button } from "@/components/ui/button";
import { PaymentFields, type PaymentFieldsHandle } from "./payment-fields";
import { PlanChangePanel } from "./plan-change-panel";
import { billingDate as date, billingPrice as price } from "@/lib/subscriptions/format";
import { subscriptionApi } from "@/lib/subscriptions/client";
import type { SubscriptionManagement, SubscriptionPlan } from "@/lib/subscriptions/types";

function statusLabel(status: string) {
  return ({ active: "Active", cancelled: "Cancelled", expired: "Expired", past_due: "Past due", declined: "Declined", payment_review: "Payment needs review", renewal_review: "Renewal needs review", paid_pending_schedule: "Setting up renewal", scheduling: "Setting up renewal", revoked: "Access revoked", charging: "Payment processing" } as Record<string, string>)[status] ?? status.replaceAll("_", " ");
}
function Panel({ title, children }: { title: string; children: ReactNode }) {
  return <section className="rounded-2xl border border-border bg-surface p-6 shadow-sm"><h2 className="mb-4 font-serif text-2xl">{title}</h2>{children}</section>;
}
export function BillingPage({ initial, plans }: { initial: SubscriptionManagement | null; plans: SubscriptionPlan[] }) {
  const { refresh: refreshAuth } = useAuth();
  const [data, setData] = useState(initial);
  const [busy, setBusy] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [editCard, setEditCard] = useState(false);
  const [confirmCancel, setConfirmCancel] = useState(false);
  const payment = useRef<PaymentFieldsHandle>(null);
  const guard = useRef(false);
  const sub = data?.subscription;

  async function run(operation: string, task: () => Promise<unknown>, message: string) {
    if (guard.current) return false;
    guard.current = true; setBusy(operation); setError(null); setNotice(null);
    try {
      const result = await task();
      const updated = result && typeof result === "object" && "actions" in result && "payments" in result ? result as SubscriptionManagement : await subscriptionApi.management();
      setData(updated);
      setNotice(message);
      setConfirmCancel(false);
      if (operation === "card") setEditCard(false);
      await refreshAuth();
      return true;
    } catch (err) {
      setError(err instanceof Error ? err.message : "Unable to complete this operation. Refresh the status before retrying.");
      await subscriptionApi.management().then(setData).catch(() => {});
      return false;
    } finally { guard.current = false; setBusy(null); }
  }
  async function saveCard(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    await run("card", async () => {
      if (!payment.current) throw new Error("The secure payment form is not ready.");
      await subscriptionApi.updatePayment(await payment.current.tokenize());
    }, "Payment method updated for future renewals.");
  }

  if (!data) return <Panel title="Subscription & Billing"><p>Your billing details could not be loaded.</p><Button className="mt-4" disabled={Boolean(busy)} onClick={() => void run("refresh", () => subscriptionApi.management(), "Billing details loaded.")}>Retry</Button>{error ? <p role="alert" className="mt-4 text-destructive">{error}</p> : null}</Panel>;

  return <div className="space-y-6">
    <div className="flex flex-wrap items-start justify-between gap-4">
      <div><h1 className="font-serif text-3xl">Subscription & Billing</h1><p className="mt-2 text-muted-foreground">Manage your plan, payment method and renewals.</p></div>
      <Button variant="outline" disabled={Boolean(busy)} onClick={() => void run("refresh", () => subscriptionApi.refresh(), "Subscription status refreshed.")}><RefreshCw className={busy === "refresh" ? "animate-spin" : ""} />{busy === "refresh" ? "Refreshing…" : "Refresh status"}</Button>
    </div>
    {error ? <div role="alert" className="rounded-xl border border-destructive/30 bg-destructive/5 p-4 text-sm text-destructive">{error}</div> : null}
    {notice ? <div role="status" className="rounded-xl border border-primary/20 bg-brand-soft p-4 text-sm">{notice}</div> : null}
    {sub?.needsReview ? <p className="rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-950">Your subscription has an operation awaiting confirmation. Refresh its status or contact support before requesting another change.</p> : null}
    <div className="grid gap-6 lg:grid-cols-2">
      <Panel title="Your subscription">
        {sub ? <>
          <div className="flex flex-wrap items-start justify-between gap-3"><div><p className="text-xl font-semibold">{sub.plan.name}</p><p className="mt-1 text-muted-foreground">{price(sub.plan)}</p></div><span className="rounded-full bg-brand-soft px-3 py-1 text-sm font-semibold">{statusLabel(sub.status)}</span></div>
          <dl className="mt-6 grid grid-cols-2 gap-4 text-sm">
            <div><dt className="text-muted-foreground">Started</dt><dd className="mt-1 font-medium">{date(sub.startedAt)}</dd></div>
            <div><dt className="text-muted-foreground">{sub.autoRenew ? "Next renewal" : "Paid through"}</dt><dd className="mt-1 font-medium">{date(sub.paidThrough)}</dd></div>
            <div><dt className="text-muted-foreground">Patient limit</dt><dd className="mt-1 font-medium">{sub.plan.patientLimit ?? "Unlimited"}</dd></div>
            <div><dt className="text-muted-foreground">Store access</dt><dd className="mt-1 font-medium">{sub.plan.ecommerce ? "Included" : "Not included"}</dd></div>
          </dl>
          <p className="mt-5 flex items-center gap-2 text-sm"><ShieldCheck className="size-4 shrink-0" />{data.capabilities.portalWrite ? "Your paid portal access is active." : data.capabilities.portalRead ? "Your portal is available in read-only mode." : "Subscribe to activate portal access."}</p>
          {sub.cancelAtPeriodEnd ? <p className="mt-4 text-sm text-muted-foreground">{sub.status === "cancelled" || sub.status === "expired" ? "Future renewals are cancelled. Paid access ends on " + date(sub.paidThrough) + "." : "Cancellation has been requested and is awaiting provider confirmation."}</p> : <p className="mt-4 text-sm text-muted-foreground">{sub.autoRenew ? "Automatic renewal is enabled." : "Automatic renewal is not confirmed."}</p>}
          {sub.pendingPlan ? <div className="mt-5 rounded-xl bg-brand-soft p-4 text-sm"><p className="font-semibold">{sub.pendingPlan.state === "scheduled" ? "Scheduled plan change" : "Plan change awaiting confirmation"}</p><p className="mt-1">{sub.pendingPlan.plan.name} — {price(sub.pendingPlan.plan)}</p><p className="mt-1">{sub.pendingPlan.kind === "upgrade" ? "The upgrade will activate once its payment and renewal update are verified. Refresh its status to check confirmation." : <>Next billing date: {date(sub.pendingPlan.effectiveAt)}. The lower plan activates after its renewal payment is confirmed. {sub.pendingPlan.plan.patientLimit !== null ? "New enrollments must already fit its allowance of " + sub.pendingPlan.plan.patientLimit + " patients." : ""}</>}</p></div> : null}
        </> : <p>You have not started a subscription yet.</p>}
        {data.actions.resume ? <Button className="mt-5 mr-3" disabled={Boolean(busy)} onClick={() => void run("resume", () => subscriptionApi.resume(), "Subscription resumed.")}>{busy === "resume" ? "Resuming…" : "Resume subscription"}</Button> : null}
        {data.actions.resubscribe ? <Button className="mt-5" asChild><Link href="/subscribe">{sub ? "Choose a new subscription" : "Choose a plan"}</Link></Button> : null}
      </Panel>
      <Panel title="Payment method">
        {data.paymentMethod ? <div className="flex items-center gap-3"><CreditCard className="size-8 text-primary" /><div><p className="font-semibold">{data.paymentMethod.brand} {data.paymentMethod.lastFour ? "ending in " + data.paymentMethod.lastFour : ""}</p>{data.paymentMethod.expires ? <p className="text-sm text-muted-foreground">Expires {data.paymentMethod.expires}</p> : null}</div></div> : <p className="text-muted-foreground">{data.paymentMethodUnavailable ? "Saved card details are temporarily unavailable. Refresh the status to try again." : "No saved payment method."}</p>}
        <p className="mt-4 text-sm text-muted-foreground">Your card is stored securely by Authorize.Net. Updating it does not start a new subscription or pay an overdue invoice.</p>
        {data.actions.updatePayment && data.billing.available ? editCard ? <form onSubmit={saveCard} className="mt-5"><fieldset disabled={Boolean(busy)}><PaymentFields billing={data.billing} ref={payment} /><div className="flex flex-wrap gap-3"><Button type="submit" disabled={Boolean(busy)}>{busy === "card" ? "Updating…" : "Save payment method"}</Button><Button type="button" variant="outline" disabled={Boolean(busy)} onClick={() => setEditCard(false)}>Keep current card</Button></div></fieldset></form> : <Button variant="outline" className="mt-5" disabled={Boolean(busy)} onClick={() => setEditCard(true)}>Update payment method</Button> : null}
      </Panel>
      <Panel title="Change plan">
        {data.actions.changePlan && sub && plans.length ? <PlanChangePanel
          key={sub.plan.id + ":" + sub.paidThrough}
          plans={plans} currentPlanId={sub.plan.id} disabled={Boolean(busy)}
          onConfirm={quote => run("plan", () => subscriptionApi.changePlan(quote.plan.id, quote.id),
            quote.kind === "upgrade" ? "Your upgrade is active. The additional allowance is available now." : "Your downgrade is scheduled for the next renewal.")}
        /> : <p className="text-sm text-muted-foreground">{sub?.pendingPlan
          ? sub.pendingPlan.kind === "upgrade" ? "Your upgrade payment or renewal update needs confirmation. Refresh its status before retrying." : "Your downgrade is scheduled or awaiting confirmation."
          : "Plan changes are available for active renewing subscriptions, at least 24 hours before the next renewal."}</p>}
      </Panel>
      <Panel title="Cancel subscription">
        <p className="text-sm text-muted-foreground">Cancellation stops future renewals. You keep access until the confirmed paid period ends. Cancellation does not issue a refund.</p>
        {data.actions.cancel ? confirmCancel ? <div className="mt-4 rounded-xl border border-destructive/30 p-4"><p className="text-sm font-semibold">Cancel all future renewals for this subscription?</p><div className="mt-4 flex flex-wrap gap-3"><Button variant="destructive" disabled={Boolean(busy)} onClick={() => void run("cancel", () => subscriptionApi.cancel(), "Future renewals cancelled. Your paid access remains until its expiry.")}>{busy === "cancel" ? "Cancelling…" : "Confirm cancellation"}</Button><Button variant="outline" disabled={Boolean(busy)} onClick={() => setConfirmCancel(false)}>Keep subscription</Button></div></div> : <Button variant="outline" className="mt-5" disabled={Boolean(busy)} onClick={() => setConfirmCancel(true)}>Cancel subscription</Button> : <p className="mt-4 text-sm">{sub?.cancelAtPeriodEnd ? "No further renewal is scheduled, or cancellation is awaiting confirmation." : "There is no confirmed renewing subscription to cancel."}</p>}
      </Panel>
    </div>
    <Panel title="Payment history"><div className="overflow-x-auto"><table className="w-full text-left text-sm"><thead><tr className="border-b border-border"><th className="py-3 pr-4">Date</th><th className="py-3 pr-4">Plan</th><th className="py-3 pr-4">Amount</th><th className="py-3 pr-4">Type</th><th className="py-3 pr-4">Status</th><th className="py-3">Transaction</th></tr></thead><tbody>{data.payments.map(item => <tr key={item.id} className="border-b border-border"><td className="py-3 pr-4 whitespace-nowrap">{date(item.paidAt)}</td><td className="py-3 pr-4">{item.planName}</td><td className="py-3 pr-4 whitespace-nowrap">{new Intl.NumberFormat("en-US", { style: "currency", currency: item.currency }).format(item.amountMinor / 100)}</td><td className="py-3 pr-4">{item.kind === "upgrade" ? "Prorated upgrade" : item.renewal ? "Renewal" : "Initial payment"}</td><td className="py-3 pr-4">{item.status === "settledSuccessfully" ? "Settled" : item.status === "capturedPendingSettlement" ? "Approved" : statusLabel(item.status)}</td><td className="py-3">{item.id}</td></tr>)}</tbody></table></div>{!data.payments.length ? <p className="py-4 text-sm text-muted-foreground">No verified payments recorded yet. Refresh the status to check earlier payments.</p> : null}<p className="mt-3 text-xs text-muted-foreground">Showing the latest 50 recorded payments. Billing dates use Pacific time.</p></Panel>
    {data.history.length ? <Panel title="Subscription history"><div className="overflow-x-auto"><table className="w-full text-left text-sm"><thead><tr className="border-b border-border"><th className="py-3 pr-4">Started</th><th className="py-3 pr-4">Plan</th><th className="py-3 pr-4">Price</th><th className="py-3 pr-4">Status</th><th className="py-3">Paid through</th></tr></thead><tbody>{data.history.map(item => <tr key={item.id} className="border-b border-border"><td className="py-3 pr-4 whitespace-nowrap">{date(item.startedAt)}</td><td className="py-3 pr-4">{item.plan.name}</td><td className="py-3 pr-4 whitespace-nowrap">{price(item.plan)}</td><td className="py-3 pr-4">{statusLabel(item.status)}</td><td className="py-3 whitespace-nowrap">{date(item.paidThrough)}</td></tr>)}</tbody></table></div></Panel> : null}
  </div>;
}
