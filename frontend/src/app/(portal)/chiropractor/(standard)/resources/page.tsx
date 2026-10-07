import { redirect } from "next/navigation";
import { fetchSession } from "@/lib/drupal/session";
import { resolvePortalAccess } from "@/lib/portal";
import { PortalAccessDenied } from "@/components/portal-access-denied";
import {
  fetchResourceOptions,
  fetchResources,
  ResourcesUnauthenticatedError,
} from "@/lib/resources/data";
import { ResourcesPage } from "@/components/portal/resources/resources-page";
import type { ResourceCapabilities, ResourceNode, ResourceOptions } from "@/lib/resources/types";

export const dynamic = "force-dynamic";

export default async function ChiropractorResourcesPage() {
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

  // The library is fetched here and passed down, like the recipes page: it is scoped
  // to the signed-in user's clinic, so it cannot be cached across users, and the
  // browser has no Drupal session to fetch it with.
  //
  // A failed read is rendered as an error, not swallowed. "This clinic has no
  // resources" and "the backend is down" are different facts, and an empty list
  // cannot express the second. An unauthenticated session does go to login, because
  // the check above passed but the API disagrees, and re-rendering would not fix it.
  let nodes: ResourceNode[] = [];
  let error: string | null = null;
  let capabilities: ResourceCapabilities | null = null;
  try {
    const fetched = await fetchResources();
    nodes = fetched.nodes;
    capabilities = fetched.capabilities;
  } catch (err) {
    if (err instanceof ResourcesUnauthenticatedError) redirect("/login");
    error =
      err instanceof Error
        ? err.message
        : "The resource library could not be loaded.";
  }

  // The create form's category options, fetched only for the caller who can
  // create: they exist for the "Add resource" dialog, which no patient or
  // inactive chiropractor reaches. A failed read keeps the button but leaves the
  // dialog with a retry, so a broken options endpoint is not a broken page.
  let resourceOptions: ResourceOptions | null = null;
  if (capabilities?.canCreate === true) {
    try {
      resourceOptions = await fetchResourceOptions();
    } catch (err) {
      if (err instanceof ResourcesUnauthenticatedError) redirect("/login");
    }
  }

  return (
    <ResourcesPage
      nodes={nodes}
      error={error}
      canCreate={capabilities?.canCreate === true}
      resourceOptions={resourceOptions}
    />
  );
}
