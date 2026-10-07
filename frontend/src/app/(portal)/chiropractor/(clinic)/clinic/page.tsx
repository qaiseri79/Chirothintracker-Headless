import { redirect } from "next/navigation";
import { fetchSession } from "@/lib/drupal/session";
import { resolvePortalAccess } from "@/lib/portal";
import { PortalAccessDenied } from "@/components/portal-access-denied";
import { fetchClinic } from "@/lib/clinic/server";
import { ClinicPage } from "@/components/portal/clinic/clinic-page";

export const dynamic = "force-dynamic";

export default async function ChiropractorClinicPage() {
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

  let initialData: Awaited<ReturnType<typeof fetchClinic>> | null = null;
  let initialError: string | undefined;
  try { initialData = await fetchClinic(); }
  catch { initialError = "Clinic data is unavailable. Please try again."; }
  return <ClinicPage initialData={initialData} initialError={initialError} />;
}
