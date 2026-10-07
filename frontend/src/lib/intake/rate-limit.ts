import { NextResponse } from "next/server";
import { cookies } from "next/headers";

/**
 * Request budget for the public intake endpoint.
 *
 * ## What this limits
 *
 * `POST /api/intake/{token}` only. The intake form is anonymous — there is no
 * session to check — and the clinic token is permanent and reusable, so it is
 * also not a rate limit: one clinic's link can legitimately be opened by every
 * patient the practice ever sees. Nothing upstream throttles this route.
 *
 * ## Why the counter is in memory
 *
 * There is no Redis or KV in this stack (no dependency, no env var, no service),
 * and the app runs as exactly one `next-server` process, so a module-level map is
 * the whole store and it is shared by every request the process handles. Two
 * consequences, both real and neither hidden here:
 *
 * - Counters reset when the process restarts, and are lost if it is replaced by
 *   more than one instance. Running two app servers would multiply the effective
 *   limit by the number of instances, since they would not share the map.
 * - This is an abuse brake, not a security control. It raises the cost of casual
 *   or automated hammering; it does not stop a determined attacker, who clears
 *   cookies between requests. Anything that must actually hold needs a shared
 *   store and an identity the client cannot mint.
 *
 * ## Why a cookie
 *
 * Nothing sits in front of the app to set `x-forwarded-for`, so there is no
 * trustworthy client address to key on. Every anonymous intake arrives looking
 * like it came from the same host, so an address-keyed limit would put every
 * patient and every attacker in one shared bucket and lock out real patients
 * after three submissions clinic-wide.
 *
 * The identity is therefore an opaque id this server mints itself and hands back
 * as an `HttpOnly` cookie: the closest thing to "per anonymous user" that exists
 * without infrastructure. It is random, not derived from anything about the
 * request, so nothing about the caller can be guessed from it.
 */
export const INTAKE_MAX_SUBMISSIONS = 3;

/**
 * How long a budget lasts. Long enough that a patient filling a six-step form,
 * or fixing a server-rejected answer, is never cut off mid-attempt; short enough
 * that a shared waiting room or a clinic's front desk cannot exhaust a real
 * patient's budget over a morning.
 */
export const INTAKE_WINDOW_MS = 60 * 60 * 1000;

/**
 * Hard cap on tracked identities. The map is keyed by untrusted input, so it
 * needs a ceiling or a flood of distinct cookies grows it without bound. When
 * full, the oldest entry is dropped — which can only ever let someone through
 * early, never lock anyone out.
 */
const MAX_TRACKED = 10_000;

interface Bucket {
  count: number;
  resetAt: number;
}

const globalStore = globalThis as unknown as {
  __intakeBudget?: Map<string, Bucket>;
};

function store(): Map<string, Bucket> {
  globalStore.__intakeBudget ??= new Map();
  return globalStore.__intakeBudget;
}

/** Cookie carrying the minted identity. Read server-side only. */
export const VISITOR_COOKIE = "chirothin_visitor";

/**
 * Best guess at the caller's address.
 *
 * There is no proxy in front of the app to set `x-forwarded-for`, so in practice
 * this is `"unknown"` for every anonymous intake, and all such callers deliberately
 * share one bucket. That is only ever consulted when a cookie is minted, to carry
 * over an attempt already spent in this window; it is never the primary identity,
 * precisely because it cannot tell patients apart.
 */
function callerFingerprint(request: Request): string {
  const address =
    request.headers.get("x-forwarded-for")?.split(",")[0]?.trim() ??
    request.headers.get("x-real-ip") ??
    "unknown";
  return `f:${address}|${request.headers.get("user-agent") ?? ""}`;
}

export interface BudgetResult {
  allowed: boolean;
  remaining: number;
  retryAfterSeconds: number;
}

/**
 * Counts this attempt against the caller's budget and reports whether it may
 * proceed.
 *
 * An over-budget caller still has their attempt counted, so waiting does not
 * help them and the bucket cannot be kept alive by polling.
 */
export async function consumeIntakeBudget(request: Request): Promise<BudgetResult> {
  const now = Date.now();
  const budgets = store();
  const jar = await cookies();
  const existing = jar.get(VISITOR_COOKIE)?.value;

  let key: string;
  if (existing) {
    key = `c:${existing}`;
  } else {
    // Mint the identity now rather than deferring it to a later request. Deferring
    // meant the caller's first attempt was counted under the fingerprint key and
    // their second landed under a brand new cookie key, handing them a fresh budget
    // and making the limit trivially bypassable.
    const minted = crypto.randomUUID().replace(/-/g, "");
    key = `c:${minted}`;
    jar.set(VISITOR_COOKIE, minted, {
      httpOnly: true,
      sameSite: "lax",
      secure: process.env.NODE_ENV === "production",
      path: "/",
      maxAge: Math.ceil(INTAKE_WINDOW_MS / 1000),
    });

    // Carry the attempt already spent this window over from the fingerprint
    // bucket, so minting the cookie mid-window cannot reset the count.
    const prior = budgets.get(callerFingerprint(request));
    if (prior && prior.resetAt > now) {
      budgets.set(key, prior);
      budgets.delete(callerFingerprint(request));
    }
  }

  const bucket = budgets.get(key);

  if (!bucket || bucket.resetAt <= now) {
    if (budgets.size >= MAX_TRACKED) {
      // Drop the entry closest to expiry.
      let oldestKey: string | undefined;
      let oldestReset = Infinity;
      for (const [candidate, entry] of budgets) {
        if (entry.resetAt < oldestReset) {
          oldestReset = entry.resetAt;
          oldestKey = candidate;
        }
      }
      if (oldestKey !== undefined) budgets.delete(oldestKey);
    }
    budgets.set(key, { count: 1, resetAt: now + INTAKE_WINDOW_MS });
    return { allowed: true, remaining: INTAKE_MAX_SUBMISSIONS - 1, retryAfterSeconds: 0 };
  }

  bucket.count += 1;

  if (bucket.count > INTAKE_MAX_SUBMISSIONS) {
    return {
      allowed: false,
      remaining: 0,
      retryAfterSeconds: Math.max(1, Math.ceil((bucket.resetAt - now) / 1000)),
    };
  }

  return {
    allowed: true,
    remaining: INTAKE_MAX_SUBMISSIONS - bucket.count,
    retryAfterSeconds: 0,
  };
}

/**
 * The 429 the form is told about.
 *
 * `Retry-After` is in seconds and is required by RFC 9110, so the header and the
 * message cannot disagree.
 */
export function intakeRateLimited(retryAfterSeconds: number): NextResponse {
  const retryAfter = Math.max(1, Math.round(retryAfterSeconds));
  return NextResponse.json(
    {
      error: "rate_limited",
      message:
        "You have reached the limit of 3 intake submissions from this device in the past hour. Please contact your clinic if you need to submit again.",
    },
    {
      status: 429,
      headers: { "Retry-After": String(retryAfter) },
    },
  );
}