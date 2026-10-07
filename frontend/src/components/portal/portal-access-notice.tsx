"use client";

import Link from "next/link";
import { Info, LockKeyhole } from "lucide-react";
import type { EffectivePortalAccess, PortalAudience } from "@/lib/portal";

function billingDate(value: number) {
  return new Intl.DateTimeFormat("en-US", { month: "short", day: "numeric", year: "numeric", timeZone: "America/Los_Angeles" }).format(new Date(value * 1000));
}

/** Enrollment describes the program; access describes permitted operations. */
export function PortalAccessNotice({ access, audience, loading }: {
  access?: EffectivePortalAccess | null;
  audience: PortalAudience;
  loading: boolean;
}) {
  if (loading) return <div role="status" className="shrink-0 border-b border-border bg-canvas px-6 py-2 text-sm text-muted-foreground">Checking account access…</div>;
  if (!access) return null;
  const restricted = !access.write;
  const cancellation = access.write && access.canManageBilling && access.cancelAtPeriodEnd && access.paidThrough;
  if (!restricted && !cancellation) return null;
  const archived = access.reason === "program_archived";
  const label = archived ? "Program archived" : restricted ? "Access inactive" : "Renewal cancelled";
  const message = cancellation
    ? "Renewal is cancelled. Your access remains active until " + billingDate(access.paidThrough!) + "."
    : audience === "patient"
      ? archived
        ? "Your weight-loss program is archived. Contact your doctor if you want to rejoin."
        : "Your program is still enrolled, but access to operations is paused. You can view your history. Please contact your clinic."
      : access.canManageBilling
        ? "Your subscription access is inactive. You can view your records, but cannot make changes. Manage your subscription to restore access."
         : access.funding === "sponsored"
          ? "Your access is funded by your primary subscriber and is currently inactive. You can view your records, but cannot make changes. Contact your primary subscriber to restore access."
          : "Your account has inactive access. You can view your records, but cannot make changes. Please contact your clinic.";
  const Icon = restricted ? LockKeyhole : Info;
  return <div role="status" aria-live="polite" className="shrink-0 border-b border-amber-200 bg-amber-50 px-4 py-3 text-amber-950 sm:px-6">
    <div className="flex flex-wrap items-start gap-x-3 gap-y-2">
      <Icon aria-hidden="true" className="mt-0.5 size-4 shrink-0" />
      <div className="min-w-0 flex-1 text-sm">
        <span className="mr-2 inline-flex rounded-full border border-amber-300 bg-white/70 px-2 py-0.5 text-xs font-semibold">{label}</span>
        <span>{message}</span>
      </div>
      {access.canManageBilling ? <Link href="/billing" className="shrink-0 text-sm font-semibold underline underline-offset-2">Subscription &amp; Billing</Link> : null}
    </div>
  </div>;
}
