import { cn } from "cn";

/**
 * Layout primitives for the doctor subscription funnel.
 *
 * Transcribed from the CSS block of
 * `New Design/ChiroThin Tracker — Subscribe flow.html` (lines 8–62). The mock-up
 * is a single stylesheet driving bare tags, so the same measurements are
 * expressed here as Tailwind utilities: `.w` becomes `SubscribeContainer`,
 * `.st` becomes `StepItem`, and so on.
 */

/** `.w` — the 1120px page gutter, narrower than the landing page's 1180px. */
export function SubscribeContainer({
  className,
  children,
}: {
  className?: string;
  children: React.ReactNode;
}) {
  return (
    <div className={cn("mx-auto w-full max-w-[1120px] px-5", className)}>
      {children}
    </div>
  );
}

/**
 * `.grid` — the two-column body of every step: a wide form column and a narrow
 * order summary. The mock-up's `1.6fr 1fr` collapses to a single column at
 * 860px, which is where its own media query sits.
 */
export const SUBSCRIBE_GRID =
  "grid grid-cols-1 items-start gap-6 my-8 lg:grid-cols-[1.6fr_1fr]";

/** `.card` — the white rounded panel with the mock-up's soft double shadow. */
export function SubscribeCard({
  className,
  children,
}: {
  className?: string;
  children: React.ReactNode;
}) {
  return (
    <div
      className={cn(
        "rounded-[20px] border border-border bg-surface p-7",
        "shadow-[0_1px_2px_rgba(16,24,39,0.04),0_12px_32px_-16px_rgba(16,24,39,0.12)]",
        className,
      )}
    >
      {children}
    </div>
  );
}

/** `.sum` — the sticky order-summary column. */
export const SUBSCRIBE_SUMMARY =
  "lg:sticky lg:top-5";

/** `h1` — the mock-up's centred Fraunces step heading. */
export const SUBSCRIBE_H1 =
  "mt-5 text-center font-serif text-[clamp(30px,4vw,44px)] leading-[1.1] font-semibold";

/** `.sub` — the muted sentence under a step heading. */
export const SUBSCRIBE_SUB =
  "mx-auto mt-2.5 max-w-[560px] text-center text-muted-foreground";

/** `.tag` — the small caps teal pill naming the selected plan. */
export const SUBSCRIBE_TAG =
  "inline-block rounded-full bg-brand-soft px-3 py-1 text-[12px] font-bold tracking-[0.06em] text-brand uppercase";

/** `.price` — the Fraunces figure with its Figtree "/month" suffix. */
export const SUBSCRIBE_PRICE =
  "font-serif text-[56px] leading-none font-semibold";

/** `.price small` — the muted period suffix inside the figure. */
export const SUBSCRIBE_PRICE_SUFFIX =
  "font-sans text-[16px] font-medium text-muted-foreground";

/** `.pills` / `.pill` — the plan selector chips. */
export const SUBSCRIBE_PILL =
  "cursor-pointer rounded-full border border-border bg-background px-4 py-[9px] text-[14px] font-semibold transition-colors outline-none hover:border-brand focus-visible:ring-3 focus-visible:ring-ring/50";
export const SUBSCRIBE_PILL_ON =
  "border-brand bg-brand text-primary-foreground hover:border-brand";

/** `.lbl` — the bold field label. */
export const SUBSCRIBE_LABEL =
  "mb-1.5 block text-[13px] font-bold";

/**
 * `.f` — the text field.
 *
 * `.bad` in the mock-up is a sibling selector (`bad~.err`), so an invalid field
 * is expressed here by passing `invalid` and letting the ring follow, which
 * keeps the error message reveal in the same component as the ring.
 */
export const SUBSCRIBE_FIELD =
  "w-full rounded-[12px] border border-border bg-surface px-3.5 py-[13px] text-[15px] font-medium transition-[border-color,box-shadow] outline-none placeholder:text-muted-foreground/70 focus:border-brand focus:ring-4 focus:ring-brand-soft";
