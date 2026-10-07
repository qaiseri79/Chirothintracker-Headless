import { PortalShell } from "@/components/portal/portal-shell-server";
import { PatientsHeaderPill } from "@/components/portal/patients/patients-page";
import { getPatientsCached } from "@/lib/patients/data";
import type { EnrollmentAllowance } from "@/lib/patients/types";

/**
 * The patients pages render inside the padded shell — not `(workspace)`'s
 * full-bleed one, because unlike the messages thread they scroll with the page
 * rather than pinning a header and a reply bar.
 *
 * Its own group for two reasons, not one. The design puts "Patients" in the
 * sticky shell header and lets the content below start at "Patient List", so
 * `title` here is what preserves that hierarchy; without it the page would have
 * to invent a second heading underneath a header that said nothing. And the
 * design's second header element — the "Subscription Unlimited | Enrolled" pill
 * — lives in the same bar, which only the shell can render, so the layout needs
 * the snapshot too.
 *
 * Both this and the page call `getPatientsCached`, which React memoises per
 * request, so the two consumers share one upstream call.
 */
export default async function PatientsChiropractorLayout({
  children,
}: {
  children: React.ReactNode;
}) {
  // The endpoint counts `enrolled_patient` in SQL. Counting the rows here looks
  // equivalent and is not: it would count whoever the roster lists, and the
  // roster deliberately also carries coaches and the doctor.
  let enrolled = 0;
  let allowance: EnrollmentAllowance | null = null;
  try {
    const snapshot = await getPatientsCached();
    enrolled = snapshot.enrolledCount;
    allowance = snapshot.enrollmentAllowance;
  } catch {
    // The page below renders its own unavailable state for this case. A missing
    // figure in the header must not take down the route on its own.
  }

  return (
    <PortalShell
      audience="chiropractor"
      title="Patients"
      headerAside={<PatientsHeaderPill enrolled={enrolled} allowance={allowance} />}
    >
      {children}
    </PortalShell>
  );
}