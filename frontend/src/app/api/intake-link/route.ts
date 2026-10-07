import type { NextResponse } from "next/server";
import { forwardIntakeLinkWrite } from "@/lib/patients/intake-link-server";

/**
 * POST /api/intake-link — generates the clinic's intake link if it has none.
 *
 * Forwards to Drupal's `headless_patients.intake_link_create`, which is idempotent:
 * it creates only when there is nothing and otherwise returns the existing URL. That
 * is what makes a double-click, a retried request, or two tabs open at once harmless.
 */
export async function POST(): Promise<NextResponse> {
  return forwardIntakeLinkWrite(
    "/api/intake-link",
    "Unable to generate your intake link.",
  );
}