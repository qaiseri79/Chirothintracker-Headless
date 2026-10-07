import type { Metadata } from "next";
import { redirect } from "next/navigation";
import { PortalShell } from "@/components/portal/portal-shell-server";
import { PortalAccessDenied } from "@/components/portal-access-denied";
import { fetchSession } from "@/lib/drupal/session";
import { PORTAL_DASHBOARDS, resolvePortalAccess } from "@/lib/portal";

export const metadata: Metadata = {
  title: "Log Your Progress — ChiroThin",
};

export default async function LogProgressLayout({
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
  if (access?.audience === "chiropractor") redirect(PORTAL_DASHBOARDS.chiropractor);
  if (!access) return <PortalAccessDenied email={session.mail || session.name} />;

  return <PortalShell audience="patient">{children}</PortalShell>;
}