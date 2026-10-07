"use client";

import { useEffect, useId, useState } from "react";
import { ChevronDown, Flag, Loader2, MoreHorizontal } from "lucide-react";
import { Button } from "@/components/ui/button";
import { useProgressiveList } from "@/hooks/use-progressive-list";
import { useAuth } from "@/lib/auth";
import { resolvePortalAccess } from "@/lib/portal";
import { EditEntryDialog } from "@/components/portal/progress/edit-entry-dialog";
import { DeleteEntryDialog } from "@/components/portal/progress/delete-entry-dialog";
import type { ProgressEntry } from "@/lib/progress/types";

/** How many rows are rendered on first paint, and added per scroll. */
const PAGE_SIZE = 10;

/**
 * "Log history" — the newest-first list of daily entries, each expandable.
 *
 * Transcribed from "New Design/ChiroThin — My Progress.html": the most recent
 * entry starts open, the summary row carries a date chip, the day number, an
 * adherence pill and a "Flagged" pill when there is a note, and the expanded body
 * splits into Details / Food / Flags & Notes.
 *
 * ## One deliberate departure from the design
 *
 * The design makes the whole summary row a click target and nests the per-entry
 * menu button *inside* it. Two `<button>` elements cannot nest — it is invalid
 * HTML, and it makes the inner control unreachable by keyboard because the outer
 * one takes the focus. So the row is the toggle button and the entry menu sits
 * beside it in its own cell. The row is still fully clickable, which is the part
 * that matters; only the two controls swap order, by a few pixels.
 *
 * ## Read-only
 *
 * Edit and Delete are operations, so both are disabled for `archived_patient`
 * accounts, which `lib/portal.ts` defines as able to view and download their own
 * data but perform no operations. The menu is still rendered, disabled, with the
 * reason stated — a missing button would leave a read-only patient wondering
 * whether their log is editable at all. The flag is read from the session with
 * the same `resolvePortalAccess` the server guard uses.
 *
 * ## Editing happens in a dialog
 *
 * Edit opens `EditEntryDialog` over this list rather than navigating to
 * `/log-progress/edit/[id]`. That keeps the log history on screen, so the
 * patient does not have to go back to the list after every save and can move on
 * to the next day immediately. The standalone edit route still exists and still
 * works — it is a deep-linkable page — it is just no longer where the menu
 * takes you.
 *
 * Delete is a dialog too, but an `AlertDialog` rather than the edit dialog's
 * `Dialog`, because Radix prevents backdrop dismissal in an AlertDialog and a
 * stray click should not be able to destroy a day of logging. Both dialogs are
 * rendered once for the whole list and re-targeted by id, so working through
 * several rows does not accumulate a dialog instance per entry.
 *
 * ## Ten rows at a time
 *
 * The full `entries` array still arrives — the weight chart above needs every
 * point, and its y-axis is scaled to the minimum and maximum of *all* weights,
 * so handing it a truncated list would quietly redraw the patient's history as
 * the last ten days only. What is limited is how much of that array becomes
 * DOM at once: `visibleCount` starts at `PAGE_SIZE` and grows by `PAGE_SIZE`
 * as the sentinel scrolls into view.
 *
 * The distinction matters. This is a rendering limit, not a request limit: one
 * request still fetches the whole array, and what a longer history costs is
 * paid in the network response rather than in layout and paint. That is the
 * right trade for the numbers on this page, where the chart has to see
 * everything. If the payload ever needs to shrink too, the chart wants its own
 * lightweight series endpoint and only then is server-side paging worth it.
 *
 * Because loading more only appends to the end, the `expanded` set keyed by
 * index stays valid, and `router.refresh()` after an edit or delete hands back
 * the whole array — so how far the patient had scrolled survives a save.
 *
 * The loading mechanics live in `hooks/use-progressive-list.ts`, shared with the
 * message thread. This page passes no scroll root, because the list scrolls with
 * the window.
 */
