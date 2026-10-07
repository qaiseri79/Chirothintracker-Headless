import type { Metadata } from "next";
import { redirect } from "next/navigation";
import { PortalShell } from "@/components/portal/portal-shell";
import { fetchSession } from "@/lib/drupal/session";
import { PORTAL_DASHBOARDS, resolvePortalAccess } from "@/lib/portal";

export const metadata: Metadata = {
  title: "Messages — ChiroThin",
};

export default async function ChiropractorMessagesLayout({
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

  const access = resolvePortalAccess(session.roles);
  if (access?.audience !== "chiropractor") redirect(PORTAL_DASHBOARDS.chiropractor);
  if (!access) return null;

  return (
    <PortalShell audience="chiropractor" title="Messages" bleed>
      {children}
    </PortalShell>
  );
}