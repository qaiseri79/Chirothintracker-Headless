import type { Metadata } from "next";
import { TrackingForm } from "@/components/tracking/tracking-form";
import { fetchSession } from "@/lib/drupal/session";
import { PORTAL_DASHBOARDS, resolvePortalAccess } from "@/lib/portal";
import { drupalFetch } from "@/lib/drupal/client";
import { redirect } from "next/navigation";
import type { FormState } from "@/lib/intake/state";

export const metadata: Metadata = {
  title: "Edit Progress Log — ChiroThin",
};

export const dynamic = "force-dynamic";

/**
 * Loads one tracking log to seed the edit form.
 *
 * A non-OK response covers both "no such log" and "not yours" (the API returns
 * NULL rather than a distinct 403), so both land on the not-found view below.
 */
async function getEntry(messageId: string): Promise<FormState | null> {
  const response = await drupalFetch(`/api/headless/progress/entry/${messageId}`);
  if (!response.ok) {
    return null;
  }
  const entry: unknown = await response.json();
  return entry && typeof entry === "object" ? (entry as FormState) : null;
}

export default async function EditProgressPage({
  params,
}: {
  params: Promise<{ messageId: string }>;
}) {
  const { messageId } = await params;

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

  const entry = await getEntry(messageId);

  if (!entry) {
    return (
      <div className="flex min-h-full items-center justify-center">
        <div className="text-center">
          <p className="text-lg font-semibold text-foreground">Entry not found</p>
          <p className="mt-2 text-sm text-muted-foreground">
            The entry you are trying to edit does not exist or you do not have permission to view it.
          </p>
          <a href="/dashboard" className="mt-4 inline-block text-primary hover:underline">
            Back to dashboard
          </a>
        </div>
      </div>
    );
  }

  return <TrackingForm editMessageId={messageId} initialValues={entry} />;
}
