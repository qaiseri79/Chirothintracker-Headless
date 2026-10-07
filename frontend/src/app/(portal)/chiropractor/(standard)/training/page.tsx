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

export default async function ChiropractorTrainingPage() {
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

  // Fetched here and passed down, like the recipes page: the library is scoped to
  // the signed-in user's clinic, so it cannot be cached across users, and the
  // browser has no Drupal session to fetch it with.
  //
  // A failed read is rendered as an error rather than swallowed into an empty
  // list. "This clinic has no training" and "Drupal is down" are different facts,
  // and only one of them is something the chiropractor can act on.
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

  return (
    <TrainingPage
      nodes={nodes}
      error={error}
      canCreate={capabilities?.canCreate === true}
    />
  );
}
