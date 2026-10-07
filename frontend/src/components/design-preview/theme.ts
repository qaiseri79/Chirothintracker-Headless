import type { CSSProperties } from "react";

/**
 * Palette of the doctor subscription funnel.
 *
 * Source of truth: `New Design/ChiroThin Tracker — Subscribe flow.html`, the
 * `:root` block at line 9. Those are raw hex values under short names
 * (`--pri`, `--acc`, `--mut`), so rather than renaming them the block is mapped
 * onto the semantic slots `globals.css` already defines. Every `bg-*`, `text-*`
 * and `border-*` utility underneath then re-resolves against these, the same
 * technique `components/marketing/theme.ts` uses.
 *
 * Scoped to the mock-up's wrapper element rather than declared in `:root`:
 * `--background`, `--muted-foreground`, `--primary` and friends are shared with
 * every signed-in page, so overriding them here keeps the portal on its own
 * palette. The three variables below that `globals.css` has no slot for
 * (`--accent-hover`, `--warn`, `--meter-*`) ride along as extras.
 *
 * Only slots whose value actually differs from `globals.css` are listed.
 */
export const subscribeTheme = {
  /* `--bg` — the warm off-white page canvas. */
  "--background": "#f7f5f0",
  "--muted": "#f7f5f0",
  /* `--mut` — secondary text. */
  "--muted-foreground": "#4b5563",
  /* `--pri` / `--prih` — the teal brand and its hover. */
  "--primary": "#0b5d52",
  "--brand": "#0b5d52",
  "--brand-dark": "#08463e",
  /* `--soft` — the tinted teal panel behind checks and the featured tag. */
  "--brand-soft": "#e4f0ec",
  "--secondary": "#e4f0ec",
  "--secondary-foreground": "#08463e",
  "--ring": "#0b5d52",

  /*
   * `--acc` / `--acch` — the rust used for every primary call to action and for
   * invalid-field feedback. `--flame` is the slot `globals.css` already has;
   * `--accent-hover` has no equivalent, and `destructive` is deliberately left
   * alone so form errors elsewhere in the app keep their own meaning.
   */
  "--flame": "#a8421f",
  "--accent-hover": "#8c3517",

  /* The ring an invalid field gets, from `.f.bad`'s box-shadow (line 48). */
  "--danger-soft": "#f6e4dc",

  /*
   * The four password-strength meter stops, from the mock-up's `input` handler
   * (line 193). They are inline values in the source with no variable of their
   * own, so they are named here rather than left as hex inside a component.
   */
  "--meter-1": "#a8421f",
  "--meter-2": "#d08a2e",
  "--meter-3": "#5e9e6e",
  "--meter-4": "#0b5d52",

  /*
   * `--font-inter` / `--font-newsreader` are what `font-sans` and `font-serif`
   * compile down to (see the `@theme inline` block in globals.css), so
   * repointing them puts the whole mock-up on its Figtree + Fraunces pair
   * without touching the portal's own type. The two faces are loaded by the
   * route's layout.
   */
  "--font-inter": "var(--font-figtree), ui-sans-serif, system-ui, sans-serif",
  "--font-newsreader": "var(--font-fraunces), Georgia, serif",
} as CSSProperties;