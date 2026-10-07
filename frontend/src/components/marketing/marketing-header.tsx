"use client";

import { useState } from "react";
import Link from "next/link";
import { usePathname } from "next/navigation";
import { Menu, X } from "lucide-react";
import { accountHome } from "@/lib/portal";
import { useAuth } from "@/lib/auth";
import { Button } from "@/components/ui/button";
import { Container, pillSm } from "@/components/marketing/primitives";

/** The pulse mark from the mock-up's lockup. */
function LogoMark() {
  return (
    <svg
      width="30"
      height="30"
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth="2"
      strokeLinecap="round"
      strokeLinejoin="round"
      aria-hidden
      className="shrink-0 text-primary"
    >
      <path d="M3 12h4l3-8 4 16 3-8h4" />
    </svg>
  );
}

function Wordmark() {
  return (
    <span className="font-serif text-[22px] leading-none font-semibold whitespace-nowrap">
      ChiroThin <span className="text-primary">Tracker</span>
    </span>
  );
}

const NAV_LINKS = [
  { label: "Pricing", href: "#pricing" },
  { label: "FAQ", href: "#faq" },
];

export function MarketingHeader() {
  const { user } = useAuth();
  const pathname = usePathname();
  const [menuOpen, setMenuOpen] = useState(false);

  /*
   * The wordmark scrolls to the top of the landing page, but `#top` only exists
   * there — on the legal pages that anchor resolves to nothing, so those go to
   * the home route instead.
   */
  const homeHref = pathname === "/" ? "#top" : "/";
  const goHome = () => setMenuOpen(false);

  return (
    <header className="sticky top-0 z-20 border-b border-border bg-surface/95 backdrop-blur">
      <Container className="flex h-[72px] items-center justify-between gap-4">
        <Link
          href={homeHref}
          onClick={goHome}
          className="flex items-center gap-2.5 rounded-lg outline-none focus-visible:ring-3 focus-visible:ring-ring/50"
        >
          <LogoMark />
          <Wordmark />
        </Link>

        {/*
         * The mock-up's inline nav needs ~385px of row to sit beside the
         * wordmark, which no phone can spare, so below `sm` it collapses into
         * the disclosure at the end of this row.
         */}
        <nav className="hidden items-center gap-7 text-[15px] font-semibold sm:flex">
          {NAV_LINKS.map((link) => (
            <a
              key={link.href}
              href={pathname === "/" ? link.href : `/${link.href}`}
              className="rounded outline-none hover:text-primary focus-visible:ring-3 focus-visible:ring-ring/50"
            >
              {link.label}
            </a>
          ))}
          <Button asChild size="lg" className={pillSm}>
            <Link href={user ? accountHome(user) : "/login"}>
              {user ? "Dashboard" : "Log in"}
            </Link>
          </Button>
        </nav>

        <button
          type="button"
          aria-label={menuOpen ? "Close menu" : "Open menu"}
          aria-expanded={menuOpen}
          aria-controls="marketing-menu"
          onClick={() => setMenuOpen((open) => !open)}
          className="-mr-2 flex size-11 items-center justify-center rounded-lg text-foreground outline-none hover:bg-muted focus-visible:ring-3 focus-visible:ring-ring/50 sm:hidden"
        >
          {menuOpen ? (
            <X className="size-6" aria-hidden />
          ) : (
            <Menu className="size-6" aria-hidden />
          )}
        </button>
      </Container>

      {menuOpen ? (
        <Container
          id="marketing-menu"
          className="flex flex-col gap-1 border-t border-border py-3 sm:hidden"
        >
          {NAV_LINKS.map((link) => (
            <a
              key={link.href}
              href={pathname === "/" ? link.href : `/${link.href}`}
              onClick={() => setMenuOpen(false)}
              className="rounded-lg px-3 py-2.5 text-[15px] font-semibold outline-none hover:bg-muted focus-visible:ring-3 focus-visible:ring-ring/50"
            >
              {link.label}
            </a>
          ))}
          <Button asChild size="lg" className={`${pillSm} mt-1`}>
            <Link href={user ? accountHome(user) : "/login"}>
              {user ? "Dashboard" : "Log in"}
            </Link>
          </Button>
        </Container>
      ) : null}
    </header>
  );
}