export const SUBSCRIBE_FIELD_INVALID =
  "border-flame focus:border-flame focus:ring-danger-soft";

/** `.hint` — the muted helper text under a field. */
export const SUBSCRIBE_HINT = "mt-1.5 text-[12px] text-muted-foreground";

/** `.err` — the message revealed when a field is invalid. */
export const SUBSCRIBE_ERROR = "mt-1.5 text-[12px] font-semibold text-flame";

/** `.fld` — vertical rhythm between fields. */
export const SUBSCRIBE_FIELD_WRAP = "mb-[18px]";

/** `.row` — the mock-up's two-up field row, stacking below 600px. */
export const SUBSCRIBE_ROW =
  "grid grid-cols-1 gap-4 min-[600px]:grid-cols-2";

/** `.meter` — the four-segment password strength bar. */
export const SUBSCRIBE_METER = "mt-2 flex gap-1";
export const SUBSCRIBE_METER_SEGMENT =
  "h-1 flex-1 rounded-[4px] transition-colors duration-200";

/** `.note` — the tinted "Good to know" panel. */
export const SUBSCRIBE_NOTE =
  "rounded-[12px] bg-background px-4 py-3.5 text-[14px] text-muted-foreground";

/** `.ck` — the feature checklist, two columns above 600px. */
export const SUBSCRIBE_CHECKLIST =
  "my-6 grid grid-cols-1 gap-3 min-[600px]:grid-cols-2";

/** `.sep` — the "or" divider, drawn with rules either side. */
export const SUBSCRIBE_SEPARATOR =
  "my-5.5 flex items-center gap-3 text-[13px] text-muted-foreground before:h-px before:flex-1 before:bg-border after:h-px after:flex-1 after:bg-border";

/** `.opt` — a selectable row (payment method, plan radio). */
export const SUBSCRIBE_OPTION =
  "mb-2.5 flex cursor-pointer items-center gap-3 rounded-[12px] border border-border px-4 py-3.5 font-semibold transition-colors";

/** `.ok` — the 72px teal check disc on the confirmation step. */
export const SUBSCRIBE_SUCCESS_DISC =
  "mx-auto mb-5 grid size-[72px] place-items-center rounded-full bg-brand-soft text-brand";

/**
 * `.btn` — the mock-up's 48px pill.
 *
 * `variant` only chooses the colour treatment; the geometry is the same for
 * all three. The accent rust is used for every primary call to action, primary
 * teal is kept for the confirmation step's dashboard link, and ghost is the
 * bordered secondary.
 */
export const SUBSCRIBE_BTN =
  "inline-flex h-12 cursor-pointer items-center justify-center gap-2 rounded-[12px] px-6 text-[15px] font-bold transition-colors disabled:cursor-wait disabled:opacity-60";
export const SUBSCRIBE_BTN_ACCENT =
  "bg-flame text-white hover:bg-accent-hover";
export const SUBSCRIBE_BTN_PRIMARY =
  "bg-brand text-white hover:bg-brand-dark";
export const SUBSCRIBE_BTN_GHOST =
  "border border-border bg-surface text-foreground hover:bg-brand-soft";

const SUBSCRIBE_BTN_VARIANTS = {
  accent: SUBSCRIBE_BTN_ACCENT,
  primary: SUBSCRIBE_BTN_PRIMARY,
  ghost: SUBSCRIBE_BTN_GHOST,
} as const;

/**
 * Renders `.btn` with its base geometry and one colour treatment.
 *
 * The base lives here rather than being repeated at each call site so that a
 * colour treatment cannot be applied on its own — which is exactly how the
 * 48px height, 12px radius and 24px padding went missing from the funnel's
 * primary calls to action before this existed.
 */
export function SubscribeButton({
  variant = "accent",
  className,
  ...props
}: React.ComponentProps<"button"> & {
  variant?: keyof typeof SUBSCRIBE_BTN_VARIANTS;
}) {
  return (
    <button
      className={cn(SUBSCRIBE_BTN, SUBSCRIBE_BTN_VARIANTS[variant], className)}
      {...props}
    />
  );
}