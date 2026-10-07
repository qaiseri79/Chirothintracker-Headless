import type { CSSProperties, ReactNode } from "react";
import { Check } from "lucide-react";
import { cn } from "cn";

/**
 * Layout primitives shared by the landing-page sections.
 *
 * The measurements come from the mock-up's utility classes (`.w`, `.sec`,
 * `.eb`, `.h2`, `.lead`, `.card`) — see
 * "ChiroThin Tracker New Front Page - B · Logo colors (teal).html".
 */

/** `.w` — the 1180px page gutter. */
export function Container({
  id,
  className,
  style,
  children,
}: {
  id?: string;
  className?: string;
  style?: CSSProperties;
  children: ReactNode;
}) {
  return (
    <div
      id={id}
      className={cn("mx-auto w-full max-w-[1180px] px-5 sm:px-7", className)}
      style={style}
    >
      {children}
    </div>
  );
}

/**
 * The mock-up's auto-fit grids (`.g2`, `.g3`, `.g4` and the hero's) each set a
 * minimum track width.
 *
 * A bare `minmax(Npx, 1fr)` makes N a *hard* floor: on a viewport narrower than
 * N the track stays N wide and spills out of the gutter, which is what gave the
 * landing page a horizontal scrollbar on a phone. Every grid below therefore
 * spells its minimum as `min(Npx, 100%)` so the track collapses to the
 * container instead of overflowing it.
 */
export const GRID_HERO = "grid-cols-[repeat(auto-fit,minmax(min(380px,100%),1fr))]";
/** `.g2` — two-up bands. */
export const GRID_2 = "grid-cols-[repeat(auto-fit,minmax(min(340px,100%),1fr))]";
/** `.g3` — the feature grid. */
export const GRID_3 = "grid-cols-[repeat(auto-fit,minmax(min(310px,100%),1fr))]";
/** `.g4` — the four-up pricing grid. */
export const GRID_4 = "grid-cols-[repeat(auto-fit,minmax(min(240px,100%),1fr))]";

/**
 * `.sec` — a 96px band, stepped down on phones where 96px top and bottom would
 * eat a quarter of the screen for every section. `paper` is the mock-up's
 * alternating white band with hairlines top and bottom; `plain` sits on the
 * page canvas.
 */
export function Section({
  id,
  tone = "plain",
  className,
  children,
}: {
  id?: string;
  tone?: "plain" | "paper";
  className?: string;
  children: ReactNode;
}) {
  return (
    <section
      id={id}
      className={cn(
        "scroll-mt-16 py-16 sm:py-24",
        tone === "paper" && "border-y border-border bg-surface",
        className,
      )}
    >
      {children}
    </section>
  );
}

/** `.eb` — the small caps label that opens every section. */
export function Eyebrow({ children }: { children: ReactNode }) {
  return (
    <span className="block text-[13px] font-bold tracking-[0.12em] text-primary uppercase">
      {children}
    </span>
  );
}

/** `.h2` — section heading. */
export function SectionTitle({ children }: { children: ReactNode }) {
  return (
    <h2 className="mt-3 font-serif text-[clamp(30px,4vw,46px)] leading-[1.1] font-medium tracking-[-0.01em]">
      {children}
    </h2>
  );
}

/** `.lead` — the sentence under a section heading. */
export function Lead({ children }: { children: ReactNode }) {
  return (
    <p className="mt-4 max-w-[620px] text-[18px] text-muted-foreground">
      {children}
    </p>
  );
}

/**
 * `.card h3` / `.card p` — the mock-up styles these two tags globally, so they
 * are spelled out here to keep every card's heading and body identical. The
 * `mb-2.5` is the mock-up's 10px under a card heading.
 */
export function CardTitle({ children }: { children: ReactNode }) {
  return (
    <h3 className="mb-2.5 font-serif text-[22px] leading-[1.1] font-medium tracking-[-0.01em]">
      {children}
    </h3>
  );
}

export function CardBody({ children }: { children: ReactNode }) {
  return <p className="text-[15.5px] text-muted-foreground">{children}</p>;
}

/** `.card` — the white rounded panel. `canvas` is the tinted variant. */
export function Card({
  tone = "surface",
  className,
  children,
}: {
  tone?: "surface" | "canvas";
  className?: string;
  children: ReactNode;
}) {
  return (
    <div
      className={cn(
        "rounded-[20px] border border-border p-7",
        tone === "surface" ? "bg-surface" : "bg-background",
        className,
      )}
    >
      {children}
    </div>
  );
}

/** `.ic` — the tinted 44px tile that fronts a feature card. */
export function IconTile({ children }: { children: ReactNode }) {
  return (
    <div className="mb-[18px] flex size-11 items-center justify-center rounded-xl bg-brand-soft text-primary">
      {children}
    </div>
  );
}

/** `.num` — the numbered marker in a "how it works" step list. */
export function StepNumber({ children }: { children: ReactNode }) {
  return (
    <span className="flex size-8 shrink-0 items-center justify-center rounded-full bg-primary text-[14px] font-bold text-primary-foreground">
      {children}
    </span>
  );
}

/**
 * `.ck` — the feature checklist. `onBrand` is the reversed treatment for the
 * featured pricing card.
 */
export function Checklist({
  items,
  onBrand = false,
  className,
}: {
  items: ReactNode[];
  onBrand?: boolean;
  className?: string;
}) {
  return (
    <ul className={cn("grid gap-2.5 text-[15px]", className)}>
      {items.map((item, index) => (
        <li key={index} className="flex items-start gap-2.5">
          <Check
            aria-hidden
            className={cn(
              "mt-0.5 size-4 shrink-0",
              onBrand ? "text-primary-foreground" : "text-primary",
            )}
          />
          <span>{item}</span>
        </li>
      ))}
    </ul>
  );
}

/**
 * The mock-up's `.btn` — a 48px pill. Kept here rather than added to the shared
 * `ui/button` so the portal keeps its compact buttons.
 */
export const pill = "h-12 gap-2 rounded-full px-6 text-base font-semibold";

/** The header's smaller pill. */
export const pillSm = "h-11 gap-2 rounded-full px-5 text-base font-semibold";

/**
 * A pair of side-by-side CTAs (the hero and the closing band).
 *
 * At the mock-up's type size the pair needs ~323px, which a phone cannot spare,
 * so on mobile they become an equal two-column grid at a smaller type size and
 * tighter padding rather than wrapping onto two rows. From `sm` up they are the
 * mock-up's centred/wrapping flex row again.
 */
export const ctaRow =
  "grid grid-cols-2 gap-2.5 sm:flex sm:flex-wrap sm:gap-3.5";

export const pillCta =
  "h-12 gap-2 rounded-full px-2.5 text-[13px] font-semibold sm:px-6 sm:text-base";