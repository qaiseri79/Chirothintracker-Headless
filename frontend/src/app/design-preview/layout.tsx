import type { ReactNode } from "react";
import type { Metadata } from "next";
import { Figtree, Fraunces } from "next/font/google";
import { subscribeTheme } from "@/components/design-preview/theme";
import { DesignPreviewHeader } from "@/components/design-preview/preview-header";
import { DesignPreviewFooter } from "@/components/design-preview/preview-footer";

/* Design index shell. The subscription entry redirects to the real /subscribe route. */

/*
 * The subscribe mock-up uses the marketing face pair — Figtree for text,
 * Fraunces for headings — rather than the portal's Inter + Newsreader. Loaded
 * here, not in the root layout, because only this route uses them.
 */
const figtree = Figtree({
  variable: "--font-figtree",
  subsets: ["latin"],
});

const fraunces = Fraunces({
  variable: "--font-fraunces",
  subsets: ["latin"],
  axes: ["opsz"],
});

export const metadata: Metadata = {
  title: "Design preview — ChiroThin commerce",
  description: "Static mock-ups of the commerce designs, running on mock data.",
  robots: { index: false, follow: false },
};

export default function DesignPreviewLayout({ children }: { children: ReactNode }) {
  return (
    /*
     * The palette is applied to this wrapper rather than to `:root`, so the
     * overrides re-resolve for everything below without touching the signed-in
     * portal, which shares several of these tokens.
     *
     * `font-sans` is load-bearing, not decoration. Tailwind's base font is
     * declared on the root element, which sits *above* this wrapper and so still
     * resolves `--font-inter` to Inter. Without an explicit `font-sans` here,
     * every heading would come out in Fraunces (`font-serif` re-resolves against
     * the override on this element) while all the body copy quietly stayed in
     * Inter — half the design's type applied and half not.
     */
    <div
      style={subscribeTheme}
      className={`${figtree.variable} ${fraunces.variable} flex min-h-dvh flex-col bg-background font-sans text-foreground`}
    >
      <DesignPreviewHeader />
      <main className="flex-1">{children}</main>
      <DesignPreviewFooter />
    </div>
  );
}