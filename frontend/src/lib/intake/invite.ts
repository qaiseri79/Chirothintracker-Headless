import "server-only";

import { DrupalError, drupalJson } from "@/lib/drupal/client";

/**
 * The invite-token contract with Drupal.
 *
 * Next.js owns the token: the opaque value is the only tenant selector in the
 * URL, and the clinic it maps to never reaches the browser. Drupal stores the
 * token (it is issued by clinic staff and the clinic lives there) and answers
 * two narrow questions:
 *
 *   GET  /api/intake/invite/{token} -> does this invite resolve, and what
 *                                       should be shown to the patient?
 *   POST /api/intake/submit          -> store a patient_intake contact message
 *                                       for the token's clinic.
 *
 * Both endpoints must be anonymous-accessible (RedirectSubscriber already
 * exempts /intake/ and /api/) and must resolve the clinic from the token
 * alone. Neither may accept a clinic id from the request.
 */

export type InviteStatus = "active" | "expired" | "exhausted" | "revoked";

export interface Invite {
  token: string;
  status: InviteStatus;
  /** Display name for the clinic and its brand. Not an identifier. */
  brand: string;
  /**
   * Legal blocks with every EPP token already substituted from the
   * token-derived clinic and site config. Sent so the patient consents to the
   * exact text that will be stored with the submission.
   */
  legal: Record<string, string>;
}

export type InviteRejection =
  | { reason: "unknown" }
  | { reason: "expired" }
  | { reason: "exhausted" }
  | { reason: "revoked" };

const TOKEN_SHAPE = /^[A-Za-z0-9_-]{16,128}$/;

export function isPlausibleToken(token: string): boolean {
  return TOKEN_SHAPE.test(token);
}

export async function resolveInvite(
  token: string,
): Promise<{ ok: true; invite: Invite } | { ok: false; rejected: InviteRejection }> {
  if (!isPlausibleToken(token)) return { ok: false, rejected: { reason: "unknown" } };

  try {
    const invite = await drupalJson<Invite>(
      `/api/intake/invite/${encodeURIComponent(token)}`,
      { forwardSession: false },
    );
    if (invite.status !== "active") {
      return { ok: false, rejected: { reason: invite.status } };
    }
    return { ok: true, invite };
  } catch (error) {
    if (error instanceof DrupalError && error.status === 404) {
      return { ok: false, rejected: { reason: "unknown" } };
    }
    throw error;
  }
}

export interface SubmitResult {
  id: number;
  received: string;
}

export async function submitToDrupal(body: {
  token: string;
  fields: Record<string, unknown>;
}): Promise<SubmitResult> {
  return drupalJson<SubmitResult>("/api/intake/submit", {
    method: "POST",
    forwardSession: false,
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify(body),
  });
}
