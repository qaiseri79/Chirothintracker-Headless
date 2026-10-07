import type { Metadata } from "next";
import { redirect } from "next/navigation";
import { PortalShell } from "@/components/portal/portal-shell-server";
import { PortalAccessDenied } from "@/components/portal-access-denied";
import { fetchSession } from "@/lib/drupal/session";
import { PORTAL_DASHBOARDS, resolvePortalAccess } from "@/lib/portal";

export const metadata: Metadata = {
  title: "Messages — ChiroThin",
};

export default async function MessagesLayout({
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

  // `bleed` because the design runs the page edge to edge: a fixed thread
  // header, a scrolling thread, and a fixed reply bar. Those cannot nest
  // inside the shell's padded, width-capped, scrolling <main>. The title goes
  // in the shell header because the design draws "Messages" there, not in the
  // page.
  return (
    <PortalShell audience="patient" title="Messages" bleed>
      {children}
    </PortalShell>
  );
}