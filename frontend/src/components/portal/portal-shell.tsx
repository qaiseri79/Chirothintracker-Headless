"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import { usePathname, useRouter } from "next/navigation";
import { Menu, X } from "lucide-react";
import { BrandLockup } from "@/components/site-header";
import { Button } from "@/components/ui/button";
import { InitialsAvatar } from "@/components/avatar";
import { useAuth } from "@/lib/auth";
import { useMessageUnread } from "@/components/providers/message-unread-provider";
import { useIntakeCount } from "@/components/providers/intake-count-provider";
import { PORTAL_NAV, isNavItemActive, type PortalNavItem } from "@/lib/portal-nav";
import { resolvePortalAccess, type PortalAudience } from "@/lib/portal";
import type { EffectivePortalAccess } from "@/lib/portal";
import { PortalAccessNotice } from "./portal-access-notice";
import { LogoutConfirmDialog } from "@/components/logout-confirm-dialog";

/**
 * The signed-in app shell: collapsing sidebar, sticky header, scrollable main.
 *
 * Ported from "New Design/ChiroThin — My Progress.html", the first design to
 * include the shell. Both dashboards use it, so the two audiences differ only in
 * the nav list they are handed.
 *
 * This replaces `SiteHeader` for the portal routes, which is why the dashboards
 * moved out of the `(site)` group: that layout renders the public header, and
 * the design has no room for both.
 *
 * ## Why it is a client component
 *
 * Three things need the browser: the active nav item (`usePathname`), the
 * signed-in name and the read-only flag (`useAuth`), and the mobile drawer.
 * The sidebar's collapsed/expanded states are pure CSS on the desktop aside —
 * `w-[72px]` widening to `w-64` on hover — so the only JavaScript state here is
 * the mobile drawer.
 *
 * ## Read-only
 *
 * `resolvePortalAccess` is re-run on the client rather than threaded down from
 * the server guard. It is the same pure function reading the same roles, so the
 * two cannot disagree, and it keeps the flag next to the controls that consult it
 * — which is how `lib/portal.ts` describes the model. The server layout still
 * owns the decision about *which dashboard* an account may see; this only
 * decides which of its own controls are live.
 *
 * ## Title and `bleed`
 *
 * `title` fills the header slot the design draws to the left of the spacer
 * ("Messages" on the messages page). Optional, so the dashboards that render
 * their own `<h1>` keep the header as it was.
 *
 * `bleed` drops `<main>`'s padding, max-width and scrolling so a page can run
 * edge to edge and manage its own scroll regions. The messages page needs it:
 * the design has a fixed thread header, a scrolling thread, and a fixed reply
 * bar, which cannot work inside a padded `overflow-y-auto` wrapper.
 */
