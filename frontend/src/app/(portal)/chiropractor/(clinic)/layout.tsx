import { PortalShell } from "@/components/portal/portal-shell-server";

/**
 * The clinic page renders inside the padded shell — not `(workspace)`'s full-bleed
 * one — because it scrolls with the page like a normal table screen.
 *
 * Its own group for the reason `(patients)` has one: the design puts "My Clinic Page"
 * in the sticky shell header and lets the content below start at "My Chiropractors" or
 * "Clinic Locations", so `title` here is what preserves that hierarchy. Inherited
 * into `(standard)`, which is shared with Resources and Training, the header would say
 * nothing and the page would have to invent a heading underneath it.
 *
 * The design's second header element — the "Subscription Unlimited | Enrolled" pill —
 * is deliberately not reproduced. Its numbers come from the clinic's real plan and
 * enrolment, not from this page's records, and rendering a hardcoded figure in the
 * account bar of every clinic page would state something untrue about the signed-in
 * account. `PatientsHeaderPill` shows the same shape once there is a subscription
 * snapshot to pass it.
 */
export default function ClinicChiropractorLayout({
  children,
}: {
  children: React.ReactNode;
}) {
  return (
    <PortalShell audience="chiropractor" title="My Clinic Page">
      {children}
    </PortalShell>
  );
}