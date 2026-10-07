"use client";

import { useEffect, useMemo, useRef, useState } from "react";
import { useAuth } from "@/lib/auth";
import { resolvePortalAccess } from "@/lib/portal";
import {
  generateIntakeLink,
  regenerateIntakeLink,
  reviewIntakeSubmissions,
} from "@/lib/patients/api";
import { Check, CheckCircle2, Copy, Eye, Link2, RefreshCw, Trash2 } from "lucide-react";
import {
  Actions,
  CARD,
  Cell,
  EmptyRow,
  EnrollButton,
  Field,
  FilterBar,
  GridHead,
  GridRow,
  IconAction,
  Pager,
  Person,
  PlainHeader,
  ResultsCard,
  SearchBox,
  Select,
  SortHeader,
  formatDate,
  formatSubmitted,
} from "@/components/portal/patients/patients-ui";
import type { IntakeRow, IntakeStatus } from "@/lib/patients/types";
import { RegenerateIntakeLinkDialog } from "@/components/portal/patients/regenerate-intake-link-dialog";

/**
 * Intake Forms — submissions collected through the clinic's public intake link.
 *
 * The design's `viewIntake` / `intakeRows` / `intakeAct`. The busiest of the four
 * tabs: a shareable link to copy, a review workflow over a checkbox selection,
 * and an eight-column table.
 *
 * ## What is real here and what is not
 *
 * **Real:** search across name, email and phone; the review-status filter; all
 * four sort columns; paging; the select-page and select-all-results mechanics;
 * and the copy-to-clipboard on the link.
 *
 * Review toggles and bulk actions persist through the intake review endpoint.
 * Rows and counts update in shared client state after Drupal confirms the save,
 * matching message read receipts without refreshing the page or moving the table.
 *
 * **Enroll is live, and it opens the Add form.** It does not submit anything itself:
 * the row is handed to `patients-page.tsx`, which switches to the Add New Patient tab
 * with the form filled in from the submission, start date included, and says so at
 * the top of the form. That patient then goes through the same form and the same
 * `POST /api/patients` as one typed in by hand, which is the point — there is one
 * form with two ways to open it, not two forms.
 *
 * **View enroll information and Delete enroll are live**, and both live in the page
 * rather than here: this tab hands the row up and `patients-page.tsx` owns the view
 * and the confirm dialog, so neither is unmounted by a tab switch. View reads the
 * submission through `GET /api/patients/intake/{id}`; Delete goes through a
 * confirm dialog and `DELETE` on the same path, which is a hard delete — see
 * `DeleteIntakeDialog` for why it asks twice in as many words.
 *
 * ## The clinic's own intake link
 *
 * A clinic has exactly one permanent intake link, and it has three states: none yet,
 * present, or present and being replaced. The backend models all three
 * (`ClinicIntakeLinkController`), and this strip is where they are told apart.
 *
 * The link is a plain string on the server snapshot, and `''` means "this clinic has
 * not generated one" — `PatientIntakeService::intakeLink()` returns exactly that when
 * there is no token. That is not a failure and must not render like one: it used to be
 * drawn as an empty box next to a Copy button that copied nothing, which reads as a
 * broken page rather than as a clinic that has not set itself up. An empty link now
 * gets an explanation and the button that fixes it.
 *
 * `onIntakeLinkChange` hands the new URL up to the page rather than keeping it here,
 * because this tab is unmounted by a tab switch and a link generated on this tab should
 * still be there when the chiropractor comes back to it.
 */

const COLUMNS =
  "grid-cols-[28px_minmax(0,1fr)] md:grid-cols-[28px_minmax(0,2.2fr)_1.1fr_.6fr_1.3fr_1fr_.9fr_auto]";

/** The design hardcodes `accent-[#0B5D52]` on its checkboxes — the same hex as its `--primary`. */
const CHECKBOX = "size-4 accent-[#0B5D52]";

type SortKey = "sub" | "name" | "start" | "goal";

const SORT_OPTIONS: [SortKey, string][] = [
  ["sub", "Date submitted"],
  ["name", "Full name"],
  ["start", "Program start date"],
  ["goal", "Goal weight"],
];

