import {
  ArrowUpDown,
  ChevronLeft,
  ChevronRight,
  Search,
  UserPlus,
} from "lucide-react";
import type { LucideIcon } from "lucide-react";
import { PHASE_CLASSES, PHASE_LABELS, type PhaseCode } from "@/lib/patients/types";
import { MAX_WEIGHT_LBS } from "@/lib/patients/weight";

/**
 * The building blocks the four Patients tabs share.
 *
 * Transcribed from the one-line helpers at the top of
 * "New Design/ChiroThin — Patients.html" — `card`, `inp`, `btnO`, `lab`, `cell`,
 * `acts`, `who`, `avatar`, `ib`, `gHead`, `gRow`, `pager`, `hd`, `empty`. The mock
 * builds its HTML by string concatenation, which is why four near-identical
 * tables could share one definition; React gets the same result from components.
 *
 * The design token names are mapped to this app's: `ink` → `foreground`, `muted` →
 * `muted-foreground`, and `accent` → `flame`. Anything left raw is called out at
 * the point of use.
 */

/** `card` — the panel every filter bar, table and aside sits in. */
export const CARD = "rounded-2xl border border-line bg-surface shadow-panel";

/** `inp` — inputs, selects and the goal-weight number box. */
export const INPUT =
  "w-full rounded-lg border border-line bg-canvas px-3.5 py-2.5 text-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary";

/** `btnO` — the quiet outlined button on the intake link strip. */
export const BUTTON_OUTLINE =
  "rounded-lg border border-line px-3 py-1.5 font-medium text-foreground/80 text-xs hover:border-primary hover:text-primary";

export const EMPTY_CLASS = "text-muted-foreground text-sm";

/**
 * A sortable column header.
 *
 * The design shows a neutral double-chevron on every sortable column and the
 * active one flips to an arrow in the primary colour, which is the only cue for
 * which column and direction are live — so both states are rendered rather than
 * left to the default browser affordance.
 */
export function SortHeader({
  label,
  active,
  ascending,
  onSort,
}: {
  label: string;
  active: boolean;
  ascending: boolean;
  onSort: () => void;
}) {
  return (
    <button
      type="button"
      onClick={onSort}
      // `aria-sort` belongs on a columnheader, and these cells are a CSS grid
      // rather than a real table, so the sort state is carried in the button's
      // accessible name instead. The visible label is kept at the front of it, so
      // it still satisfies Label in Name.
      aria-label={
        active ? `${label}, sorted ${ascending ? "ascending" : "descending"}` : `${label}, not sorted`
      }
      className={`inline-flex items-center gap-1 font-semibold tracking-wider uppercase ${
        active ? "text-foreground" : "hover:text-foreground"
      }`}
    >
      {label}
      {/* The design's `hd` swaps a neutral double-arrow for a coloured ↑/↓ once
          a column is live, so both direction and identity are always legible
          rather than relying on the browser's default affordance. */}
      {active ? (
        <span className="text-primary" aria-hidden="true">
          {ascending ? "↑" : "↓"}
        </span>
      ) : (
        <ArrowUpDown className="size-3 opacity-40" aria-hidden="true" />
      )}
    </button>
  );
}

/** A plain, non-sortable column header. */
export function PlainHeader({ children }: { children: React.ReactNode }) {
  return <span>{children}</span>;
}

/** `gHead` — the header strip. Hidden below `md`, where rows become stacked cards. */
export function GridHead({ columns, children }: { columns: string; children: React.ReactNode }) {
  return (
    <div
      className={`hidden items-center gap-4 border-b border-line bg-canvas/70 px-5 py-2.5 font-semibold text-[11px] text-muted-foreground tracking-wider uppercase md:grid ${columns}`}
    >
      {children}
    </div>
  );
}

/** `gRow` — one row. The border and hover fill live here so every table matches. */
export function GridRow({
  columns,
  children,
  selected = false,
}: {
  columns: string;
  children: React.ReactNode;
  selected?: boolean;
}) {
  return (
    <div
      className={`grid items-center gap-x-4 gap-y-2 border-b border-line px-5 py-3.5 last:border-b-0 hover:bg-primary-soft/30 ${columns} ${
        selected ? "bg-primary-soft/40" : ""
      }`}
    >
      {children}
    </div>
  );
}

