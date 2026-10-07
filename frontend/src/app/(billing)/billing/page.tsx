import type { Metadata } from "next";
import Link from "next/link";
import { redirect } from "next/navigation";
import { fetchSession } from "@/lib/drupal/session";
import { fetchSubscriptionCatalog, fetchSubscriptionManagement } from "@/lib/drupal/subscriptions";
import { PortalShell } from "@/components/portal/portal-shell-server";
import { BillingPage } from "@/components/subscriptions/billing-page";
import { resolvePortalAccess } from "@/lib/portal";

export const metadata: Metadata = { title: "Subscription & Billing — ChiroThin" };

export default async function DoctorBillingPage() {
  const session = await fetchSession();
  if (!session) redirect("/login");
  if (!session.capabilities) {
    const audience = resolvePortalAccess(session.roles)?.audience;
    if (audience === "patient") redirect("/dashboard");
    return <PortalShell audience="chiropractor" title="Subscription & Billing"><p>This account uses the legacy subscription system. Contact support to manage its subscription.</p><Link href="/chiropractor" className="text-primary underline">Back to dashboard</Link></PortalShell>;
  }
  const [management, catalog] = await Promise.all([
    fetchSubscriptionManagement().catch(() => null),
    fetchSubscriptionCatalog().catch(() => null),
  ]);
  return <PortalShell audience="chiropractor" title="Subscription & Billing"><BillingPage initial={management} plans={catalog?.plans ?? []} /></PortalShell>;
}
