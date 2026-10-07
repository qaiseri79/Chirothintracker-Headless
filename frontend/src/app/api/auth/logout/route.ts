import "server-only";
import { cookies } from "next/headers";
import { NextResponse } from "next/server";
import { DRUPAL_LOGOUT_COOKIE, drupalFetch, SESSION_COOKIE } from "@/lib/drupal/client";

/**
 * Ends the session through Drupal's own logout endpoint
 * (`POST /user/logout?_format=json`, route `user.logout.http`).
 *
 * That route requires `_csrf_token: 'TRUE'`, which core's `CsrfAccessCheck`
 * verifies as a `?token=` query parameter (an `X-CSRF-Token` header is rejected
 * — that belongs to the REST resource routes, not this one). The token is
 * session-bound and only issued in the login response, so it is kept in an
 * HttpOnly cookie by the login route and replayed here.
 */
export async function POST(): Promise<NextResponse> {
  const store = await cookies();
  const logoutToken = store.get(DRUPAL_LOGOUT_COOKIE)?.value;

  const path = logoutToken
    ? `/user/logout?_format=json&token=${encodeURIComponent(logoutToken)}`
    : "/user/logout?_format=json";
  await drupalFetch(path, { method: "POST" }).catch(() => null);

  // The browser cookies are cleared regardless, so the user is logged out on
  // the frontend even if Drupal was unreachable.
  const next = NextResponse.json({ ok: true });
  for (const cookie of store.getAll()) {
    if (SESSION_COOKIE.test(cookie.name) || cookie.name === DRUPAL_LOGOUT_COOKIE) {
      next.cookies.set(cookie.name, "", { path: "/", expires: new Date(0) });
    }
  }
  return next;
}