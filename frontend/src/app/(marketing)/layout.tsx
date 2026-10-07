import type { ReactNode } from "react";
import type { Metadata } from "next";
import { Figtree, Fraunces } from "next/font/google";
import { MarketingHeader } from "@/components/marketing/marketing-header";
import { MarketingFooter } from "@/components/marketing/marketing-footer";
import { marketingTheme } from "@/components/marketing/theme";

/*
 * The marketing landing page runs on its own pair of faces — Figtree for text,
 * Fraunces for headings — as laid out in
 * "ChiroThin Tracker New Front Page - B · Logo colors (teal).html". They are
 * loaded here rather than in the root layout because only this route uses them.
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
  title: "ChiroThin Tracker — ChiroThin® patient tracking software",
  description:
    "Built by ChiroThin® doctors for ChiroThin® doctors. The only authorized patient tracking software, fully endorsed by Chiro Nutraceutical™, the makers of ChiroThin®.",
};

export default function MarketingLayout({ children }: { children: ReactNode }) {
  return (
    /*
     * `marketingTheme` is scoped to this wrapper rather than declared in
     * `:root`, so the colours and faces the mock-up asks for apply here without
     * dragging the signed-in portal onto the same palette.
     */
    <div
      style={marketingTheme}
      className={`${figtree.variable} ${fraunces.variable} flex min-h-dvh flex-col bg-background font-sans text-foreground`}
    >
      <MarketingHeader />
      <main className="flex-1">{children}</main>
      <MarketingFooter />
    </div>
  );
}