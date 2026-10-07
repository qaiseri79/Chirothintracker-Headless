import type { Metadata } from "next";
import { redirect } from "next/navigation";
import { fetchSession } from "@/lib/drupal/session";
import { PORTAL_DASHBOARDS, resolvePortalAccess } from "@/lib/portal";

export const metadata: Metadata = {
  title: "Chiropractor Portal — ChiroThin",
};

/**
 * The chiropractor dashboard's access gate, mirroring `dashboard/layout.tsx`.
 *
 * Chiropractors authenticate through the same `/login` form as patients; this
 * layout is what sends each of them to the dashboard they actually belong on.
 * Both chiropractor roles are admitted — `chiropractor_active_` and
 * `chiropractor_inactive_` — and the read-only variant is carried by
 * `access.readOnly` for the components to act on, not by refusing the page.
 *
 * This layout no longer renders PortalShell. Route groups (standard) and (workspace)
 * render their own PortalShell with appropriate configuration (bleed, title).
 */
export default async function ChiropractorLayout({
  children,
}: {
  children: React.ReactNode;
}) {
  let session = null;
  try {
    session = await fetchSession();
  } catch {
    redirect("/login");
  }

  if (!session) redirect("/login");
  if (session.capabilities?.billingOnly) redirect("/subscribe");

  const access = resolvePortalAccess(session.roles, session.capabilities, session.portalAccess);
  if (access?.audience === "patient") redirect(PORTAL_DASHBOARDS.patient);
  if (!access) return null;

  return <>{children}</>;
}