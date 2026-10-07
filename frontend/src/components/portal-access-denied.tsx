"use client";

import { useRouter } from "next/navigation";
import Link from "next/link";
import { LogOut, ShieldAlert } from "lucide-react";
import { Button } from "@/components/ui/button";
import { BrandLockup } from "@/components/site-header";
import { useAuth } from "@/lib/auth";
import { PORTAL_ROLES } from "@/lib/portal";

/**
 * Shown to an authenticated account that has no portal at all — one holding
 * neither a patient nor a chiropractor role.
 *
 * This is deliberately *not* what an archived patient or an inactive
 * chiropractor sees: those accounts are legitimate portal users with reduced
 * capabilities, and they reach their own dashboard. Only accounts outside both
 * audiences land here, so the panel says so plainly rather than implying
 * anything is wrong with the credentials.
 *
 * It carries its own minimal brand bar rather than relying on the `(site)`
 * header, because the dashboards that render it live in `(portal)` — they have
 * the app shell instead, and this is the one case where that shell must *not*
 * appear, since the account has no audience to pick a sidebar from.
 */
export function PortalAccessDenied({ email }: { email?: string }) {
  const { logout } = useAuth();
  const router = useRouter();

  async function handleLogout() {
    await logout();
    router.replace("/login");
  }

  const allRoles = [
    ...Object.values(PORTAL_ROLES.patient),
    ...Object.values(PORTAL_ROLES.chiropractor),
  ];

  return (
    <div className="flex min-h-dvh flex-col">
      <header className="border-border border-b bg-surface">
        <div className="mx-auto flex h-16 w-full max-w-5xl items-center px-5 sm:px-8">
          <BrandLockup />
        </div>
      </header>

      <div className="mx-auto w-full max-w-2xl px-5 py-12 sm:px-8">
        <div className="rounded-xl border border-border bg-surface p-8 shadow-panel">
          <span className="flex size-9 items-center justify-center rounded-lg bg-flame-soft text-destructive">
            <ShieldAlert className="size-5" />
          </span>
          <h1 className="mt-4 font-serif text-2xl text-foreground">
            This account doesn&apos;t have a portal
          </h1>
          <div className="mt-2 space-y-3 text-muted-foreground text-sm">
            {email ? (
              <p>
                You are signed in as <span className="text-foreground">{email}</span>.
              </p>
            ) : null}
            <p>
              The sign-in was successful, but this account is neither a patient nor
              a chiropractor, so there is no dashboard to show you. A portal account
              needs one of these roles:
            </p>
            <ul className="list-inside list-disc space-y-1">
              {allRoles.map((role) => (
                <li key={role}>
                  <code className="rounded bg-muted px-1 py-0.5 font-mono text-foreground text-xs">
                    {role}
                  </code>
                </li>
              ))}
            </ul>
            <p>
              If you think that is wrong, contact your clinic — they can update the
              role on your account.
            </p>
          </div>
          <div className="mt-6 flex flex-wrap items-center gap-3">
            <Button variant="outline" onClick={handleLogout}>
              <LogOut data-icon="inline-start" />
              Log out
            </Button>
            <Button asChild variant="ghost">
              <Link href="/">Back to home</Link>
            </Button>
          </div>
        </div>
      </div>
    </div>
  );
}
