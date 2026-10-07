import type { NextResponse } from "next/server";
import { forwardIntakeLinkWrite } from "@/lib/patients/intake-link-server";

/**
 * POST /api/intake-link/regenerate — throws away the current intake link and issues a
 * new one.
 *
 * A separate path rather than an action on the create path, because losing the old
 * token is destructive: patients may already have the old URL printed or saved, and it
 * stops working immediately. Kept apart so a retried "generate" can never destroy a
 * working link.
 */
export async function POST(): Promise<NextResponse> {
  return forwardIntakeLinkWrite(
    "/api/intake-link/regenerate",
    "Unable to regenerate your intake link.",
  );
}