export function PortalShell({
  audience,
  initialAccess,
  title,
  headerAside,
  bleed = false,
  maxWidth = "narrow",
  children,
}: {
  audience: PortalAudience;
  initialAccess?: EffectivePortalAccess | null;
  /** Serif heading in the header, left of the spacer. */
  title?: React.ReactNode;
  /**
   * Rendered in the header between the spacer and the account cluster.
   *
   * The patients design puts a subscription/enrolment pill in that exact slot —
   * see `head()` in "New Design/ChiroThin — Patients.html" — and the progress and
   * messages designs leave it empty. A slot rather than a prop-shaped pill
   * because the figure inside it is page data, not shell state.
   */
  headerAside?: React.ReactNode;
  /**
   * Render children edge to edge without the padded, width-capped, scrolling
   * `<main>`. The child must then own its own height and overflow.
   */
  bleed?: boolean;
  /**
   * How wide the padded content column may grow. The chiropractor dashboard's
   * design (`chirothin-patient-summary-v9.html`) caps at 1400px; the progress
   * dashboard's at 1200px, which is the default.
   */
  maxWidth?: "narrow" | "wide";
  children: React.ReactNode;
}) {
  const { user, status } = useAuth();
  const effective = status === "loading" ? initialAccess : user?.portalAccess;
  const pathname = usePathname();
  const router = useRouter();
  const [drawerOpen, setDrawerOpen] = useState(false);

  const { unreadCount } = useMessageUnread();
  const { intakeCount } = useIntakeCount();
  const messageHref = audience === "patient" ? "/messages" : "/chiropractor/messages";
  const items = PORTAL_NAV[audience].filter(item => item.href !== "/billing" || (effective?.canManageBilling ?? Boolean(user?.capabilities))).map((item) =>
    item.href === messageHref
      ? { ...item, badge: badgeCount(unreadCount) }
      : audience === "chiropractor" && item.href === "/chiropractor/patients"
        ? { ...item, badge: badgeCount(intakeCount) }
        : item,
  );
  const readOnly = resolvePortalAccess(user?.roles, user?.capabilities, effective)?.readOnly ?? true;

  // The shell is a scroll container, so the body must not scroll behind an open
  // drawer. Synchronising an external system, so this one is a real effect.
  useEffect(() => {
    if (!drawerOpen) return;
    const previous = document.body.style.overflow;
    document.body.style.overflow = "hidden";
    return () => {
      document.body.style.overflow = previous;
    };
  }, [drawerOpen]);

  // The guard layout settled access on the server; this only covers the session
  // being lost while the page is open, same as the pages did before the shell
  // took over their body.
  useEffect(() => {
    if (status === "anonymous") router.replace("/login");
  }, [status, router]);

  return (
    <div className="flex h-dvh">
      <aside className="group/sidebar hidden shrink-0 flex-col overflow-hidden border-r border-border bg-surface transition-[width] duration-200 hover:w-64 md:flex w-[72px]">
        <div className="flex h-16 shrink-0 items-center gap-3 border-b border-border px-5">
          <span className="flex size-6 shrink-0 text-primary">
            <BrandMark />
          </span>
          <span className="hidden whitespace-nowrap opacity-0 transition-opacity duration-150 group-hover/sidebar:opacity-100">
            <span className="block font-serif text-base leading-none text-foreground">
              ChiroThin
            </span>
            <span className="block text-[10px] tracking-wide text-muted-foreground">
              My Account
            </span>
          </span>
        </div>
        <nav aria-label="Portal" className="flex-1 space-y-0.5 px-3 py-4">
          <NavList items={items} pathname={pathname} readOnly={readOnly} />
        </nav>
      </aside>

      {drawerOpen ? (
        <div className="fixed inset-0 z-50 md:hidden">
          <button
            type="button"
            aria-label="Close menu"
            className="absolute inset-0 bg-foreground/20"
            onClick={() => setDrawerOpen(false)}
          />
          <div className="absolute inset-y-0 left-0 flex w-64 flex-col border-r border-border bg-surface shadow-panel">
            <div className="flex h-16 shrink-0 items-center justify-between gap-3 border-b border-border px-5">
              <BrandLockup subtext="My Account" />
              <Button
                variant="ghost"
                size="icon-sm"
                onClick={() => setDrawerOpen(false)}
                aria-label="Close menu"
              >
                <X />
              </Button>
            </div>
            <nav aria-label="Portal" className="flex-1 space-y-0.5 overflow-y-auto px-3 py-4">
              <NavList
                items={items}
                pathname={pathname}
                readOnly={readOnly}
                alwaysShowLabels
                onNavigate={() => setDrawerOpen(false)}
              />
            </nav>
          </div>
        </div>
      ) : null}

      <div className="flex min-w-0 flex-1 flex-col">
        <header className="sticky top-0 z-30 flex h-16 shrink-0 items-center gap-4 border-b border-border bg-surface/90 px-6 backdrop-blur">
          <Button
            variant="ghost"
            size="icon"
            className="md:hidden"
            onClick={() => setDrawerOpen(true)}
            aria-label="Open menu"
            aria-expanded={drawerOpen}
          >
            <Menu />
          </Button>
          {title ? (
            <h1 className="font-serif text-lg leading-none text-foreground">{title}</h1>
          ) : null}
          <div className="flex-1" />
          {headerAside}
          <div className="flex items-center gap-2 border-l border-border pl-4">
            <InitialsAvatar name={user?.name} />
            <span className="hidden font-medium text-foreground text-sm xl:block">
              {user?.name ?? ""}
            </span>
            <LogoutConfirmDialog iconOnly />
          </div>
        </header>

        <PortalAccessNotice access={effective} audience={audience} loading={status === "loading" && effective === undefined} />
        {bleed ? (
          <main className="flex min-h-0 flex-1 flex-col overflow-hidden">{children}</main>
        ) : (
          <main className="flex-1 overflow-y-auto bg-canvas px-6 py-6 lg:px-8 lg:py-8">
            <div className={`mx-auto space-y-8 ${maxWidth === "wide" ? "max-w-[1400px]" : "max-w-[1200px]"}`}>{children}</div>
          </main>
        )}
      </div>
    </div>
  );
}

