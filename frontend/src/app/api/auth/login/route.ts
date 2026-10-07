import "server-only";
import { NextResponse } from "next/server";
import { DRUPAL_LOGOUT_COOKIE, drupalFetch } from "@/lib/drupal/client";

interface AuthBody {
  email?: string;
  password?: string;
}

interface CoreLoginResponse {
  current_user?: { uid?: string | number; name?: string; roles?: string[] };
  logout_token?: string;
}

/**
 * Authenticates against Drupal's own session API: `POST /user/login?_format=json`
 * (route `user.login.http`, controller `UserAuthenticationController::login`),
 * which handles the flood control, `user_login_finalize()` and session
 * regeneration. Patient accounts are created with their email address as the
 * account name, so the email is sent as Drupal's `name`.
 */
export async function POST(request: Request): Promise<NextResponse> {
  let payload: AuthBody;
  try {
    payload = (await request.json()) as AuthBody;
  } catch {
    payload = {};
  }
  const email = typeof payload.email === "string" ? payload.email.trim() : "";
  const password = typeof payload.password === "string" ? payload.password : "";

  if (!email || !password) {
    return NextResponse.json(
      { error: "bad_request", message: "Email and password are required." },
      { status: 400 },
    );
  }

  let response: Response;
  try {
    response = await drupalFetch("/user/login?_format=json", {
      forwardSession: false,
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ name: email, pass: password }),
    });
  } catch (error) {
    console.error("[auth/login] Drupal login request failed", error);
    return NextResponse.json({ error: "unavailable" }, { status: 502 });
  }

  const body = (await response.json().catch(() => null)) as
    | (CoreLoginResponse & { message?: string })
    | null;

  if (!response.ok) {
    const message =
      response.status === 429
        ? "Too many failed login attempts. Try again later."
        : (body?.message ?? "Unable to log in. Please try again.");
    return NextResponse.json({ error: "login_failed", message }, { status: response.status });
  }

  const next = NextResponse.json({
    id: body?.current_user?.uid ?? null,
    name: body?.current_user?.name ?? email,
    roles: body?.current_user?.roles ?? [],
  });

  // Keep core's logout_token: the JSON logout route will not accept anything
  // else, and it is only ever issued here.
  if (body?.logout_token) {
    next.cookies.set(DRUPAL_LOGOUT_COOKIE, body.logout_token, {
      path: "/",
      httpOnly: true,
      sameSite: "lax",
    });
  }

  // Drupal wrote the session cookie on its own origin. Copy it onto the
  // frontend origin so subsequent /api/auth/* calls authenticate the same
  // session (the frontend forwards it back to Drupal server-side).
  for (const cookie of response.headers.getSetCookie()) {
    const separator = cookie.indexOf("=");
    if (separator === -1) continue;
    const name = cookie.slice(0, separator);
    const value = cookie.slice(separator + 1).split(";")[0];
    next.cookies.set(name, value, {
      path: "/",
      httpOnly: true,
      sameSite: "lax",
    });
  }

  return next;
}