import type { Metadata } from "next";
import { TrackingForm } from "@/components/tracking/tracking-form";
import { fetchSession } from "@/lib/drupal/session";
import { PORTAL_DASHBOARDS, resolvePortalAccess } from "@/lib/portal";
import { redirect } from "next/navigation";

export const metadata: Metadata = {
  title: "Log Your Progress — ChiroThin",
};

export const dynamic = "force-dynamic";

export default async function LogProgressPage() {
  let session = null;
  try {
    session = await fetchSession();
  } catch {
    redirect("/login");
  }

  if (!session) redirect("/login");
  if (session.capabilities?.billingOnly) redirect("/subscribe");

  const access = resolvePortalAccess(session.roles, session.capabilities, session.portalAccess);
  if (access?.audience === "chiropractor") redirect(PORTAL_DASHBOARDS.chiropractor);
  if (!access) redirect("/login");

  return <TrackingForm />;
}