/**
 * The nav list, shared by the desktop sidebar and the mobile drawer.
 *
 * `alwaysShowLabels` is the only difference between the two call sites: the
 * sidebar hides labels until hover, the drawer shows them because it is already
 * 256px wide.
 */
function NavList({
  items,
  pathname,
  readOnly,
  alwaysShowLabels = false,
  onNavigate,
}: {
  items: PortalNavItem[];
  pathname: string;
  readOnly: boolean;
  alwaysShowLabels?: boolean;
  onNavigate?: () => void;
}) {
  return (
    <>
      {items.map((item) => (
        <NavLink
          key={item.label}
          item={item}
          active={isNavItemActive(item, pathname)}
          readOnly={readOnly}
          alwaysShowLabels={alwaysShowLabels}
          onNavigate={onNavigate}
        />
      ))}
    </>
  );
}

/**
 * A sidebar count, or nothing.
 *
 * `undefined` is what hides the pill, and these counts arrive as `null` while
 * their request is still in flight — which is why the badge was `?? undefined`
 * rather than a number that happens to be falsy. Zero has to go the same way,
 * and the reason is not tidiness: a "0" on Messages or Patients asserts that the
 * clinic looked and found nothing, which is a claim about a queue that was
 * checked. When there is genuinely nothing to report, absence is the honest
 * state — and the sidebar pill is at its most insistent precisely where there is
 * nothing to attend to.
 *
 * The placeholder counts in `PORTAL_NAV` are unaffected: both items carrying one
 * are the two this helper feeds, so the design's numbers are always replaced.
 */
function badgeCount(count: number | null | undefined): number | undefined {
  return typeof count === "number" && count > 0 ? count : undefined;
}

function NavLink({
  item,
  active,
  readOnly,
  alwaysShowLabels,
  onNavigate,
}: {
  item: PortalNavItem;
  active: boolean;
  readOnly: boolean;
  alwaysShowLabels: boolean;
  onNavigate?: () => void;
}) {
  const { icon: Icon, label, href } = item;
  const navBadge = href === "/messages" || href === "/chiropractor/messages"
    || href === "/chiropractor/patients" ? item.badge : undefined;
  const badgeDescription = href === "/chiropractor/patients"
    ? `${navBadge} new intake ${navBadge === 1 ? "form" : "forms"}`
    : `${navBadge} unread ${navBadge === 1 ? "message" : "messages"}`;

  const className = [
    "relative flex items-center gap-3 rounded-lg px-2.5 py-2.5 text-sm font-medium transition-colors",
    active
      ? "bg-primary text-primary-foreground"
      : "text-foreground/70 hover:bg-brand-soft hover:text-primary",
  ].join(" ");

  const labelClassName = [
    "flex-1 whitespace-nowrap transition-opacity duration-150",
    alwaysShowLabels ? "opacity-100" : "opacity-0 group-hover/sidebar:opacity-100",
  ].join(" ");

  // An operation the read-only audience may not perform. The design's sidebar is
  // navigation only, so this cannot trigger today; it is here so a future
  // "Log My Weight" is wired correctly the first time rather than shipped
  // enabled to 30k archived patients.
  const isOperation = /^(log|edit|add|new|upload|submit)/i.test(label);
  const blocked = readOnly && isOperation;

  const icon = <Icon className="size-[18px] shrink-0" aria-hidden="true" />;

  if (!href) {
    return (
      <span
        className={`${className} cursor-default opacity-60`}
        title={
          blocked
            ? `${label} is unavailable on a read-only account.`
            : `${label} is not built yet.`
        }
      >
        {icon}
        <span className={labelClassName}>{label}</span>
      </span>
    );
  }

  return (
    <Link
      href={href}
      className={className}
      aria-current={active ? "page" : undefined}
      aria-label={navBadge === undefined ? undefined
        : `${label}, ${badgeDescription}`}
      onClick={onNavigate}
    >
      {icon}
      <span className={labelClassName}>{label}</span>
      {navBadge !== undefined && (
        <span
          aria-hidden="true"
          className={`shrink-0 rounded-full bg-flame px-1.5 py-0.5 text-[10px] font-semibold text-white ${
            alwaysShowLabels ? "" : "absolute left-6 top-1 group-hover/sidebar:static"
          }`}
        >
          {navBadge > 99 ? "99+" : navBadge}
        </span>
      )}
    </Link>
  );
}

/** The ChiroThin pulse mark, matching `BrandLockup` in the public header. */
function BrandMark() {
  return (
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" aria-hidden="true">
      <path d="M22 12h-4l-3 9L9 3l-3 9H2" />
    </svg>
  );
}
