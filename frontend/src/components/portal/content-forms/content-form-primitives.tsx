import { cn } from "cn";
import type { ReactNode } from "react";

/**
 * Shared styling for the "Add a …" dialogs (recipe, resource, training).
 *
 * Transcribed verbatim from `New Design/ChiroThin — Add a recipe form.html` —
 * its `.field`, `.field.bad`, `.lbl`, `.hint`, `.err`, `.chip`, the modal shell,
 * the header row, the footer, and the button treatments. The mock-up is one
 * stylesheet plus a string-building script, so the same measurements are
 * expressed here as Tailwind utilities, exactly as `clinic-primitives.ts` does
 * for the clinic page and `subscribe-primitives.tsx` does for the subscribe
 * flow.
 *
 * The design's token names are mapped to this app's: `ink` → `foreground`,
 * `muted` → `muted-foreground`, `accent` → `flame` (and `accent.soft` →
 * `flame-soft`), `primary.dark` → `brand-dark`, `primary.soft` → `primary-soft`.
 * The primary/canvas/surface/line names already exist here unchanged; the
 * chip's unselected label colour `#374151` is the one value with no palette
 * token, so it is kept as an arbitrary value.
 */

/**
 * `DialogContent` geometry for the design's modal.
 *
 * The design is a bottom sheet on phones (full width, rounded top corners,
 * anchored to the bottom edge) and a centred panel from `sm` up
 * (`items-center p-4`, so `inset:0` plus margin auto). The base `DialogContent`
 * centring and slide-from-centre animation are overridden like the clinic
 * drawer does for its right-hand panel.
 */
export const SHEET =
  "fixed inset-x-0 bottom-0 top-auto z-50 flex max-h-[94vh] w-full max-w-2xl translate-x-0 translate-y-0 flex-col gap-0 rounded-t-3xl border-0 bg-surface p-0 shadow-2xl sm:inset-0 sm:mx-auto sm:my-auto sm:rounded-2xl data-[state=open]:slide-in-from-bottom-full data-[state=open]:slide-in-from-left-0 data-[state=open]:slide-in-from-top-0 data-[state=open]:zoom-in-100 data-[state=closed]:slide-out-to-bottom-full data-[state=closed]:slide-out-to-left-0 data-[state=closed]:slide-out-to-top-0 data-[state=closed]:zoom-out-100";

/** The design's `#ov` backdrop — `bg-ink/40 backdrop-blur-[2px]`. */
export const OVERLAY = "bg-foreground/40 backdrop-blur-[2px]";

/** The modal's bordered header row, with the serif title at the left. */
export const HEADER =
  "flex items-start justify-between gap-4 border-b border-line px-6 py-5 sm:px-8";

/** The scrollable field column inside the form. */
export const BODY = "min-h-0 flex-1 space-y-7 overflow-y-auto px-6 py-6 sm:px-8";

/** The bordered footer that holds Cancel and the submit action. */
export const FOOTER =
  "flex items-center justify-end gap-3 border-t border-line bg-surface px-6 py-4 sm:px-8";

/** `.lbl` — `display:block; font-size:.8125rem; font-weight:600`. */
export const LABEL = "mb-1.5 block text-[13px] font-semibold text-foreground";

/** The design's required-field marker, `text-accent` → flame. */
export const REQUIRED = <span className="text-flame">*</span>;

/**
 * `.field` — inputs, textareas and selects.
 *
 * `border-radius:.75rem; padding:.7rem .9rem; font-size:.875rem` with the white
 * background, and the focus treatment the mock-up writes as
 * `border-color:#0B5D52; box-shadow:0 0 0 4px #E4EEEC`. The invalid treatment
 * (`.field.bad`) is a separate constant rather than a modifier, because React
 * needs the invalid ring to follow the value and cannot rely on a sibling
 * selector.
 */
export const FIELD =
  "w-full rounded-xl border border-line bg-surface px-3.5 py-2.5 text-sm text-foreground transition-[border-color,box-shadow] placeholder:text-muted-foreground/70 focus:border-primary focus:ring-4 focus:ring-primary-soft focus:outline-none";

/** `.field.bad` — `#C7522A` border with the `#F6E4DC` focus ring. */
export const FIELD_BAD = "border-flame focus:border-flame focus:ring-flame-soft";

/** `.hint` — the muted helper line under a field label. */
export const HINT = "text-xs text-muted-foreground";

/** `.err` — the message under an invalid field, in the acid-accent. */
export const FIELD_ERROR = "mt-1.5 text-xs text-flame";

/** `.chip` — the pill pickers for categories and types. */
export const CHIP =
  "inline-flex items-center gap-1.5 rounded-full border border-line bg-surface px-3.5 py-1.5 text-[13px] font-medium text-[#374151] transition-colors hover:border-primary disabled:cursor-not-allowed disabled:opacity-40";

/** `.chip[aria-pressed=true]` — selected, teal fill with a check. */
export const CHIP_ON = "border-primary bg-primary text-white hover:border-primary";

/** The designer's Cancel button. */
export const BTN_CANCEL =
  "rounded-xl border border-line px-5 py-2.5 text-sm font-semibold text-foreground transition-colors hover:bg-canvas";

/** `.btn` as the form's submit action. */
export const BTN_SUBMIT =
  "inline-flex items-center justify-center gap-2 rounded-xl bg-primary px-6 py-2.5 text-sm font-semibold text-white shadow-sm transition-colors hover:bg-brand-dark disabled:pointer-events-none disabled:opacity-40";

/** The form-level alert for a failed submit (backend 400 / network). */
export const FORM_ERROR = "rounded-lg bg-flame-soft p-3 text-sm text-flame";

/**
 * One pill in a category/type picker row, with the design's check that appears
 * only once the chip is pressed. `max`-aware rows disable the unselected chips
 * at capacity through `disabled`.
 */
export function FormChip({
  checked,
  disabled,
  onClick,
  children,
}: {
  checked: boolean;
  disabled?: boolean;
  onClick: () => void;
  children: ReactNode;
}) {
  return (
    <button
      type="button"
      aria-pressed={checked}
      disabled={disabled}
      onClick={onClick}
      className={cn(CHIP, checked && CHIP_ON)}
    >
      <svg
        className={cn("size-3 shrink-0", checked ? "block" : "hidden")}
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        strokeWidth={3}
        aria-hidden="true"
      >
        <path d="m5 12 5 5 9-10" />
      </svg>
      {children}
    </button>
  );
}