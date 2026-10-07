"use client";

import { useRef, useState } from "react";
import { Button } from "@/components/ui/button";
import { subscriptionApi } from "@/lib/subscriptions/client";
import { billingDate, billingMoney, billingPrice } from "@/lib/subscriptions/format";
import type { PlanChangePatient, PlanChangeQuote, SubscriptionPlan } from "@/lib/subscriptions/types";

export function PlanChangePanel({ plans, currentPlanId, disabled, onConfirm }: {
  plans: SubscriptionPlan[];
  currentPlanId: number;
  disabled: boolean;
  onConfirm: (quote: PlanChangeQuote) => Promise<boolean>;
}) {
  const [planId, setPlanId] = useState(currentPlanId);
  const [quote, setQuote] = useState<PlanChangeQuote | null>(null);
  const [patients, setPatients] = useState<PlanChangePatient[]>([]);
  const [selected, setSelected] = useState<number[]>([]);
  const [confirmArchive, setConfirmArchive] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const guard = useRef(false);
  const waiting = disabled || busy;

  async function loadPatients(next: PlanChangeQuote) {
    setPatients(next.mustArchive > 0 ? (await subscriptionApi.planChangePatients()).patients : []);
  }
  async function review() {
    if (guard.current) return;
    guard.current = true; setBusy(true); setError(null); setQuote(null); setSelected([]); setConfirmArchive(false);
    try {
      const next = await subscriptionApi.quotePlanChange(planId);
      setQuote(next);
      await loadPatients(next);
    } catch (err) { setError(err instanceof Error ? err.message : "Unable to review this plan change."); }
    finally { guard.current = false; setBusy(false); }
  }
  async function archive() {
    if (guard.current || !quote || !selected.length) return;
    guard.current = true; setBusy(true); setError(null);
    try {
      const next = await subscriptionApi.archiveForPlan(quote.plan.id, selected);
      setQuote(next); setSelected([]); setConfirmArchive(false);
      await loadPatients(next);
    } catch (err) {
      setError(err instanceof Error ? err.message : "Unable to archive the selected patients.");
      // A failed multi-patient request may have committed some archives. Refresh actual state.
      await subscriptionApi.quotePlanChange(planId).then(async next => {
        setQuote(next); setSelected([]); setConfirmArchive(false); await loadPatients(next);
      }).catch(() => {});
    } finally { guard.current = false; setBusy(false); }
  }
  function toggle(id: number) {
    setConfirmArchive(false);
    setSelected(values => values.includes(id) ? values.filter(value => value !== id) : [...values, id]);
  }
  async function confirm() {
    if (guard.current || !quote || !quote.eligible) return;
    guard.current = true; setBusy(true);
    try {
      const completed = await onConfirm(quote);
      if (!completed && quote.kind === "downgrade") {
        await subscriptionApi.quotePlanChange(planId).then(async next => {
          setQuote(next); setSelected([]); setConfirmArchive(false); await loadPatients(next);
        }).catch(() => {});
      }
    }
    finally { guard.current = false; setBusy(false); }
  }

  return <div>
    <label htmlFor="billing-plan" className="mb-2 block text-sm font-medium">New subscription plan</label>
    <select id="billing-plan" value={planId} disabled={waiting} onChange={event => {
      setPlanId(Number(event.target.value)); setQuote(null); setPatients([]); setSelected([]); setConfirmArchive(false); setError(null);
    }} className="w-full rounded-lg border border-border bg-background px-3 py-3">
      {plans.map(plan => <option key={plan.id} value={plan.id}>{plan.name} — {billingPrice(plan)}</option>)}
    </select>
    <p className="mt-4 text-sm text-muted-foreground">Upgrades unlock access immediately after payment and renewal confirmation. Pay only the prorated difference for the remaining paid period, keeping your renewal date. Downgrades start at the next renewal.</p>
    {error ? <p role="alert" className="mt-4 text-sm text-destructive">{error}</p> : null}
    {quote ? <div className="mt-5 space-y-4 rounded-xl border border-border p-4">
      <h3 className="font-semibold">{quote.kind === "upgrade" ? "Review upgrade" : "Review downgrade"} to {quote.plan.name}</h3>
      <dl className="grid grid-cols-2 gap-3 text-sm">
        <div><dt className="text-muted-foreground">Due now</dt><dd className="mt-1 font-semibold">{billingMoney(quote.amountMinor, quote.currency)}</dd></div>
        <div><dt className="text-muted-foreground">Next renewal</dt><dd className="mt-1 font-semibold">{billingDate(quote.renewalAt)}</dd></div>
        <div><dt className="text-muted-foreground">New recurring price</dt><dd className="mt-1 font-semibold">{billingPrice(quote.plan)}</dd></div>
        <div><dt className="text-muted-foreground">Patient limit</dt><dd className="mt-1 font-semibold">{quote.plan.patientLimit ?? "Unlimited"}</dd></div>
      </dl>
      {quote.kind === "upgrade" ? <p className="text-sm text-muted-foreground">
        {quote.amountMinor > 0 ? "Confirming charges your saved payment method once for the amount shown." : "No additional payment is due for the remaining period."} Your additional access becomes available as soon as confirmation completes. {quote.plan.per !== quote.currentPlan.per ? "The new billing interval starts at the next renewal." : ""}
      </p> : <p className="text-sm text-muted-foreground">Your current paid features remain until renewal. Once scheduled, new enrollments must fit the lower plan allowance. No refund is issued for the remaining period.</p>}
      <p className="text-sm"><strong>{quote.enrolledCount}</strong> enrolled patients in your clinic, including patients enrolled by additional doctors.</p>
      {quote.mustArchive > 0 ? <div className="space-y-3 rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm text-amber-950">
        <p><strong>Archive at least {quote.mustArchive} {quote.mustArchive === 1 ? "patient" : "patients"} to become eligible.</strong> Choose from all enrolled patients below. The downgrade remains unavailable until enrollment fits.</p>
        <p>Archiving removes a patient from the program and restricts their access. It preserves their history. Only patients you explicitly confirm will be archived.</p>
        <div className="max-h-80 overflow-y-auto rounded-lg border border-border bg-surface text-foreground">
          {patients.map(patient => <label key={patient.id} className="flex cursor-pointer items-start gap-3 border-b border-border p-3 last:border-0">
            <input type="checkbox" checked={selected.includes(patient.id)} disabled={waiting} onChange={() => toggle(patient.id)} className="mt-1 size-4 shrink-0 accent-primary" />
            <span className="min-w-0"><span className="block font-medium">{patient.name}</span><span className="block break-all text-xs text-muted-foreground">{patient.email}</span></span>
          </label>)}
        </div>
        {patients.length === 0 ? <p>The patient list could not be loaded. Refresh the review to try again.</p> : null}
        {confirmArchive ? <div className="rounded-lg border border-destructive/30 bg-surface p-3 text-foreground">
          <p className="font-semibold">Archive these {selected.length} selected patients?</p>
          <ul className="my-3 max-h-32 list-inside list-disc overflow-y-auto">
            {patients.filter(patient => selected.includes(patient.id)).map(patient => <li key={patient.id}>{patient.name}</li>)}
          </ul>
          <div className="flex flex-wrap gap-3">
            <Button variant="destructive" disabled={waiting} onClick={() => void archive()}>{busy ? "Archiving…" : "Confirm archival"}</Button>
            <Button variant="outline" disabled={waiting} onClick={() => setConfirmArchive(false)}>Keep patients enrolled</Button>
          </div>
        </div> : <Button variant="outline" disabled={waiting || !selected.length || selected.length > 100} onClick={() => setConfirmArchive(true)}>Archive selected patients ({selected.length})</Button>}
        {selected.length > 100 ? <p>Archive up to 100 patients at a time.</p> : null}
      </div> : <p className="text-sm text-primary">{quote.kind === "downgrade" ? "Enrollment fits the lower plan. You can now confirm the downgrade." : "Your clinic is eligible for this upgrade."}</p>}
      <div className="flex flex-wrap gap-3">
        <Button disabled={waiting || !quote.eligible} onClick={() => void confirm()}>{waiting ? "Processing…" : quote.kind === "upgrade" ? "Confirm upgrade" : "Confirm downgrade"}</Button>
        <Button variant="outline" disabled={waiting} onClick={() => void review()}>Refresh review</Button>
        <Button variant="ghost" disabled={waiting} onClick={() => { setQuote(null); setPatients([]); setSelected([]); setConfirmArchive(false); }}>Keep current plan</Button>
      </div>
      <p className="text-xs text-muted-foreground">The quote expires in 10 minutes. Billing dates use Pacific time.</p>
    </div> : <Button className="mt-5" disabled={waiting || planId === currentPlanId} onClick={() => void review()}>{busy ? "Loading review…" : "Review plan change"}</Button>}
  </div>;
}