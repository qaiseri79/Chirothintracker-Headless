import { NextResponse } from "next/server";
import { inputFields } from "@/lib/intake/blueprint";
import { DrupalError } from "@/lib/drupal/client";
import { isPlausibleToken, submitToDrupal } from "@/lib/intake/invite";
import type { FormState } from "@/lib/intake/state";
import { buildSubmission, validateAll } from "@/lib/intake/submit";
import { consumeIntakeBudget, intakeRateLimited } from "@/lib/intake/rate-limit";

/**
 * Submits the intake for an invite token.
 *
 * Security model: the clinic is resolved from the token by Drupal. This handler
 * never reads or forwards a clinic id from the request body, and
 * `buildSubmission` is an allowlist built from the blueprint — anything that is
 * not a declared field, not a declared option value, or behind a false
 * `visibleWhen` condition simply does not exist in the payload.
 */
export async function POST(
  request: Request,
  { params }: { params: Promise<{ token: string }> },
) {
  const { token } = await params;

  if (!isPlausibleToken(token)) {
    return NextResponse.json(
      { error: "invalid_token" },
      { status: 404 },
    );
  }

  // After the token check, before anything is parsed or forwarded.
  //
  // Deliberately not ahead of the token check: a 429 would otherwise confirm
  // that a token is well-formed, which is the one thing the 404 is hiding.
  //
  // Also deliberately before field validation. A rejected submission is still a
  // submission attempt, and counting only the ones that pass would let a caller
  // probe the validator, or hammer Drupal with payloads that are wrong in some
  // other field, for free.
  const budget = await consumeIntakeBudget(request);
  if (!budget.allowed) {
    return intakeRateLimited(budget.retryAfterSeconds);
  }

  let body: { fields?: unknown };
  try {
    body = await request.json();
  } catch {
    return NextResponse.json({ error: "bad_request" }, { status: 400 });
  }

  if (!body || typeof body.fields !== "object" || body.fields === null) {
    return NextResponse.json({ error: "bad_request" }, { status: 400 });
  }
  const fields = body.fields as Record<string, unknown>;

  // The client form state may contain keys not in the blueprint; reduce it to
  // declared inputs before validating, so the allowlist owns the shape.
  const declared: FormState = {};
  for (const field of inputFields) {
    declared[field.name] = fields[field.name] as never;
  }

  const issues = validateAll(inputFields, declared);
  if (issues.length) {
    return NextResponse.json({ issues }, { status: 422 });
  }

  const submission = buildSubmission(inputFields, declared, token);

  try {
    const result = await submitToDrupal(submission);
    return NextResponse.json(result, { status: 201 });
  } catch (error) {
    if (error instanceof DrupalError) {
      // Drupal marks expired/exhausted/revoked tokens the same way Next.js
      // does, so the patient sees one consistent message.
      if (error.status === 404 || error.status === 409 || error.status === 410) {
        return NextResponse.json(
          { error: "token_unavailable" },
          { status: 410 },
        );
      }
      if (error.status === 422) {
        // Drupal rejected the message (missing/ill-formed field values). The
        // patient should see the specific issues, not a generic error, so pass
        // the field-level validation errors through unchanged.
        const body = error.body as { issues?: unknown } | null;
        const issues = body?.issues;
        console.error(
          "Drupal validation rejected intake:",
          JSON.stringify(body),
        );
        if (Array.isArray(issues) && issues.length > 0) {
          return NextResponse.json({ issues }, { status: 422 });
        }
        return NextResponse.json(
          { error: "validation_error" },
          { status: 422 },
        );
      }
      console.error(
        "Drupal rejected intake submission:",
        error,
        JSON.stringify(error.body),
      );
      return NextResponse.json(
        { error: "upstream_error" },
        { status: 502 },
      );
    }
    console.error("Intake submission failed:", error);
    return NextResponse.json({ error: "server_error" }, { status: 500 });
  }
}