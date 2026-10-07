import type { DoctorSubscription, SubscriptionCapabilities } from "@/lib/subscriptions/types";

/** Shared portal access rules for server layouts and client controls.
 * New subscription doctors use backend capabilities, including billing-only
 * access before their first payment. Existing patients and legacy doctors
 * continue to resolve through their Drupal roles. Drupal enforces data access.
 */

/** Roles that open each audience's dashboard, and the read-only variant. */
export const PORTAL_ROLES = {
  patient: { active: "enrolled_patient", readOnly: "archived_patient" },
  chiropractor: { active: "chiropractor_active_", readOnly: "chiropractor_inactive_" },
} as const;

export type PortalAudience = keyof typeof PORTAL_ROLES;

/** Per-audience dashboard paths. */
export const PORTAL_DASHBOARDS: Record<PortalAudience, string> = {
  patient: "/dashboard",
  chiropractor: "/chiropractor",
};

/** Server-owned effective access; payment status never changes enrollment. */
export interface EffectivePortalAccess {
  audience: PortalAudience;
  read: boolean;
  write: boolean;
  mode: "active" | "inactive" | "billing_only";
  reason: "program_archived" | "doctor_inactive" | "subscription_inactive" | "provider_subscription_inactive" | "provider_unavailable" | "sponsor_subscription_inactive" | "sponsor_unavailable" | null;
  paidThrough: number | null;
  cancelAtPeriodEnd: boolean;
  canManageBilling: boolean;
  canManageDoctors?: boolean;
  funding?: "self" | "sponsored" | null;
  enrollmentStatus: "enrolled" | "archived" | null;
}

export interface PortalAccess {
  audience: PortalAudience;
  /**
   * When true the account may view and download its own data but may not
   * perform operations. Dashboard components gate their mutating controls on
   * this rather than re-deriving the role.
   */
  readOnly: boolean;
  /** The dashboard this account belongs on. */
  dashboard: string;
}

/** The account behind the current Drupal session, as the portal needs it. */
export interface PortalSession {
  portalAccess?: EffectivePortalAccess | null;
  capabilities?: SubscriptionCapabilities;
  subscription?: DoctorSubscription | null;
  id: number | null;
  name: string;
  mail: string;
  roles: string[];
}

function hasRole(roles: readonly string[], role: string): boolean {
  return roles.includes(role);
}

/**
 * Resolves an account's roles to its audience and capabilities.
 *
 * See "Resolving conflicts" in the file header for why an account spanning both
 * audiences lands on the patient dashboard while an account holding both
 * capability levels inside one audience is treated as active.
 *
 * @returns `null` when the account belongs to neither audience, i.e. it has no
 *   portal. An empty `roles` array counts as no access, not as access: core's
 *   JSON login route only includes `roles` when the account can view its own
 *   roles field (`UserAuthenticationController::login()`), so an absent array
 *   means *unknown*, and the safe answer to unknown is no.
 */
export function resolvePortalAccess(
  roles: readonly string[] | null | undefined,
  capabilities?: SubscriptionCapabilities,
  effective?: EffectivePortalAccess | null,
): PortalAccess | null {
  if (effective !== undefined) return effective?.read
    ? { audience: effective.audience, readOnly: !effective.write, dashboard: PORTAL_DASHBOARDS[effective.audience] }
    : null;
  if (capabilities) return capabilities.portalRead ? { audience: "chiropractor", readOnly: !capabilities.portalWrite, dashboard: PORTAL_DASHBOARDS.chiropractor } : null;
  if (!Array.isArray(roles) || roles.length === 0) return null;

  const isPatient = hasRole(roles, PORTAL_ROLES.patient.active)
    || hasRole(roles, PORTAL_ROLES.patient.readOnly);
  const isChiropractor = hasRole(roles, PORTAL_ROLES.chiropractor.active)
    || hasRole(roles, PORTAL_ROLES.chiropractor.readOnly);

  if (!isPatient && !isChiropractor) return null;

  const audience: PortalAudience = isPatient ? "patient" : "chiropractor";
  return {
    audience,
    readOnly: !hasRole(roles, PORTAL_ROLES[audience].active),
    dashboard: PORTAL_DASHBOARDS[audience],
  };
}

/** New unpaid doctors finish subscription checkout before entering the portal. */
export function accountHome(account: { roles: string[]; capabilities?: SubscriptionCapabilities; portalAccess?: EffectivePortalAccess | null } | null): string {
  if (account?.capabilities?.billingOnly) return "/subscribe";
  return resolvePortalAccess(account?.roles, account?.capabilities, account?.portalAccess)?.dashboard ?? "/dashboard";
}