/**
 * `cell` — a labelled value.
 *
 * The label is always rendered but only shown below `md`, where the grid
 * collapses and a bare value would have nothing to attach itself to. Keeping the
 * label in the DOM either way means a stacked row still says what it is.
 */
export function Cell({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div className="col-span-full flex items-center justify-between gap-3 md:col-span-1 md:block">
      <span className="text-[11px] text-muted-foreground tracking-wide uppercase md:hidden">
        {label}
      </span>
      <div className="text-sm">{children}</div>
    </div>
  );
}

/** `acts` — the row's action cluster. Right-aligned in the grid, left-aligned stacked. */
export function Actions({ children }: { children: React.ReactNode }) {
  return (
    <div className="col-span-full flex items-center gap-1 md:col-span-1 md:justify-end">
      {children}
    </div>
  );
}

/**
 * `avatar` — initials in the soft circle.
 *
 * Alternates primary and accent-soft on the id's parity, as the design does, so
 * a list of names reads as distinct rows at a glance rather than as one repeated
 * avatar. This is deliberately *not* `components/avatar.tsx`: that one is fixed to
 * `flame-soft` for the shell header and the message thread, and recolouring it
 * would change both.
 */
export function PersonAvatar({ id, name }: { id: number; name: string }) {
  const initials = name
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((part) => part[0])
    .join("")
    .toUpperCase();

  return (
    <span
      aria-hidden="true"
      className={`flex size-9 shrink-0 items-center justify-center rounded-full border border-line font-semibold text-[11px] ${
        id % 2 ? "bg-primary-soft text-primary" : "bg-flame-soft text-flame"
      }`}
    >
      {initials}
    </span>
  );
}

/** `who` — avatar, name and email, the first cell of every table. */
export function Person({ id, name, email }: { id: number; name: string; email: string }) {
  return (
    <div className="flex min-w-0 items-center gap-3">
      <PersonAvatar id={id} name={name} />
      <div className="min-w-0">
        <p className="truncate font-medium text-sm">{name}</p>
        <p className="truncate text-muted-foreground text-xs">{email}</p>
      </div>
    </div>
  );
}

/** `ib` — a square icon-only action. Always labelled; the tooltip is the only affordance. */
export function IconAction({
  icon: Icon,
  label,
  tone = "primary",
  onClick,
  disabled = false,
}: {
  icon: LucideIcon;
  label: string;
  tone?: "primary" | "flame";
  disabled?: boolean;
  onClick?: () => void;
}) {
  return (
    <button
      type="button"
      title={disabled ? "This operation requires active access." : label}
      disabled={disabled}
      aria-label={label}
      onClick={onClick}
      className={`disabled:opacity-40 flex size-8 items-center justify-center rounded-lg text-muted-foreground ${
        tone === "flame"
          ? "hover:bg-flame-soft hover:text-flame"
          : "hover:bg-primary-soft hover:text-primary"
      }`}
    >
      <Icon className="size-4" aria-hidden="true" />
    </button>
  );
}

/** `enrollBtn` — the filled action on the archived and intake tables. */
export function EnrollButton({ onClick, label, disabled = false }: { onClick?: () => void; label?: string; disabled?: boolean }) {
  return (
    <button
      type="button"
      onClick={onClick}
      disabled={disabled}
      title={disabled ? "Enrollment requires active access." : undefined}
      className="disabled:opacity-40 mr-1 inline-flex items-center gap-1.5 rounded-lg bg-primary px-3 py-1.5 font-semibold text-white text-xs hover:bg-brand-dark"
    >
      <UserPlus className="size-3.5" aria-hidden="true" />
      {label ?? "Enroll"}
    </button>
  );
}

/** The phase pill: a dot in the current colour, then the phase name. */
export function PhasePill({ phase }: { phase: PhaseCode | "" }) {
  if (!phase) return <span className="text-muted-foreground">—</span>;

  return (
    <span
      className={`inline-flex items-center gap-1.5 whitespace-nowrap rounded-full px-2.5 py-1 font-medium text-[11px] ${PHASE_CLASSES[phase]}`}
    >
      <span className="size-1.5 rounded-full bg-current" aria-hidden="true" />
      {PHASE_LABELS[phase]}
    </span>
  );
}

