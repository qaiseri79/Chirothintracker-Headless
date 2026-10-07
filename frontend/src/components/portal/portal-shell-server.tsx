import type { ComponentProps } from "react";
import { fetchSession } from "@/lib/drupal/session";
import { PortalShell as ClientPortalShell } from "./portal-shell";

/** Share the initial server access decision with the client shell. */
export async function PortalShell(props: Omit<ComponentProps<typeof ClientPortalShell>, "initialAccess">) {
  const session = await fetchSession();
  return <ClientPortalShell {...props} initialAccess={session?.portalAccess} />;
}
