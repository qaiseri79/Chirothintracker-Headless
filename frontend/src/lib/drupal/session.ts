import "server-only";
import { cache } from "react";
import { drupalFetch } from "@/lib/drupal/client";
import type { PortalSession } from "@/lib/portal";

/**
 * The signed-in account, resolved server-side from the caller's own Drupal
 * session cookie.
 *
 * `GET /api/headless/session` (`headless_custom/headless_session`) is the only
 * source that reliably carries the account *and* its roles: core can report
 * logged-in vs logged-out (`GET /user/login_status`) but not the identity, and
 * core's JSON login response omits `roles` unless the account passes field
 * access on its own roles field. This endpoint returns all of it unconditionally
 * and never describes anyone but the caller.
 *
 * @returns The session, or `null` when there is no authenticated account.
 * @throws When Drupal cannot be reached at all — a transport failure, which is
 *   distinct from "nobody is logged in" and must not be reported as one.
 */
export const fetchSession = cache(async function fetchSession(): Promise<PortalSession | null> {
  const response = await drupalFetch("/api/headless/session", { forwardSession: true });

  if (response.status === 401) return null;
  if (!response.ok) {
    throw new Error(`Drupal /api/headless/session responded ${response.status}`);
  }

  const body = (await response.json().catch(() => null)) as Partial<PortalSession> | null;
  if (!body) return null;

  return {
    portalAccess: body.portalAccess,
    capabilities: body.capabilities,
    subscription: body.subscription,
    id: typeof body.id === "number" ? body.id : null,
    name: body.name ?? "",
    mail: body.mail ?? "",
    roles: Array.isArray(body.roles) ? body.roles : [],
  };
});