const PER_PAGE = 10;

export function IntakeTab({
  rows,
  intakeLink,
  onIntakeLinkChange,
  onEnroll,
  onView,
  onDelete,
  onReviewed,
}: {
  rows: IntakeRow[];
  /**
   * The clinic's intake link, or `''` when it has not generated one.
   *
   * Empty is a real state, not a loading one: the server snapshot carries `''` from
   * `PatientIntakeService::intakeLink()` when the clinic has no token, and it is drawn
   * as "not set up yet" with the button that fixes it.
   */
  intakeLink: string;
  /**
   * Reports a link this tab generated or replaced.
   *
   * Held by the page rather than in local state because this tab unmounts on a tab
   * switch, and a chiropractor who generates a link, visits another tab and comes back
   * should find it there.
   */
  onIntakeLinkChange: (url: string) => void;
  /**
   * Opens the Add-patient form filled in from this row.
   *
   * Not a submit: Enroll here hands the row to the page, which switches to the Add tab
   * prefilled — start date included — so the chiropractor confirms what the patient
   * sent before anything is created. There is no second form and no second endpoint
   * for this path; it is the same form and the same POST as adding a patient by hand.
   */
  onEnroll: (row: IntakeRow) => void;
  /** Opens the read-only view of one submission. Held by the page, not here. */
  onView?: (row: IntakeRow) => void;
  /** Opens the delete confirmation for one submission. Held by the page, not here. */
  onDelete?: (row: IntakeRow) => void;
  onReviewed: (ids: number[], status: IntakeStatus) => void;
}) {
  const items = rows;
  const { user } = useAuth();
  const readOnly = resolvePortalAccess(user?.roles, user?.capabilities, user?.portalAccess)?.readOnly ?? true;
  const [saving, setSaving] = useState(false);
  const savingRef = useRef(false);
  const [reviewError, setReviewError] = useState<string | null>(null);
  const [query, setQuery] = useState("");
  const [status, setStatus] = useState<IntakeStatus | "">("");
  const [sortKey, setSortKey] = useState<SortKey>("sub");
  const [ascending, setAscending] = useState(false);
  const [page, setPage] = useState(1);
  const [selected, setSelected] = useState<Set<number>>(new Set());
  const [allSelected, setAllSelected] = useState(false);
  const [copied, setCopied] = useState(false);
  const [linkBusy, setLinkBusy] = useState<"create" | "regenerate" | null>(null);
  const [linkError, setLinkError] = useState<string | null>(null);
  const [confirmRegenerate, setConfirmRegenerate] = useState(false);

  const filtered = useMemo(() => {
    const needle = query.trim().toLowerCase();
    const direction = ascending ? 1 : -1;

    return items
      .filter(
        (row) =>
          (!status || row.status === status) &&
          (!needle ||
            `${row.name}${row.email}${row.phone}`.toLowerCase().includes(needle)),
      )
      .sort((a, b) => {
        if (sortKey === "name") return a.name.localeCompare(b.name) * direction;
        // `submitted` is `YYYY-MM-DD HH:mm`, already zero-padded and sortable
        // as a string — no `Date` needed to order submissions.
        if (sortKey === "sub") return a.submitted.localeCompare(b.submitted) * direction;
        if (sortKey === "start") return a.programStart.localeCompare(b.programStart) * direction;
        return (a.goalWeight - b.goalWeight) * direction;
      });
  }, [items, query, status, sortKey, ascending]);

  const pageCount = Math.max(1, Math.ceil(filtered.length / PER_PAGE));
  const currentPage = Math.min(page, pageCount);
  const slice = filtered.slice((currentPage - 1) * PER_PAGE, currentPage * PER_PAGE);

  const isSelected = (id: number) => allSelected || selected.has(id);
  const selectionCount = allSelected ? filtered.length : selected.size;
  const pageAllSelected =
    slice.length > 0 && slice.every((row) => isSelected(row.id));

  // Ticking or unticking anything invalidates a select-all, which by definition
  // means "everything", not "everything except that one".
  function setRowSelected(id: number, checked: boolean) {
    setAllSelected(false);
    setSelected((current) => {
      const next = new Set(current);
      if (checked) next.add(id);
      else next.delete(id);
      return next;
    });
  }

  function setPageSelected(checked: boolean) {
    setAllSelected(false);
    setSelected((current) => {
      const next = new Set(current);
      for (const row of slice) {
        if (checked) next.add(row.id);
        else next.delete(row.id);
      }
      return next;
    });
  }

  function clearSelection() {
    setAllSelected(false);
    setSelected(new Set());
  }

  async function saveReview(ids: number[], next: IntakeStatus, clear = false) {
    if (ids.length === 0 || savingRef.current || readOnly) return;
    savingRef.current = true;
    setSaving(true);
    setReviewError(null);
    try {
      const result = await reviewIntakeSubmissions(ids, next);
      const accepted = [...result.updated, ...result.unchanged];
      onReviewed(accepted, next);
      if (clear && result.rejected.length === 0) clearSelection();
      if (result.rejected.length > 0) {
        setReviewError(`${result.rejected.length} submission(s) could not be updated in your clinic.`);
      }
    } catch (error) {
      setReviewError(error instanceof Error ? error.message : "Unable to save intake review state.");
    } finally {
      savingRef.current = false;
      setSaving(false);
    }
  }

  function markSelected(next: IntakeStatus) {
    const ids = allSelected ? filtered.map((row) => row.id) : [...selected];
    void saveReview(ids, next, true);
  }

  function toggleStatus(id: number) {
    const row = items.find((item) => item.id === id);
    if (row) void saveReview([id], row.status === "new" ? "checked" : "new");
  }

  function toggleSort(key: SortKey) {
    if (key === sortKey) {
      setAscending((current) => !current);
    } else {
      setSortKey(key);
      setAscending(true);
    }
    setPage(1);
  }

  async function copyLink() {
    try {
      await navigator.clipboard.writeText(intakeLink);
      setCopied(true);
    } catch {
      // Clipboard access needs a secure context and permission. The link is on
      // screen and selectable, so failing quietly is better than an alert the
      // design has no room for.
      setCopied(false);
    }
  }

  useEffect(() => {
    if (!copied) return;
    const timer = window.setTimeout(() => setCopied(false), 2000);
    return () => window.clearTimeout(timer);
  }, [copied]);

  /**
   * Generates the clinic's intake link.
   *
   * Gated on `readOnly` because that is what the backend admits: a doctor is created
   * `chiropractor_inactive_` and becomes `chiropractor_active_` only once a
   * subscription grants portal write, and `ManageIntakeLinkAccess` allows the active
   * role. Showing a button that answers 403 would be worse than showing none — but the
   * reason is stated in place, so a read-only chiropractor is told it is their account
   * rather than left guessing.
   *
   * Not optimistic: the URL is handed up only after the endpoint confirms it.
   */
  async function generateLink() {
    if (linkBusy || readOnly) return;
    setLinkBusy("create");
    setLinkError(null);
    try {
      onIntakeLinkChange(await generateIntakeLink());
    } catch (error) {
      setLinkError(
        error instanceof Error ? error.message : "Unable to generate your intake link.",
      );
    } finally {
      setLinkBusy(null);
    }
  }

  /**
   * Replaces the link. Only reached through the confirmation, which stays open for the
   * duration so a failure is reported against the dialog rather than a link that looks
   * replaced but is not.
   */
  async function replaceLink() {
    setLinkError(null);
    try {
      onIntakeLinkChange(await regenerateIntakeLink());
    } catch (error) {
      setLinkError(
        error instanceof Error ? error.message : "Unable to regenerate your intake link.",
      );
      // Left open on failure so the reason is read while the request that caused it is
      // still on screen.
      return;
    }
    setConfirmRegenerate(false);
  }

  // Changing the filters or sort changes what "select all results" means, so
  // the selection is dropped rather than left pointing at hidden rows.
  function resetAndClear(update: () => void) {
    update();
    setPage(1);
    clearSelection();
  }

  return (
    <>
      {/* The clinic's own intake link. Three states, not one: no link yet, a link, and
          a link being replaced. The first used to render as an empty box beside a Copy
          button that copied nothing, which reads as a broken page rather than as a
          clinic that has not generated one yet. */}
      <div className={`${CARD} mb-4 p-3 pl-4`}>
        <div className="flex flex-wrap items-center gap-3">
          <span className="font-medium text-muted-foreground text-xs">Intake link</span>

          {intakeLink ? (
            <>
              <code className="min-w-0 flex-1 truncate rounded-md bg-canvas px-3 py-1.5 text-xs">
                {intakeLink}
              </code>
              <button
                type="button"
                onClick={copyLink}
                className="inline-flex items-center gap-1.5 rounded-lg border border-line px-3 py-1.5 font-medium text-foreground/80 text-xs hover:border-primary hover:text-primary"
              >
                {copied ? (
                  <Check className="size-3.5" aria-hidden="true" />
                ) : (
                  <Copy className="size-3.5" aria-hidden="true" />
                )}
                {copied ? "Copied" : "Copy link"}
              </button>
              {/* Only for a writable account, and always behind a confirmation: the old
                  URL stops working the moment this succeeds. */}
              {!readOnly ? (
                <button
                  type="button"
                  onClick={() => setConfirmRegenerate(true)}
                  className="inline-flex items-center gap-1.5 rounded-lg border border-line px-3 py-1.5 font-medium text-foreground/80 text-xs hover:border-flame hover:text-flame"
                >
                  <RefreshCw className="size-3.5" aria-hidden="true" />
                  Replace link
                </button>
              ) : null}
            </>
          ) : (
            <>
              <p className="min-w-0 flex-1 text-xs text-muted-foreground">
                {readOnly
                  ? "No intake link has been generated for this clinic yet. Generating one needs an active chiropractor account."
                  : "No intake link has been generated for this clinic yet. Generate one to share with patients."}
              </p>
              {!readOnly ? (
                <button
                  type="button"
                  onClick={() => void generateLink()}
                  disabled={linkBusy !== null}
                  aria-busy={linkBusy === "create"}
                  className="inline-flex shrink-0 items-center gap-1.5 rounded-lg bg-primary px-3 py-1.5 font-semibold text-white text-xs hover:bg-primary-dark disabled:opacity-60"
                >
                  <Link2 className="size-3.5" aria-hidden="true" />
                  {linkBusy === "create" ? "Generating…" : "Generate intake link"}
                </button>
              ) : null}
            </>
          )}
        </div>

        {/* `role="alert"` so a refusal is announced: the common failure here is a 403
            from an account the policy does not admit, and it would otherwise look like
            the button did nothing. */}
        {linkError ? (
          <p
            role="alert"
            className="mt-3 rounded-lg border border-destructive/30 bg-surface px-3 py-2 text-xs text-destructive"
          >
            {linkError}
          </p>
        ) : null}
      </div>

      <RegenerateIntakeLinkDialog
        open={confirmRegenerate}
        onConfirm={replaceLink}
        onClose={() => setConfirmRegenerate(false)}
      />

      <FilterBar>
        <Field label="Search" span="lg:col-span-6">
          <SearchBox
            id="intake-search"
            placeholder="Search by name, email or phone..."
            value={query}
            onChange={(value) => resetAndClear(() => setQuery(value))}
          />
        </Field>
        <Field label="Review status" span="lg:col-span-2">
          <Select
            id="intake-status"
            value={status}
            onChange={(value) =>
              resetAndClear(() => setStatus(value as IntakeStatus | ""))
            }
          >
            <option value="">All submissions</option>
            <option value="new">New</option>
            <option value="checked">Checked</option>
          </Select>
        </Field>
        <Field label="Sort by" span="lg:col-span-2">
          <Select
            id="intake-sort"
            value={sortKey}
            onChange={(value) => toggleSort(value as SortKey)}
          >
            {SORT_OPTIONS.map(([value, label]) => (
              <option key={value} value={value}>
                {label}
              </option>
            ))}
          </Select>
        </Field>
        <Field label="Order" span="lg:col-span-2">
          <Select
            id="intake-order"
            value={ascending ? "1" : "-1"}
            onChange={(value) => {
              setAscending(value === "1");
              setPage(1);
            }}
          >
            <option value="1">Ascending</option>
            <option value="-1">Descending</option>
          </Select>
        </Field>
      </FilterBar>

      {reviewError && (
        <p role="alert" className="mb-4 rounded-lg border border-destructive/30 bg-surface px-4 py-3 text-sm text-destructive">
          {reviewError}
        </p>
      )}
      <span role="status" aria-live="polite" className="sr-only">
        {saving ? "Saving review state…" : ""}
      </span>

      <ResultsCard>
        {/* The design swaps these two bars: a hint when nothing is ticked, a
            count and the mark-as actions once something is. */}
        {selectionCount > 0 ? (
          <div className="flex min-h-16 flex-wrap items-center gap-3 border-primary/15 border-b bg-primary-soft px-5 py-3">
            <span className="inline-flex items-center gap-2 font-semibold text-primary text-sm">
              <span className="flex h-6 min-w-6 items-center justify-center rounded-full bg-primary px-1.5 font-semibold text-xs tabular-nums text-white">
                {selectionCount}
              </span>
              selected
            </span>
            <button
              type="button"
              onClick={clearSelection}
              className="inline-flex items-center gap-1 font-medium text-primary/70 text-xs hover:text-primary"
            >
              Clear
            </button>
            <div className="flex-1" />
            <div className="inline-flex overflow-hidden rounded-lg border-primary/25 border bg-surface shadow-sm">
              <button
                type="button"
                onClick={() => markSelected("checked")}
                disabled={saving || readOnly}
                className="inline-flex cursor-pointer items-center gap-1.5 px-3.5 py-2 disabled:cursor-pointer disabled:opacity-50 font-semibold text-primary text-xs hover:bg-primary hover:text-white"
              >
                <Check className="size-3.5" aria-hidden="true" />
                Mark as checked
              </button>
              <span className="w-px bg-primary/25" />
              <button
                type="button"
                onClick={() => markSelected("new")}
                disabled={saving || readOnly}
                className="inline-flex cursor-pointer items-center gap-1.5 px-3.5 py-2 disabled:cursor-pointer disabled:opacity-50 font-semibold text-flame text-xs hover:bg-flame hover:text-white"
              >
                <span className="size-2 rounded-full bg-current" aria-hidden="true" />
                Mark as new
              </button>
            </div>
          </div>
        ) : (
          <div className="flex min-h-16 items-center gap-2 border-line border-b px-5 py-3 text-muted-foreground text-xs">
            <CheckCircle2 className="size-3.5" aria-hidden="true" />
            Tick submissions to mark them as checked or new.
            <span className="ml-auto tabular-nums">{filtered.length} results</span>
          </div>
        )}

        {/* Only worth saying once every row on the page is ticked *and* there are
            rows on other pages — otherwise the checkbox already tells you. */}
        {pageAllSelected && filtered.length > slice.length ? (
          <div className="border-line border-b bg-canvas/70 px-5 py-2 text-muted-foreground text-xs">
            {allSelected
              ? `All ${filtered.length} results are selected.`
              : `All ${slice.length} on this page are selected.`}{" "}
            <button
              type="button"
              onClick={() => (allSelected ? clearSelection() : setAllSelected(true))}
              className="font-semibold text-primary underline"
            >
              {allSelected ? "Clear selection" : `Select all ${filtered.length} results`}
            </button>
          </div>
        ) : null}

        <GridHead columns={COLUMNS}>
          <input
            type="checkbox"
            aria-label="Select page"
            className={CHECKBOX}
            checked={pageAllSelected}
            onChange={(event) => setPageSelected(event.target.checked)}
          />
          <SortHeader
            label="Patient"
            active={sortKey === "name"}
            ascending={ascending}
            onSort={() => toggleSort("name")}
          />
          <PlainHeader>Phone</PlainHeader>
          <SortHeader
            label="Goal"
            active={sortKey === "goal"}
            ascending={ascending}
            onSort={() => toggleSort("goal")}
          />
          <SortHeader
            label="Submitted"
            active={sortKey === "sub"}
            ascending={ascending}
            onSort={() => toggleSort("sub")}
          />
          <SortHeader
            label="Program start"
            active={sortKey === "start"}
            ascending={ascending}
            onSort={() => toggleSort("start")}
          />
          <PlainHeader>Status</PlainHeader>
          <PlainHeader>
            <span className="sr-only">Actions</span>
          </PlainHeader>
        </GridHead>

        {slice.length === 0 ? (
          <EmptyRow message="No intake forms match your filters." />
        ) : (
          slice.map((row) => {
            const on = isSelected(row.id);
            const submitted = formatSubmitted(row.submitted);

            return (
              <GridRow key={row.id} columns={COLUMNS} selected={on}>
                <input
                  type="checkbox"
                  className={`${CHECKBOX} col-start-1 row-start-1`}
                  aria-label={`Select ${row.name}`}
                  checked={on}
                  onChange={(event) => setRowSelected(row.id, event.target.checked)}
                />
                <div className="col-span-full md:col-span-1">
                  <Person id={row.id} name={row.name} email={row.email} />
                </div>
                <Cell label="Phone">
                  <span className="whitespace-nowrap text-foreground/80">{row.phone}</span>
                </Cell>
                <Cell label="Goal">
                  {/* Whole pounds: the intake form asks for a target, not a
                      measurement, so the design drops the decimal. A blank
                      field came through as 0 and reads as a dash, not "0 lbs". */}
                  <span className="tabular-nums">
                    {row.goalWeight ? `${row.goalWeight.toFixed(0)} lbs` : "—"}
                  </span>
                </Cell>
                <Cell label="Submitted">
                  <p className="whitespace-nowrap">{submitted.date}</p>
                  <p className="text-muted-foreground text-xs">{submitted.time}</p>
                </Cell>
                <Cell label="Program start">
                  <span className="whitespace-nowrap">{formatDate(row.programStart)}</span>
                </Cell>
                <Cell label="Status">
                  <button
                    type="button"
                    onClick={() => toggleStatus(row.id)}
                    disabled={readOnly}
                    aria-disabled={saving || readOnly}
                    aria-busy={saving}
                    style={{ cursor: 'pointer' }}
                    aria-label={`Mark ${row.name} as ${row.status === "new" ? "checked" : "new"}`}
                    title={readOnly ? "Review is unavailable on a read-only account." : "Click to toggle"}
                    className={`inline-flex h-6 w-20 shrink-0 cursor-pointer items-center justify-center gap-1.5 whitespace-nowrap rounded-full px-2.5 transition-colors disabled:cursor-pointer font-semibold text-[11px] ${
                      row.status === "new"
                        ? "bg-flame-soft text-flame"
                        : "bg-primary-soft text-primary"
                    }`}
                  >
                    {row.status === "new" ? (
                      <>
                        <span className="size-1.5 rounded-full bg-current" aria-hidden="true" />
                        New
                      </>
                    ) : (
                      <>
                        <Check className="size-3" aria-hidden="true" />
                        Checked
                      </>
                    )}
                  </button>
                </Cell>
                <Actions>
                  <EnrollButton onClick={() => onEnroll(row)} label="Enroll" disabled={readOnly} />
                  <IconAction
                    icon={Eye}
                    label="View enroll information"
                    onClick={() => onView?.(row)}
                  />
                  {/* `flame` tone: the design's destructive accent. Reading and
                      destroying sit next to each other here, so they cannot both be
                      the quiet outlined button and look equally safe. */}
                  <IconAction
                    icon={Trash2}
                    label="Delete enroll"
                    disabled={readOnly}
                    tone="flame"
                    onClick={() => onDelete?.(row)}
                  />
                </Actions>
              </GridRow>
            );
          })
        )}

        <Pager
          total={filtered.length}
          page={currentPage}
          pageCount={pageCount}
          perPage={PER_PAGE}
          onPage={setPage}
        />
      </ResultsCard>
    </>
  );
}