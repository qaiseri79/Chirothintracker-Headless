"use client";

import Link from "next/link";
import { usePathname } from "next/navigation";
import { cn } from "cn";

/**
 * Header for the preview shell.
 *
 * Reproduces the mock-up's own header (line 65): white bar, hairline bottom
 * border, 72px row, pulse mark beside the wordmark, and a right-hand group of
 * two text links plus a ghost "Log in" button. `FAQ` and `Pricing` are inert
 * here — they are the mock-up's `#` links, and the landing page's real anchors
 * live at `#pricing` / `#faq` on `/`.
 *
 * The added piece is the "Preview" pill naming the current mock-up. That is not
 * in the design; it exists so anyone browsing /design-preview can tell at a
 * glance that they are looking at a mock-up and not a live page.
 */

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
      className="shrink-0 text-brand"
    >
      <path d="M3 12h4l3-8 4 16 3-8h4" />
    </svg>
  );
}

export function DesignPreviewHeader() {
  return (
    <header className="border-b border-border bg-surface">
      <div className="mx-auto flex h-[72px] w-full max-w-[1120px] items-center justify-between gap-4 px-5">
        <Link
          href="/design-preview"
          className="flex items-center gap-2.5 rounded-lg outline-none focus-visible:ring-3 focus-visible:ring-ring/50"
        >
          <LogoMark />
          <span className="font-serif text-[22px] leading-none font-semibold whitespace-nowrap">
            ChiroThin <span className="text-brand">Tracker</span>
          </span>
        </Link>

        <nav className="flex items-center gap-7 text-[15px] font-semibold">
          {/*
           * The mock-up hides these two on narrow screens via `.hide`. Hidden
           * rather than collapsed into a disclosure, to stay faithful — the
           * header has no menu state and adding one would be inventing UI.
           */}
          {/*
           * `Link` rather than `<a>` even for same-page anchors: these point at
           * the landing page's `#faq` / `#pricing`, which is a real route, and the
           * lint rule is right that a bare anchor there would do a full reload.
           */}
          <Link
            href="/#faq"
            className="hidden rounded outline-none hover:text-brand focus-visible:ring-3 focus-visible:ring-ring/50 min-[860px]:block"
          >
            FAQ
          </Link>
          <Link
            href="/#pricing"
            className="hidden rounded outline-none hover:text-brand focus-visible:ring-3 focus-visible:ring-ring/50 min-[860px]:block"
          >
            Pricing
          </Link>

          <span className="hidden rounded-full border border-brand/30 bg-brand-soft px-3 py-1 text-[12px] font-bold tracking-[0.08em] text-brand uppercase sm:inline-block">
            Mock-up
          </span>

          <Link
            href="/login"
            className="inline-flex h-11 items-center justify-center gap-2 rounded-[12px] border border-border bg-surface px-6 text-[15px] font-bold text-foreground transition-colors hover:bg-brand-soft"
          >
            Log in
          </Link>
        </nav>
      </div>
    </header>
  );
}

/**
 * The nav trail inside the preview shell.
 *
 * The active item is read from the current path rather than passed in, so a page
 * cannot render the wrong one — a caller forgetting `active` is a much likelier
 * mistake than the pathname being unavailable.
 */
export function PreviewTrail({
  items,
}: {
  items: readonly { href: string; label: string }[];
}) {
  /* `PREVIEW_PAGES` lives in `lib/design-preview/preview-pages.ts`, not here:
     this file is a client component, and a server page importing an array from
     one would get a client-reference proxy rather than the array. */
  const pathname = usePathname();

  return (
    <nav aria-label="Mock-ups" className="flex flex-wrap gap-2 text-[14px] font-semibold">
      {items.map((item) => (
        <Link
          key={item.href}
          href={item.href}
          aria-current={pathname === item.href ? "page" : undefined}
          className={cn(
            "rounded-full border px-4 py-2 transition-colors outline-none focus-visible:ring-3 focus-visible:ring-ring/50",
            pathname === item.href
              ? "border-brand bg-brand text-primary-foreground"
              : "border-border bg-surface text-foreground hover:border-brand hover:bg-brand-soft",
          )}
        >
          {item.label}
        </Link>
      ))}
    </nav>
  );
}