export function LogHistory({ entries }: { entries: ProgressEntry[] }) {
  const { user } = useAuth();
  const readOnly = resolvePortalAccess(user?.roles, user?.capabilities, user?.portalAccess)?.readOnly ?? true;

  const [expanded, setExpanded] = useState<ReadonlySet<number>>(() => new Set([0]));
  const [openMenu, setOpenMenu] = useState<number | null>(null);
  const [editingId, setEditingId] = useState<number | null>(null);
  // Deleting needs the row's date as well as its id, so the prompt can name the
  // day being destroyed instead of asking the patient to remember which row
  // they clicked.
  const [deleting, setDeleting] = useState<{ id: number; date: string } | null>(null);

  // How much of the list is rendered. Grows by PAGE_SIZE; never shrinks the
  // rows already on screen, so an edit or a `router.refresh()` cannot collapse
  // the list out from under somebody who had scrolled.
  const { visible, hasMore, remaining, loadMore, sentinelRef, isPending } = useProgressiveList(entries, {
    pageSize: PAGE_SIZE,
    resetKey: entries[0]?.id,
  });

  // The design shows "10 entries"; a patient with no logs yet gets an explicit
  // empty state rather than a badge reading "0 entries". The badge counts
  // everything the patient has, not just what is on screen, so the number does
  // not climb as they scroll.
  const bodyIdPrefix = useId();

  useEffect(() => {
    if (openMenu === null) return;

    function onKeyDown(event: KeyboardEvent) {
      if (event.key === "Escape") setOpenMenu(null);
    }
    document.addEventListener("keydown", onKeyDown);
    return () => document.removeEventListener("keydown", onKeyDown);
  }, [openMenu]);

  function toggle(index: number) {
    setExpanded((previous) => {
      const next = new Set(previous);
      if (!next.delete(index)) next.add(index);
      return next;
    });
  }

  return (
    <section>
      <div className="mb-4 flex items-center justify-between">
        <h2 className="font-serif text-lg text-foreground">Log history</h2>
        <span className="rounded-full bg-brand-soft px-2.5 py-1 font-semibold text-primary text-xs">
          {entries.length} {entries.length === 1 ? "entry" : "entries"}
        </span>
      </div>

      {entries.length === 0 ? (
        <div className="rounded-xl border border-border bg-surface p-8 text-center shadow-panel">
          <p className="text-muted-foreground text-sm">
            Nothing logged yet. Your daily weigh-ins will appear here.
          </p>
        </div>
      ) : (
        <div className="space-y-3">
          {visible.map((entry, index) => (
            <EntryCard
              key={`${entry.date}-${index}`}
              entry={entry}
              bodyId={`${bodyIdPrefix}-${index}`}
              open={expanded.has(index)}
              readOnly={readOnly}
              menuOpen={openMenu === index}
              onToggle={() => toggle(index)}
              onMenuToggle={() => setOpenMenu(openMenu === index ? null : index)}
              onMenuClose={() => setOpenMenu(null)}
              onEdit={() => setEditingId(entry.id)}
              onDelete={() => setDeleting({ id: entry.id, date: entry.date })}
            />
          ))}

          {/*
            The loader. `rootMargin` on the observer usually means the patient
            never sees this, but it is what makes the feature usable by keyboard
            and reachable by a screen reader, both of which an IntersectionObserver
            on its own would lock out.
          */}
          <div ref={sentinelRef} className="pt-1">
            {hasMore ? (
              <div className="flex flex-col items-center gap-2 py-2">
                <Button
                  variant="outline"
                  size="lg"
                  onClick={loadMore}
                  disabled={isPending}
                  aria-busy={isPending}
                  className="w-full sm:w-auto"
                >
                  {isPending ? (
                    <>
                      <Loader2 className="animate-spin" aria-hidden="true" />
                      Loading&hellip;
                    </>
                  ) : (
                    `Load ${Math.min(PAGE_SIZE, remaining)} more`
                  )}
                </Button>
                <p className="text-muted-foreground text-xs">
                  Showing {visible.length} of {entries.length}
                </p>
              </div>
            ) : null}
          </div>
        </div>
      )}

      {/* One dialog for the whole list: reopening it with a different id
          re-targets it, so a patient editing several days in a row is not
          paying for one dialog instance per row. */}
      <EditEntryDialog messageId={editingId} onClose={() => setEditingId(null)} />

      {/* Same reuse for the delete confirmation. */}
      <DeleteEntryDialog
        messageId={deleting?.id ?? null}
        entryDate={deleting?.date ?? ""}
        onClose={() => setDeleting(null)}
      />
    </section>
  );
}

