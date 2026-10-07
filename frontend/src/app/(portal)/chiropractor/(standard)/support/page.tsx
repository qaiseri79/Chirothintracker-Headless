import { redirect } from "next/navigation";
import { fetchSession } from "@/lib/drupal/session";
import { resolvePortalAccess } from "@/lib/portal";
import { PortalAccessDenied } from "@/components/portal-access-denied";
import { SupportCenterPage } from "@/components/portal/support/support-center-page";

export const dynamic = "force-dynamic";

/**
 * Doctor-only Support Center.
 *
 * The guard is the chiropractor portal's standard one — session, billing-only
 * redirect, then audience — copied from `/chiropractor/training`, because the design
 * puts Support Center in the chiropractor sidebar only. It is a portal shell route, so
 * the `(standard)` layout supplies `PortalShell`; this file only guards.
 *
 * Nothing is fetched. The page's content is static (the GoHighLevel form URL and the
 * PDF are both configuration), so there is no Drupal read that could fail and no
 * error state to render — unlike the training or resources pages beside it, which
 * read from Drupal and therefore do pass data down.
 */
export default async function ChiropractorSupportPage() {
  let session = null;
  try {
    session = await fetchSession();
  } catch {
    redirect("/login");
  }

  if (!session) redirect("/login");
  if (session.capabilities?.billingOnly) redirect("/subscribe");

  const access = resolvePortalAccess(session.roles, session.capabilities, session.portalAccess);
  if (!access || access.audience !== "chiropractor") {
    return <PortalAccessDenied email={session.mail || session.name} />;
  }

  return <SupportCenterPage />;
}