/**
 * A labelled control.
 *
 * Two variants, because the design uses two. `filter` is the muted `text-xs`
 * label of `lab()`, used in every filter bar. `form` is the darker `text-sm`
 * label of `fld()`, used on the Add New Patient form — where the label is the
 * thing being read to check what is being created, so it is set larger and
 * darker than a filter caption.
 *
 * The error line lives here rather than in each call site because the design's
 * `fld` reserves the space unconditionally (`<p id="e_x" class="hidden">`) so
 * the form does not reflow when a validation message appears.
 */
export function Field({
  label,
  span = "",
  variant = "filter",
  required = false,
  error,
  errorId,
  children,
}: {
  label: string;
  span?: string;
  variant?: "filter" | "form";
  required?: boolean;
  error?: string;
  errorId?: string;
  children: React.ReactNode;
}) {
  return (
    <label className={`block min-w-0 ${span}`}>
      <span
        className={
          variant === "form"
            ? "mb-1.5 block font-medium text-sm"
            : "mb-1.5 block font-medium text-muted-foreground text-xs"
        }
      >
        {label}
        {required ? (
          <span aria-hidden="true" className="text-flame">
            {" "}
            *
          </span>
        ) : null}
      </span>
      {children}
      {error ? (
        <p id={errorId} className="mt-1 text-flame text-xs">
          {error}
        </p>
      ) : null}
    </label>
  );
}

/** `searchBox` — a search input with the design's leading magnifier. */
export function SearchBox({
  id,
  placeholder,
  value,
  onChange,
}: {
  id: string;
  placeholder: string;
  value: string;
  onChange: (value: string) => void;
}) {
  return (
    <div className="relative min-w-0 flex-1">
      <Search
        className="absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
        aria-hidden="true"
      />
      <input
        id={id}
        type="text"
        value={value}
        placeholder={placeholder}
        onChange={(event) => onChange(event.target.value)}
        className={`${INPUT} pl-9`}
      />
    </div>
  );
}

/**
 * `filterBar` — the card the filters and sort controls sit in.
 *
 * A 12-column grid so each control can claim its own width at `lg` while stacking
 * cleanly on a phone, which is how the design lays out a five-control bar without
 * a wrapper per breakpoint.
 */
export function FilterBar({ children }: { children: React.ReactNode }) {
  return (
    <div className={`${CARD} mb-4 grid gap-3 p-4 sm:grid-cols-2 lg:grid-cols-12`}>
      {children}
    </div>
  );
}

/**
 * A native `<select>` carrying the design's `inp` classes.
 *
 * Not `components/ui/native-select.tsx`: that one pins itself to `h-8` with a
 * transparent background and its own chevron, which fights the design's taller
 * `py-2.5` canvas-filled control and its native arrow.
 */
export function Select({
  id,
  value,
  onChange,
  className = "",
  children,
}: {
  id: string;
  value: string;
  onChange: (value: string) => void;
  className?: string;
  children: React.ReactNode;
}) {
  return (
    <select
      id={id}
      value={value}
      onChange={(event) => onChange(event.target.value)}
      className={`${INPUT} ${className}`}
    >
      {children}
    </select>
  );
}

/** The result container the table and its pager share. */
export function ResultsCard({ children }: { children: React.ReactNode }) {
  return <div className={`${CARD} overflow-hidden`}>{children}</div>;
}

/** `empty` — the no-matches row. */
export function EmptyRow({ message }: { message: string }) {
  return <p className="p-10 text-center text-muted-foreground text-sm">{message}</p>;
}

/**
 * `pager` — "Showing 1–10 of 32" plus page buttons.
 *
 * Page numbers collapse to first / last / a window around the current page with
 * an ellipsis between, so a 26-row archived list does not print 26 buttons.
 * The current page is `aria-current` rather than colour alone.
 */
