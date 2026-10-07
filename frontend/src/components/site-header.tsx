"use client";

import Link from "next/link";
import { usePathname } from "next/navigation";
import { LogIn, LogOut, LayoutDashboard } from "lucide-react";
import { Button } from "@/components/ui/button";
import { accountHome } from "@/lib/portal";
import { useAuth } from "@/lib/auth";
import { LogoutConfirmDialog } from "@/components/logout-confirm-dialog";

export function BrandLockup({ subtext = "Patient Portal" }: { subtext?: string }) {
  return (
    <span className="flex items-center gap-2">
      <svg
        className="size-6 text-primary"
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        strokeWidth="2"
        aria-hidden="true"
      >
        <path d="M22 12h-4l-3 9L9 3l-3 9H2" />
      </svg>
      <span>
        <span className="block font-serif text-lg leading-none text-foreground">ChiroThin</span>
        <span className="block text-[10px] tracking-wide text-muted-foreground">{subtext}</span>
      </span>
    </span>
  );
}

export function SiteHeader() {
  const { user } = useAuth();
  const pathname = usePathname();
  const onLoginPage = pathname === "/login";

  return (
    <header className="sticky top-0 z-20 border-b border-border bg-surface/95 backdrop-blur">
      <div className="mx-auto flex h-16 w-full max-w-5xl items-center justify-between px-5 sm:px-8">
        <Link href="/" aria-label="ChiroThin home" className="rounded-lg outline-none focus-visible:ring-3 focus-visible:ring-ring/50">
          <BrandLockup />
        </Link>

        <nav className="flex items-center gap-1.5">
          {user ? (
            <>
              <span className="hidden text-sm text-muted-foreground sm:inline">
                {user.name}
              </span>
              <Button asChild variant="ghost" size="sm">
                <Link href={accountHome(user)}>
                  <LayoutDashboard data-icon="inline-start" />
                  Dashboard
                </Link>
              </Button>
              <LogoutConfirmDialog />
            </>
          ) : onLoginPage ? null : (
            <Button asChild size="sm">
              <Link href="/login">
                <LogIn data-icon="inline-start" />
                Log In
              </Link>
            </Button>
          )}
        </nav>
      </div>
    </header>
  );
}