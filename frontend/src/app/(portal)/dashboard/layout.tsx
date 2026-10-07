import type { Metadata } from "next";
import { redirect } from "next/navigation";
import { PortalShell } from "@/components/portal/portal-shell-server";
import { PortalAccessDenied } from "@/components/portal-access-denied";
import { fetchSession } from "@/lib/drupal/session";
import { PORTAL_DASHBOARDS, resolvePortalAccess } from "@/lib/portal";

export const metadata: Metadata = {
  title: "Patient Portal — ChiroThin",
};

/**
 * The patient dashboard's access gate, enforced here rather than in the page.
 *
 * The page is a server component that only renders a snapshot, so anything it
 * decided would be a decision made after the data was already fetched. Doing the
 * check in a server layout means the dashboard is not rendered at all unless the
 * caller's own Drupal session belongs to a patient account.
 *
 * Both patient roles are admitted, `enrolled_patient` and `archived_patient`;
 * what an archived patient may *do* is decided by the dashboard components from
 * `access.readOnly`, not by refusing them the page. A chiropractor is signed in
 * legitimately but belongs on the other dashboard, so they are redirected rather
 * than shown a refusal. An account in neither audience has no portal at all and
 * gets the explanation panel.
 *
 * The shell is rendered here rather than by a `(portal)` group layout because the
 * two dashboards need different sidebars, and the audience is only known after
 * this guard has run. That is also why these routes moved out of `(site)`: that
 * layout renders the public `SiteHeader`, and the app shell in
 * "New Design/ChiroThin — My Progress.html" replaces it rather than sitting
 * under it.
 */
export default async function DashboardLayout({ children }: LayoutProps<"/dashboard">) {
  let session = null;
  try {
    session = await fetchSession();
  } catch {
    // Drupal is unreachable, so the session cannot be verified. `/login` is
    // where the visitor belongs, and the login attempt there reports the
    // backend failure instead of this redirect being mistaken for bad
    // credentials.
    redirect("/login");
  }

  if (!session) redirect("/login");
  if (session.capabilities?.billingOnly) redirect("/subscribe");

  const access = resolvePortalAccess(session.roles, session.capabilities, session.portalAccess);
  if (access?.audience === "chiropractor") redirect(PORTAL_DASHBOARDS.chiropractor);
  if (!access) return <PortalAccessDenied email={session.mail || session.name} />;

  return <PortalShell audience="patient">{children}</PortalShell>;
}