export function Pager({
  total,
  page,
  pageCount,
  perPage,
  onPage,
}: {
  total: number;
  page: number;
  pageCount: number;
  /** Rows per page, so the "Showing 1–10" range is not hardcoded to 10. */
  perPage: number;
  onPage: (page: number) => void;
}) {
  if (total === 0) return null;

  const from = (page - 1) * perPage + 1;
  const to = Math.min(page * perPage, total);

  const pages = Array.from({ length: pageCount }, (_, index) => index + 1).filter(
    (candidate) =>
      candidate === 1 || candidate === pageCount || Math.abs(candidate - page) <= 1,
  );

  return (
    <div className="flex flex-wrap items-center justify-between gap-3 border-t border-line bg-canvas/40 px-5 py-3 text-muted-foreground text-xs">
      <span>
        Showing <b className="text-foreground">{from}–{to}</b> of{" "}
        <b className="text-foreground">{total}</b>
      </span>
      <div className="flex items-center gap-1">
        <PageButton
          onClick={() => onPage(page - 1)}
          disabled={page <= 1}
          label="Previous page"
          chevron="left"
        />
        {pages.map((candidate, index) => (
          <span key={candidate} className="flex items-center">
            {index > 0 && candidate - pages[index - 1] > 1 ? (
              <span className="px-1">…</span>
            ) : null}
            <PageButton
              onClick={() => onPage(candidate)}
              label={`Page ${candidate}`}
              current={candidate === page}
            >
              {candidate}
            </PageButton>
          </span>
        ))}
        <PageButton
          onClick={() => onPage(page + 1)}
          disabled={page >= pageCount}
          label="Next page"
          chevron="right"
        />
      </div>
    </div>
  );
}

function PageButton({
  children,
  onClick,
  disabled,
  current,
  label,
  chevron,
}: {
  children?: React.ReactNode;
  onClick: () => void;
  disabled?: boolean;
  current?: boolean;
  label: string;
  chevron?: "left" | "right";
}) {
  return (
    <button
      type="button"
      onClick={onClick}
      disabled={disabled}
      aria-label={label}
      aria-current={current ? "page" : undefined}
      className={`inline-flex h-8 min-w-8 items-center justify-center gap-0.5 rounded-lg px-2.5 font-medium disabled:opacity-40 ${
        current
          ? "bg-primary text-white"
          : "text-foreground/70 hover:bg-canvas disabled:hover:bg-transparent"
      }`}
    >
      {chevron === "left" ? (
        <>
          <ChevronLeft size={14} aria-hidden="true" /> {" "}Prev
        </>
      ) : chevron === "right" ? (
        <>
          Next <ChevronRight size={14} aria-hidden="true" />
        </>
      ) : (
        children
      )}
    </button>
  );
}

/** `formatDate` — the design's `en-US` short date, at midday to dodge timezones. */
export function formatDate(iso: string): string {
  if (!iso) return "";
  return new Date(`${iso}T12:00`).toLocaleDateString("en-US", {
    month: "short",
    day: "numeric",
    year: "numeric",
  });
}

/** The design's `fmtDT` — a short date and a time, split so they can stack. */
export function formatSubmitted(value: string): { date: string; time: string } {
  const parsed = new Date(value.replace(" ", "T"));
  const date = parsed.toLocaleDateString("en-US", {
    month: "short",
    day: "numeric",
    year: "numeric",
  });
  const time = parsed.toLocaleTimeString("en-US", { hour: "numeric", minute: "2-digit" });
  return { date, time };
}

/**
 * One decimal place and a unit, or an em dash when nothing is recorded.
 *
 * Tested against `value === null` rather than truthiness, which is the whole
 * reason this is not the design's one-liner. The design wrote `value ? ... : "—"`,
 * and that collapses `0` into the em dash along with `null`: a patient whose
 * recorded starting weight is 0 lbs would be shown as "no figure", which reads
 * as a missing measurement rather than a measured one. `startWeight` is typed
 * `number | null` precisely because those are different facts, and `0` is a
 * legitimate weight here.
 */
export function formatLbs(value: number | null): string {
  return value === null ? "—" : `${value.toFixed(1)} lbs`;
}

