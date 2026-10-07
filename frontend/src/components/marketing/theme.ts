import type { CSSProperties } from "react";

/**
 * Palette and typography of the marketing landing page.
 *
 * Source of truth: "ChiroThin Tracker New Front Page - B · Logo colors
 * (teal).html" (repo root, `New Design/`), theme `logo`. The mock-up drives
 * every colour through a set of CSS custom properties hung off its root
 * element, so the same is done here — `marketingTheme` is applied to the
 * landing page wrapper and every `bg-*`, `text-*`, `border-*` and `font-*`
 * utility underneath re-resolves against these values.
 *
 * Only the slots whose value actually differs from `globals.css` are listed;
 * everything else (surfaces, ink, the outline) already matches. The portal is
 * deliberately left alone: `--flame`, `--muted-foreground` and `--background`
 * are shared with every signed-in page, so overriding them here rather than in
 * `:root` keeps the rest of the app on its own palette.
 */
export const marketingTheme = {
  "--background": "#f7f5f0",
  "--muted": "#f7f5f0",
  "--muted-foreground": "#4b5563",
  "--primary": "#0b5d52",
  "--brand": "#0b5d52",
  "--brand-dark": "#08463e",
  "--brand-soft": "#e4f0ec",
  "--secondary": "#e4f0ec",
  "--secondary-foreground": "#08463e",
  "--flame": "#a8421f",
  "--ring": "#0b5d52",

  /*
   * `--font-inter` / `--font-newsreader` are the variables `font-sans` and
   * `font-serif` compile down to (see the `@theme inline` block in
   * globals.css), so repointing them swaps the whole page to the mock-up's
   * Figtree + Fraunces pair without touching the portal's own type.
   */
  "--font-inter": "var(--font-figtree), ui-sans-serif, system-ui, sans-serif",
  "--font-newsreader": "var(--font-fraunces), Georgia, serif",
} as CSSProperties;