function EntryCard({
  entry,
  bodyId,
  open,
  readOnly,
  menuOpen,
  onToggle,
  onMenuToggle,
  onMenuClose,
  onEdit,
  onDelete,
}: {
  entry: ProgressEntry;
  bodyId: string;
  open: boolean;
  readOnly: boolean;
  menuOpen: boolean;
  onToggle: () => void;
  onMenuToggle: () => void;
  onMenuClose: () => void;
  onEdit: () => void;
  onDelete: () => void;
}) {
  return (
    // No `overflow-hidden` here. It was only ever needed to clip the expanded
    // detail panel to the card's rounded corners, but it also clips the
    // absolutely-positioned row menu: on a collapsed row the card is only as
    // tall as its header, so the menu — which hangs below it — was clipped
    // away entirely and appeared to be dead. The clipping now lives on the
    // detail panel itself, which is the only thing that needs it.
    <article className="rounded-xl border border-border bg-surface shadow-panel">
      <div className="flex items-stretch">
        <button
          type="button"
          onClick={onToggle}
          aria-expanded={open}
          aria-controls={bodyId}
          className="flex flex-1 flex-wrap items-center justify-between gap-4 px-5 py-4 text-left outline-none focus-visible:ring-3 focus-visible:ring-ring/50"
        >
          <span className="flex flex-wrap items-center gap-3">
            <span className="rounded-lg bg-primary px-3 py-1.5 font-semibold text-primary-foreground text-sm">
              {entry.date}
            </span>
            <span className="text-muted-foreground text-xs">Day {entry.day}</span>
            <span className="rounded-full bg-positive-soft px-2.5 py-1 font-semibold text-positive text-xs">
              Adherence {entry.adherence}/10
            </span>
            {entry.note ? (
              <span className="flex items-center gap-1 rounded-full bg-flame-soft px-2.5 py-1 font-semibold text-flame text-xs">
                <Flag className="size-3" aria-hidden="true" />
                Flagged
              </span>
            ) : null}
          </span>

          <span className="flex items-center gap-1">
            <span className="mr-2 hidden text-foreground/70 text-sm sm:block">
              {entry.weight.toFixed(1)} lbs
            </span>
            <ChevronDown
              className={`size-4 text-muted-foreground transition-transform duration-200 ${
                open ? "rotate-180" : ""
              }`}
              aria-hidden="true"
            />
          </span>
        </button>

        <div className="relative flex items-center pr-4">
          <Button
            variant="ghost"
            size="icon-sm"
            onClick={onMenuToggle}
            aria-haspopup="menu"
            aria-expanded={menuOpen}
            aria-label={`Actions for ${entry.date}`}
            title={readOnly ? "Unavailable on a read-only account" : "Edit or delete this entry"}
          >
            <MoreHorizontal className="rotate-90" />
          </Button>

          {menuOpen ? (
            <>
              {/* Click-outside and Escape are both handled; this is the
                  click-outside, and it also covers the rest of the page so the
                  menu cannot be left open behind the card. */}
              <button
                type="button"
                aria-label="Close menu"
                className="fixed inset-0 z-10 cursor-default"
                onClick={onMenuClose}
              />
              <div
                role="menu"
                className="absolute top-full right-0 z-20 mt-1 w-40 overflow-hidden rounded-lg border border-border bg-surface shadow-panel"
              >
                <MenuItem
                  disabled={readOnly}
                  onSelect={() => {
                    onMenuClose();
                    onEdit();
                  }}
                  hint={readOnly ? "Read-only account" : undefined}
                >
                  Edit
                </MenuItem>
                <MenuItem
                  disabled={readOnly}
                  onSelect={() => {
                    onMenuClose();
                    onDelete();
                  }}
                  destructive
                  hint={readOnly ? "Read-only account" : undefined}
                >
                  Delete
                </MenuItem>
              </div>
            </>
          ) : null}
        </div>
      </div>

      {open ? (
        <div
          id={bodyId}
          className="overflow-hidden rounded-b-xl border-border border-t px-5 py-5"
        >
          <div className="grid grid-cols-1 gap-6 sm:grid-cols-3">
            <div>
              <p className="mb-2 font-semibold text-[11px] text-muted-foreground uppercase tracking-wide">
                Details
              </p>
              <Row label="Today's Weight" value={`${entry.weight.toFixed(2)} lbs`} />
              <Row label="Weight Loss To Date" value={`${entry.loss.toFixed(2)} lbs`} />
              <Row label="Water Intake" value={`${entry.water.toFixed(2)} oz`} />
              <Row label="Sleep Hours" value={`${entry.sleep} hrs`} />
            </div>
            <div>
              <p className="mb-2 font-semibold text-[11px] text-muted-foreground uppercase tracking-wide">
                Food
              </p>
              <Row label="Lunch" value={entry.lunch} />
              <Row label="Dinner" value={entry.dinner} />
              <Row label="Other" value={entry.other} />
            </div>
            <div>
              <p className="mb-2 font-semibold text-[11px] text-muted-foreground uppercase tracking-wide">
                Flags &amp; Notes
              </p>
              <div
                className={`rounded-lg px-3 py-2 text-sm ${
                  entry.note ? "bg-flame-soft text-flame" : "bg-canvas text-muted-foreground"
                }`}
              >
                {entry.note ?? "No flags logged for this day."}
              </div>
            </div>
          </div>
        </div>
      ) : null}
    </article>
  );
}

function Row({ label, value }: { label: string; value: string }) {
  return (
    <div className="flex items-center justify-between gap-3 py-1.5 text-sm">
      <span className="text-muted-foreground">{label}</span>
      <span className="text-right font-medium text-foreground">{value}</span>
    </div>
  );
}

function MenuItem({
  children,
  disabled,
  destructive,
  hint,
  onSelect,
}: {
  children: React.ReactNode;
  disabled?: boolean;
  destructive?: boolean;
  hint?: string;
  onSelect: () => void;
}) {
  return (
    <button
      type="button"
      role="menuitem"
      disabled={disabled}
      onClick={onSelect}
      title={hint}
      className={`block w-full px-3 py-2 text-left text-sm disabled:cursor-not-allowed disabled:opacity-50 ${
        destructive
          ? "text-flame enabled:hover:bg-flame-soft"
          : "text-foreground enabled:hover:bg-canvas"
      }`}
    >
      {children}
    </button>
  );
}