/**
 * A labelled text input with the design's inline error line.
 *
 * `Field` above is not enough on its own, because a form needs three things it does
 * not offer: a required marker, an error slot under the control, and the error state
 * reflected on the input's own border.
 *
 * Lives here rather than in the Add New Patient form because the re-enrollment form
 * needs the same control for the same patient's name, email and phone. One
 * definition is the point: two forms editing one field record that render it two
 * ways is how a label or a validation affordance ends up disagreeing with itself.
 */
export function FormTextField({
  id,
  label,
  value,
  onChange,
  error,
  type = "text",
  required = false,
  autoComplete,
}: {
  id: string;
  label: string;
  value: string;
  onChange: (value: string) => void;
  error?: string;
  type?: "text" | "email" | "tel";
  /** Pass `false` for a field that is optional, rather than omitting it. */
  required?: boolean;
  autoComplete?: string;
}) {
  return (
    <Field
      label={label}
      variant="form"
      required={required}
      error={error}
      errorId={`${id}-error`}
    >
      <input
        id={id}
        type={type}
        value={value}
        autoComplete={autoComplete}
        onChange={(event) => onChange(event.target.value)}
        aria-invalid={Boolean(error)}
        aria-describedby={error ? `${id}-error` : undefined}
        className={`${INPUT} ${error ? "border-flame" : ""}`}
      />
    </Field>
  );
}

/**
 * A weight: the design's number-plus-suffix pair.
 *
 * The input takes the rounding off the box and the `lbs` span supplies the unit, so
 * the control reads as one field rather than a bare number.
 *
 * Values arrive and leave as strings because that is what a number input holds; the
 * caller converts to a number on the way out and must treat "" as "not recorded"
 * rather than as zero. See `formatLbs` on why zero and empty are different facts.
 */
export function FormWeightField({
  id,
  label,
  value,
  onChange,
  error,
  required = false,
}: {
  id: string;
  label: string;
  value: string;
  onChange: (value: string) => void;
  error?: string;
  required?: boolean;
}) {
  return (
    <Field
      label={label}
      variant="form"
      required={required}
      error={error}
      errorId={`${id}-error`}
    >
      <div className="flex">
        <input
          id={id}
          type="number"
          // Advisory only. These two steer the spinner and the browser's own check; a
          // number input still accepts whatever is typed into it, so the guard that
          // actually decides anything is `weightProblem()`, run on submit.
          //
          // `min` is 0, not 1, because 0 is not the same as "not recorded": the archive
          // stores a 0lb reading as a real measurement, and a floor of 1 made the
          // browser reject a typed 0 with its own tooltip before the form could say why
          // in its own words. The reasoning lives in `lib/patients/weight.ts`.
          min={0}
          max={MAX_WEIGHT_LBS}
          step="0.1"
          value={value}
          onChange={(event) => onChange(event.target.value)}
          aria-invalid={Boolean(error)}
          aria-describedby={error ? `${id}-error` : undefined}
          className={`${INPUT} rounded-r-none ${error ? "border-flame" : ""}`}
        />
        <span className="flex items-center rounded-r-lg border border-line border-l-0 bg-surface px-3 text-muted-foreground text-sm">
          lbs
        </span>
      </div>
    </Field>
  );
}

/**
 * Enabled / Disabled as a segmented pair.
 *
 * Not a checkbox: the design is explicit that both states are a deliberate choice,
 * and a checkbox renders "on" as a filled box rather than as the word Enabled.
 */
export function FormToggle({
  legend,
  value,
  onChange,
}: {
  legend: string;
  value: boolean;
  onChange: (value: boolean) => void;
}) {
  return (
    <div>
      <p className="mb-1.5 font-medium text-sm">{legend}</p>
      <div
        role="radiogroup"
        aria-label={legend}
        className="inline-flex rounded-lg border border-line bg-canvas p-0.5 font-medium text-sm"
      >
        {[
          { on: true, label: "Enabled" },
          { on: false, label: "Disabled" },
        ].map((option) => (
          <button
            key={option.label}
            type="button"
            role="radio"
            aria-checked={value === option.on}
            onClick={() => onChange(option.on)}
            className={`rounded-md px-4 py-1.5 ${
              value === option.on
                ? "bg-primary text-white shadow-panel"
                : "text-muted-foreground hover:text-foreground"
            }`}
          >
            {option.label}
          </button>
        ))}
      </div>
    </div>
  );
}