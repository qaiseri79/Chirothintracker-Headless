import "server-only";
import { cookies } from "next/headers";
import https from "https";

/**
 * Server-side client for the Drupal backend.
 *
 * Runs only on the server. Every call forwards the visitor's Drupal session
 * cookie so authenticated reads (`/api/*`) resolve against the same session the
 * browser has, without Next.js ever minting or storing a second credential.
 *
 * The default points at Lando's published HTTP port for the appserver. That port
 * is Lando-generated and can change on restart, so `.env.local` sets
 * `DRUPAL_INTERNAL_URL` explicitly and is the value to trust; this fallback is
 * only the last resort if that file is missing. The `.lndo.site` name resolves
 * through the Lando proxy and deep paths 404 behind it, so set this to the real
 * reachable endpoint in other environments.
 */

// Lando uses self-signed certs; allow them in development
const httpsAgent = new https.Agent({
  rejectUnauthorized: process.env.NODE_ENV === "production",
});

export const DRUPAL_BASE_URL = (
  process.env.DRUPAL_INTERNAL_URL ?? "http://127.0.0.1:62249"
).replace(/\/+$/, "");

/**
 * Drupal names its session cookie `SESS<hash>` (and `SSESS<hash>` for SSL).
 * The hash changes with the site's private key, so match the prefix rather than
 * hard-coding a name.
 */
export const SESSION_COOKIE = /^(SESS|SSESS)[A-Za-z0-9]{1,}$/;

/**
 * Where the frontend parks Drupal's `logout_token` (returned by
 * `POST /user/login`). Core's JSON logout route (`user.logout.http`) carries
 * `_csrf_token: 'TRUE'`, and `CsrfAccessCheck` validates it as a `?token=` query
 * parameter whose token id is the route path — so the only way to obtain a
 * valid one is the login response. It is session-bound, and there is no
 * server-side store here, so the browser holds it in an HttpOnly cookie.
 */
export const DRUPAL_LOGOUT_COOKIE = "ctt_drupal_logout_token";

export async function sessionCookieHeader(): Promise<string> {
  const store = await cookies();
  const pairs: string[] = [];
  for (const cookie of store.getAll()) {
    if (SESSION_COOKIE.test(cookie.name)) pairs.push(`${cookie.name}=${cookie.value}`);
  }
  return pairs.join("; ");
}

export class DrupalError extends Error {
  constructor(
    message: string,
    readonly status: number,
    /** Parsed JSON body from the failing response, when one exists. */
    readonly body: unknown = null,
  ) {
    super(message);
    this.name = "DrupalError";
  }
}

interface DrupalFetchOptions extends Omit<RequestInit, "headers"> {
  /** Set false for the anonymous invite endpoints. Defaults to true. */
  forwardSession?: boolean;
  headers?: Record<string, string>;
}

export async function drupalFetch(
  path: string,
  { forwardSession = true, headers = {}, ...init }: DrupalFetchOptions = {},
): Promise<Response> {
  const outgoing = new Headers(headers);
  if (!outgoing.has("Accept")) outgoing.set("Accept", "application/json");
  if (forwardSession) {
    const cookie = await sessionCookieHeader();
    if (cookie) outgoing.set("Cookie", cookie);
  }

  return fetch(new URL(path, `${DRUPAL_BASE_URL}/`), {
    ...init,
    headers: outgoing,
    cache: "no-store",
    redirect: "manual",
    // @ts-expect-error - httpsAgent is a Node.js extension
    agent: DRUPAL_BASE_URL.startsWith("https") ? httpsAgent : undefined,
  });
}

/** Throws unless the response is 2xx, so callers only deal with happy JSON. */
export async function drupalJson<T>(
  path: string,
  options?: DrupalFetchOptions,
): Promise<T> {
  const response = await drupalFetch(path, options);
  if (!response.ok) {
    let body: unknown = null;
    try {
      body = (await response.json()) as unknown;
    } catch {
      // Non-JSON error body (e.g. an HTML error page) — keep null.
    }
    throw new DrupalError(
      `Drupal ${path} responded ${response.status}`,
      response.status,
      body,
    );
  }
  return (await response.json()) as T;
}
