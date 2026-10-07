/**
 * Shared styling for the clinic page.
 *
 * Transcribed from the CSS block and class strings of
 * `New Design/ChiroThin — My Clinic Page.html` — `.field`, `.field.bad`, `.lbl`,
 * `.err`, `.btn` and the `card`/`tabs`/`stats` panel classes. The mock-up is one
 * stylesheet plus a string-building script, so the same measurements are expressed
 * here as Tailwind utilities, exactly as `subscribe-primitives.tsx` does for the
 * subscribe flow and `patients-ui.tsx` does for the patients tables.
 *
 * The design's token names are mapped to this app's: `ink` → `foreground`, `muted` →
 * `muted-foreground`, `accent` → `flame`, and `primary.dark` → `brand-dark`. The
 * primary/canvas/surface/line names already exist here unchanged.
 */

/**
 * `.btn` — the mock-up's shared button geometry.
 *
 * `radius:.75rem; padding:.6rem 1rem; font-size:.875rem; font-weight:600` plus the
 * flex centring and the `.5rem` icon gap. Every button on the page is this plus one
 * colour treatment, so the treatment is always passed alongside the base rather than
 * instead of it.
 */
export const BTN =
  "inline-flex items-center justify-center gap-2 rounded-xl px-4 py-2.5 text-sm font-semibold transition-colors disabled:opacity-40";

/** `.btn` at the table-row size — the `!px-3 !py-1.5` overrides in the mock-up. */
export const BTN_ROW =
  "inline-flex items-center justify-center gap-2 rounded-xl border px-3 py-1.5 text-sm font-semibold transition-colors disabled:opacity-40";

/** The bordered secondary treatment, used by Cancel, the filter-less buttons and Edit. */
export const BTN_OUTLINE =
  "border border-line text-foreground hover:bg-canvas";

/** `.btn` as the page's primary call to action — Add chiropractor, Add location, Save. */
export const BTN_PRIMARY =
  "bg-primary text-white shadow-sm hover:bg-brand-dark";

/**
 * The `.btn bg-accent text-white hover:opacity-90` treatment, used by the block
 * dialog's confirm button in both directions.
 *
 * The design keeps the accent rust for unblocking as well as blocking rather than
 * swapping to the primary teal, which reads as one consistent "this is the
 * destructive-family action" colour for the row.
 */
export const BTN_ACCENT = "bg-flame text-white hover:opacity-90";

/** `card` — the white rounded panel the tables, filter bar and stats sit in. */
export const CARD = "rounded-2xl border border-line bg-surface shadow-panel";

/**
 * `.field` — inputs and selects.
 *
 * `border-radius:.75rem; padding:.7rem .9rem; font-size:.875rem` with the white
 * background, and the focus treatment the mock-up writes as
 * `border-color:#0B5D52; box-shadow:0 0 0 4px #E4EEEC`. The invalid treatment
 * (`.field.bad`) is a separate constant rather than a modifier, because React needs
 * the invalid ring to follow the value and cannot rely on a sibling selector.
 */
export const FIELD =
  "w-full rounded-xl border border-line bg-surface px-3.5 py-2.5 text-sm text-foreground transition-[border-color,box-shadow] placeholder:text-muted-foreground/70 focus:border-primary focus:ring-4 focus:ring-primary-soft focus:outline-none";

/** `.field.bad` — `#C7522A` border with the `#F6E4DC` focus ring. */
export const FIELD_BAD = "border-flame focus:border-flame focus:ring-flame-soft";

/** `.lbl` — `margin-bottom:.4rem; font-size:.75rem; font-weight:600`. */
export const LABEL = "mb-1.5 block text-xs font-semibold text-foreground";

/**
 * `.err` — the message under an invalid field.
 *
 * The mock-up reveals it with a sibling selector (`.bad ~ .err { display:block }`),
 * which is why the markup puts the message after the control. React renders it
 * conditionally instead and the `role="alert"` carries the announcement, which the
 * CSS-only version had no way to do.
 */
export const FIELD_ERROR = "mt-1.5 text-xs font-semibold text-flame";

/** The muted helper line under a field — the mock-up's optional `hint` argument. */
export const FIELD_HINT = "mt-1.5 text-xs text-muted-foreground";

/**
 * The table header treatment the mock-up applies to both tables:
 * `bg-canvas/70 text-[11px] uppercase tracking-wide text-muted`.
 */
export const TABLE_HEAD =
  "bg-canvas/70 text-[11px] font-semibold tracking-wide text-muted-foreground uppercase";

/** A body row's hover fill, `hover:bg-canvas/50`. */
export const ROW_HOVER = "transition-colors hover:bg-canvas/50";