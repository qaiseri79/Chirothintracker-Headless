import { redirect } from "next/navigation";
import { fetchSession } from "@/lib/drupal/session";
import { resolvePortalAccess } from "@/lib/portal";
import { PortalAccessDenied } from "@/components/portal-access-denied";
import {
  fetchTraining,
  TrainingUnauthenticatedError,
} from "@/lib/training/data";
import { TrainingPage } from "@/components/portal/training/training-page";
import type { TrainingCapabilities, TrainingNode } from "@/lib/training/types";

export const dynamic = "force-dynamic";

export default async function DashboardTrainingPage() {
  let session = null;
  try {
    session = await fetchSession();
  } catch {
    redirect("/login");
  }

  if (!session) redirect("/login");
  if (session.capabilities?.billingOnly) redirect("/subscribe");

  const access = resolvePortalAccess(session.roles, session.capabilities, session.portalAccess);
  if (!access || access.audience !== "patient") {
    return <PortalAccessDenied email={session.mail || session.name} />;
  }

  // Fetched here and passed down, like the recipes page: the library is scoped to
  // the signed-in user's clinic, so it cannot be cached across users, and the
  // browser has no Drupal session to fetch it with.
  //
  // A failed read is rendered as an error rather than swallowed into an empty
  // list. "This clinic has no training" and "Drupal is down" are different facts,
  // and a patient shown the first has no way to tell it from the second.
  let nodes: TrainingNode[] = [];
  let error: string | null = null;
  let capabilities: TrainingCapabilities | null = null;
  try {
    const fetched = await fetchTraining();
    nodes = fetched.nodes;
    capabilities = fetched.capabilities;
  } catch (err) {
    if (err instanceof TrainingUnauthenticatedError) redirect("/login");
    error =
      err instanceof Error
        ? err.message
        : "The training library could not be loaded.";
  }

  // A patient cannot create, so `canCreate` is always false here; the button
  // stays hidden and the dialog is never rendered. Kept uniform with the
  // chiropractor page so the component's contract is the same on both sides.
  return (
    <TrainingPage
      nodes={nodes}
      error={error}
      canCreate={capabilities?.canCreate === true}
    />
  );
}
