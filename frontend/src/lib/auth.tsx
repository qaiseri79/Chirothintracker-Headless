"use client";

import { createContext, useCallback, useContext, useEffect, useRef, useState } from "react";

import type { EffectivePortalAccess } from "@/lib/portal";
import type { DoctorSubscription, SubscriptionCapabilities } from "@/lib/subscriptions/types";

export interface AuthUser {
  portalAccess?: EffectivePortalAccess | null;
  id: number;
  capabilities?: SubscriptionCapabilities;
  subscription?: DoctorSubscription | null;
  email: string;
  /** Display name shown in the header greeting and dashboard. */
  name: string;
  /**
   * Drupal roles on the account. Empty means "not known" — see
   * `hasPatientAccess` in `lib/portal`.
   */
  roles: string[];
}

export type AuthStatus = "loading" | "authenticated" | "anonymous";

interface AuthContextValue {
  user: AuthUser | null;
  status: AuthStatus;
  login: (email: string, password: string) => Promise<AuthUser>;
  logout: () => Promise<void>;
  refresh: () => Promise<AuthUser | null>;
}

interface SessionUserDto {
  portalAccess?: EffectivePortalAccess | null;
  id?: number;
  capabilities?: SubscriptionCapabilities;
  subscription?: DoctorSubscription | null;
  name?: string;
  mail?: string;
  roles?: string[];
}

function displayNameFallback(input: string): string {
  const local = input.split("@")[0].replace(/[._-]+/g, " ");
  return local.replace(/\b\w/g, (char) => char.toUpperCase()).trim();
}

/** Patients enroll with their email as the account name, so derive a friendly
 *  display name when the account name is itself an email. */
function toAuthUser(data: SessionUserDto): AuthUser {
  const email = data.mail ?? data.name ?? "";
  const accountName = data.name ?? email;
  const looksLikeEmail = /^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(accountName);
  return {
    id: Number(data.id) || 0,
    portalAccess: data.portalAccess,
    capabilities: data.capabilities,
    subscription: data.subscription,
    email,
    name: accountName && !looksLikeEmail ? accountName : displayNameFallback(email),
    roles: Array.isArray(data.roles) ? data.roles : [],
  };
}

async function currentUser(): Promise<AuthUser | null> {
  const response = await fetch("/api/auth/me", { cache: "no-store" });
  if (response.status === 401) return null;
  if (!response.ok) throw new Error("Unable to refresh account access.");
  const data = (await response.json().catch(() => null)) as SessionUserDto | null;
  if (!data || (!data.name && !data.mail)) return null;
  return toAuthUser(data);
}

const AuthContext = createContext<AuthContextValue | null>(null);

/**
 * Real Drupal session authentication.
 *
 * The browser holds an HttpOnly Drupal session cookie (copied onto this
 * origin by /api/auth/login). Mounting the provider re-validates it against
 * Drupal via /api/auth/me, and login/logout proxy through the same endpoints.
 */
export function AuthProvider({ children }: { children: React.ReactNode }) {
  const [user, setUser] = useState<AuthUser | null>(null);
  const [status, setStatus] = useState<AuthStatus>("loading");

  useEffect(() => {
    let cancelled = false;
    currentUser()
      .then((next) => {
        if (cancelled) return;
        setUser(next);
        setStatus(next ? "authenticated" : "anonymous");
      })
      .catch(() => {
        if (!cancelled) setStatus("anonymous");
      });
    return () => {
      cancelled = true;
    };
  }, []);

  const login = useCallback(async (email: string, password: string): Promise<AuthUser> => {
    const trimmed = email.trim();
    if (!trimmed) throw new Error("Enter your email address.");
    if (!password) throw new Error("Enter your password.");

    const response = await fetch("/api/auth/login", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ email: trimmed, password }),
      cache: "no-store",
    });
    const data = (await response.json().catch(() => null)) as
      | (SessionUserDto & { message?: string })
      | null;
    if (!response.ok) {
      throw new Error(data?.message ?? "Unable to log in. Please try again.");
    }
    // The response is core's, and core only includes `roles` when the account
    // passes field access on its own roles field
    // (`UserAuthenticationController::login()`), so it cannot be trusted to
    // carry them. Re-read the session instead, which does. The login response
    // is only a fallback for when that follow-up cannot be made.
    const next = (await currentUser().catch(() => null)) ?? toAuthUser(data ?? {});
    setUser(next);
    setStatus("authenticated");
    return next;
  }, []);

  const refreshRequest = useRef<Promise<AuthUser | null> | null>(null);
  const refresh = useCallback(() => {
    if (refreshRequest.current) return refreshRequest.current;
    const request = currentUser().then((next) => {
      setUser((previous) => JSON.stringify(previous) === JSON.stringify(next) ? previous : next);
      setStatus(next ? "authenticated" : "anonymous");
      return next;
    }).finally(() => { if (refreshRequest.current === request) refreshRequest.current = null; });
    refreshRequest.current = request;
    return request;
  }, []);

  useEffect(() => {
    if (status !== "authenticated") return;
    const update = () => { if (document.visibilityState === "visible") void refresh().catch(() => undefined); };
    window.addEventListener("focus", update);
    document.addEventListener("visibilitychange", update);
    window.addEventListener("portal-access-change", update);
    const interval = window.setInterval(update, 60_000);
    const boundary = user?.portalAccess?.paidThrough;
    const delay = boundary ? boundary * 1000 - Date.now() + 1000 : 0;
    const timer = delay > 0 ? window.setTimeout(update, Math.min(delay, 2_147_483_647)) : undefined;
    return () => {
      window.removeEventListener("focus", update);
      document.removeEventListener("visibilitychange", update);
      window.removeEventListener("portal-access-change", update);
      window.clearInterval(interval);
      if (timer !== undefined) window.clearTimeout(timer);
    };
  }, [status, user?.portalAccess?.paidThrough, refresh]);

  const logout = useCallback(async (): Promise<void> => {
    try {
      await fetch("/api/auth/logout", { method: "POST", cache: "no-store" });
    } finally {
      setUser(null);
      setStatus("anonymous");
    }
  }, []);

  return (
    <AuthContext.Provider value={{ user, status, login, logout, refresh }}>
      {children}
    </AuthContext.Provider>
  );
}

export function useAuth(): AuthContextValue {
  const value = useContext(AuthContext);
  if (!value) throw new Error("useAuth must be used within <AuthProvider>.");
  return value;
}