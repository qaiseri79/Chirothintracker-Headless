"use client";

import { useEffect, useState } from "react";
import { createPortal } from "react-dom";
import { Calendar, ChevronRight, Eye, MessageSquare, Plus, Send, StickyNote, Zap } from "lucide-react";
import { formatSummaryNumber, type PatientSummaryRow } from "@/lib/patients/summary";
import { SummarySectionStatus, useSummaryData } from "./summary-data-provider";
import { fetchThread } from "@/lib/messages/client";
import type { Conversation } from "@/lib/messages/types";
import { LogProgressDialog } from "./log-progress-dialog";
import { DailyLogs } from "@/components/patients/daily-logs";
import { SendNotificationDialog } from "./send-notification-dialog";

/**
 * The expanded row's detail body: the sessions grid, then the stat tiles, from
 * the design's `detail()`.
 *
 * The sessions section is the design's Sessions card — one button per session
 * type with its count, plus a dashed "New session" tile. Both open the
 * workspace panel on the Sessions tab, which is why this component takes
 * `onOpenWorkspace` and hands it the tab name; the row supplies the patient.
 *
 * Below the grid, the ten standard tiles — label above figure, the figure
 * coloured only where the design colours it (program day in the accent red, net
 * loss by sign) — plus the "Last seen" tile, which spans two columns and opens
 * the date modal rather than displaying a static value.
 *
 * The modal is the design's `seen()` popup: a portaled overlay with a date
 * input. The input speaks `YYYY-MM-DD` while the row displays `MM/DD/YYYY`, so
 * the two conversions the design's `iso()`/`fmt()` pair performs happen here,
 * at the boundary. Saving calls `onUpdateLastSeen`, which the container
 * answers by rewriting that patient's `lastSeen` in its state.
 */
export function PatientExpandedDetails({
  patient,
  onUpdateLastSeen,
  onOpenWorkspace,
  showToast,
}: {
  patient: PatientSummaryRow;
  onUpdateLastSeen: (id: number, lastSeen: string) => Promise<void>;
  onOpenWorkspace: (tab: string) => void;
  showToast?: (message: string) => void;
}) {
  const { readOnly, pending, sections, load } = useSummaryData();
  const logsState = sections[`${patient.id}:logs`];
  const [logDialogOpen, setLogDialogOpen] = useState(false);
  const [saveError, setSaveError] = useState<string | null>(null);
  const [readFull, setReadFull] = useState<{ kind: "message" | "note"; time: string; text: string } | null>(null);
  const [isModalOpen, setIsModalOpen] = useState(false);
  const [notificationOpen, setNotificationOpen] = useState(false);
  /** The date input's value, held in `YYYY-MM-DD` as the input requires. */
  const [draftDate, setDraftDate] = useState("");
  const latestNote = [...patient.notesList].sort((a, b) => b.date.localeCompare(a.date))[0];

  /**
   * The messages tile reads the patient's live thread (`/api/messages/{uid}`)
   * rather than a summary field, exactly like the Messages tab, so its count,
   * preview and unread badge are real data instead of a placeholder. A missing
   * thread (404, or a failed read) leaves `conversation` null and the tile
   * falls back to the empty state.
   */
  const [conversation, setConversation] = useState<Conversation | null>(null);
  useEffect(() => {
    let cancelled = false;
    fetchThread(patient.id)
      .then((thread) => {
        if (!cancelled) setConversation(thread);
      })
      .catch(() => {});
    return () => {
      cancelled = true;
    };
  }, [patient.id]);
  const messageCount = conversation?.messages.length ?? 0;
  const unreadCount = conversation?.unread_count ?? 0;

  /**
   * `notesList` is a lazily fetched section (it is not part of the roster
   * payload), so pull it when the row opens; `load` no-ops when the section is
   * already loaded or being fetched, and the workspace tab used to be the only
   * trigger for it. Without this, the Notes tile stays empty until the Notes
   * tab is opened.
   */
  useEffect(() => {
    void load(patient.id, "notes");
  }, [patient.id, load]);

  function openModal() {
    setDraftDate(toIso(patient.lastSeen));
    setIsModalOpen(true);
  }

  async function save() {
    setSaveError(null);
    try { await onUpdateLastSeen(patient.id, toDisplay(draftDate)); setIsModalOpen(false); }
    catch (error) { setSaveError(error instanceof Error ? error.message : "Unable to save last seen."); }
  }

  return (
    <>
      <div className="mb-4 flex flex-wrap items-center gap-2">
        <CommTile
          kind="message"
          count={unreadCount}
          hasContent={messageCount > 0}
          last={conversation?.last_message_preview ?? ""}
          time={conversation?.last_message_time_formatted ?? ""}
          badgeRed={unreadCount > 0}
          onReadFull={() => {
            if (!messageCount) return;
            setReadFull({
              kind: "message",
              time: conversation?.last_message_time_formatted ?? "",
              text: conversation?.last_message_preview ?? "",
            });
          }}
          onViewAll={() => onOpenWorkspace("messages")}
        />
        <CommTile
          kind="note"
          count={patient.notesList.length}
          hasContent={patient.notesList.length > 0}
          last={latestNote?.text ?? ""}
          time={latestNote?.date ?? ""}
          onReadFull={() => {
            if (!latestNote) return;
            setReadFull({ kind: "note", time: latestNote.date, text: latestNote.text });
          }}
          onViewAll={() => onOpenWorkspace("notes")}
        />
        <button
          type="button"
          onClick={() => {
            if (readOnly) {
              showToast?.("This account has read-only access.");
              return;
            }
            setNotificationOpen(true);
          }}
          className="inline-flex items-center gap-2 rounded-lg bg-[#A8421F] px-3.5 py-2 text-sm font-semibold text-white hover:opacity-90"
        >
          <Send className="size-3.5" /> Send notification
        </button>
      </div>

      <div className="mb-4 rounded-xl border border-line bg-white px-4 py-3">
        <p className="text-[12px] font-semibold tracking-[0.05em] uppercase text-[#6B7280]">
          Sessions
        </p>
        <SummarySectionStatus id={patient.id} section="sessions" />
        <div className="mt-2.5 grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
          {patient.sessions.map((session) => (
            <button
              key={session.name}
              type="button"
              onClick={() => onOpenWorkspace("sessions")}
              className="group flex items-center gap-3 rounded-xl border border-line bg-white p-3 text-left transition hover:border-[#3D8361] hover:shadow-panel"
            >
              <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-[#E4F0E9] text-[#3D8361]">
                <Zap className="size-5" />
              </span>
              <span className="min-w-0 flex-1">
                <span className="block truncate text-sm font-semibold">{session.name}</span>
                <span className="block text-xs text-[#6B7280]">
                  <b className="text-base text-[#3D8361]">{session.count}</b> sessions
                </span>
              </span>
              <ChevronRight className="size-4 text-[#6B7280] transition group-hover:translate-x-0.5 group-hover:text-[#3D8361]" />
            </button>
          ))}
          <button
            type="button"
            onClick={() => onOpenWorkspace("sessions")}
            className="flex min-h-[64px] items-center justify-center gap-2 rounded-xl border border-dashed border-[#0B5D52]/50 text-sm font-semibold text-[#0B5D52] transition hover:bg-[#E4EEEC]"
          >
            <Plus className="size-4" /> New session
          </button>
        </div>
      </div>

      <div className="grid grid-cols-2 gap-2 sm:grid-cols-4 xl:grid-cols-6">
        <Tile label="Start date" value={patient.startDate || "—"} />
        <Tile label="Program day" value={formatSummaryNumber(patient.day, 0)} valueColor="text-[#A8421F]" />
        <Tile label="Start weight" value={formatSummaryNumber(patient.startWeight, 2, "lbs")} />
        <Tile label="Goal weight" value={formatSummaryNumber(patient.goalWeight, 2, "lbs")} />
        <Tile
          label="Net loss"
          value={formatSummaryNumber(patient.netLoss, 2, "lbs")}
          valueColor={(patient.netLoss ?? 0) < 0 ? "text-[#A8421F]" : "text-[#3D8361]"}
        />
        <Tile label="% of goal" value={formatSummaryNumber(patient.percentOfGoal, 2, "%")} />
        <Tile label="Overall loss" value={formatSummaryNumber(patient.overallLoss, 2, "lbs")} />
        <Tile label="Inches lost" value={formatSummaryNumber(patient.inchesLost, 2, "in")} />
        <Tile label="Daily loss" value={formatSummaryNumber(patient.dailyLoss, 2, "lbs")} />
        <Tile label="Avg loss/day" value={formatSummaryNumber(patient.avgLossPerDay, 2)} />

        <button
          type="button"
          disabled={readOnly}
          onClick={openModal}
          className="col-span-2 flex items-center gap-2 rounded-lg border border-[#A8421F]/40 bg-[#F6E4DC] px-3 py-2.5 text-left hover:border-[#A8421F]"
        >
          <Calendar className="size-4 text-[#A8421F]" />
          <div>
            <span className="block text-[12px] font-semibold tracking-[0.05em] uppercase text-[#6B7280]">
              Last seen
            </span>
            <span className="text-sm font-semibold text-[#A8421F]">{patient.lastSeen || "—"}</span>
          </div>
        </button>

        {isModalOpen
          ? createPortal(
              <div className="fixed inset-0 z-50 flex items-center justify-center bg-[#101827]/40 p-4">
                <div className="w-full max-w-sm rounded-2xl bg-white p-6 shadow-xl">
                  <h3 className="font-serif text-lg">Update last seen</h3>
                  <input
                    type="date"
                    value={draftDate}
                    onChange={(event) => setDraftDate(event.target.value)}
                    className="mt-4 w-full rounded-lg border border-line bg-white px-3 py-2 text-sm focus:border-[#0B5D52] focus:outline-none"
                  />
                  {saveError && <p role="alert" className="mt-3 text-sm text-destructive">{saveError}</p>}
                  <div className="mt-5 flex justify-end gap-2">
                    <button
                      type="button"
                      onClick={() => setIsModalOpen(false)}
                      className="rounded-full border border-line px-4 py-2 text-sm"
                    >
                      Cancel
                    </button>
                    <button
                      type="button"
                      disabled={readOnly || pending[patient.id] || !draftDate}
                      onClick={save}
                      className="rounded-full bg-primary px-5 py-2 text-sm font-semibold text-white"
                    >
                      Save
                    </button>
                  </div>
                </div>
              </div>,
              document.body,
            )
          : null}

        {readFull
          ? createPortal(
              <div className="fixed inset-0 z-50 flex items-center justify-center bg-[#101827]/40 p-4">
                <div className="w-full max-w-lg rounded-2xl bg-white p-6 shadow-xl">
                  <h3 className="font-serif text-lg">
                    Latest {readFull.kind} — {patient.name}
                  </h3>
                  {readFull.time ? <p className="mt-1 text-xs text-[#6B7280]">{readFull.time}</p> : null}
                  <p className="mt-3 whitespace-pre-line leading-relaxed">{readFull.text}</p>
                  <div className="mt-5 flex items-center justify-between gap-2">
                    <button
                      type="button"
                      onClick={() => setReadFull(null)}
                      className="rounded-full border border-line px-4 py-2 text-sm"
                    >
                      Close
                    </button>
                    <button
                      type="button"
                      onClick={() => {
                        const kind = readFull.kind;
                        setReadFull(null);
                        if (kind === "note") onOpenWorkspace("notes");
                        else onOpenWorkspace("messages");
                      }}
                      className="rounded-full bg-primary px-5 py-2 text-sm font-semibold text-white"
                    >
                      View all {readFull.kind === "note" ? "notes" : "messages"}
                    </button>
                  </div>
                </div>
              </div>,
              document.body,
            )
          : null}
      </div>

      <DailyLogs
        onLogProgress={() => setLogDialogOpen(true)}
        canLogProgress={!readOnly && !pending[patient.id]}
        logsList={patient.logsList}
        logsTotal={patient.logsTotal}
        loading={logsState?.loading}
        error={logsState?.error}
        hasMore={logsState?.hasMore}
        onLoadMore={() => void load(patient.id, "logs", true)}
        onRetry={() => void load(patient.id, "logs", Boolean(logsState?.loaded && logsState.hasMore))}
      />
      <LogProgressDialog patientId={patient.id} open={logDialogOpen} onClose={() => setLogDialogOpen(false)} />
      {notificationOpen ? (
        <SendNotificationDialog
          patient={patient}
          onClose={() => setNotificationOpen(false)}
          showToast={showToast}
        />
      ) : null}
    </>
  );
}

/** One standard tile: label above figure, the figure coloured only if asked. */
function Tile({
  label,
  value,
  valueColor = "text-[#101827]",
}: {
  label: string;
  value: string;
  valueColor?: string;
}) {
  return (
    <div className="rounded-lg border border-line bg-white px-3 py-2.5">
      <p className="text-[12px] font-semibold tracking-[0.05em] uppercase text-[#6B7280]">
        {label}
      </p>
      <p className={`mt-0.5 text-sm font-semibold ${valueColor}`}>{value}</p>
    </div>
  );
}

/** `MM/DD/YYYY` to `YYYY-MM-DD`, for the date input. The design's `iso()`. */
function toIso(display: string): string {
  return display ? `${display.slice(6)}-${display.slice(0, 2)}-${display.slice(3, 5)}` : "";
}

/** `YYYY-MM-DD` to `MM/DD/YYYY`, for the row. The design's `fmt()`. */
function toDisplay(iso: string): string {
  return iso ? `${iso.slice(5, 7)}/${iso.slice(8)}/${iso.slice(0, 4)}` : "";
}

/**
 * One compact "Messages" / "Notes" tile, the design's `ctile()`: icon, label,
 * count badge, short date and truncated latest item, with a "read latest" eye
 * and a click that opens the workspace tab.
 *
 * The badge shows what matters for its kind: the Messages tile displays the
 * thread's unread count, tinted red only when there are unread messages (and
 * `0` in the neutral grey otherwise); the Notes tile displays the note count,
 * always neutral. `hasContent` — whether the thread/notes exist at all — drives
 * the icon's accent, the preview line and the eye button.
 */
function CommTile({
  kind,
  count,
  hasContent,
  last,
  time,
  badgeRed = false,
  onReadFull,
  onViewAll,
}: {
  kind: "message" | "note";
  count: number;
  hasContent: boolean;
  last: string;
  time: string;
  badgeRed?: boolean;
  onReadFull: () => void;
  onViewAll: () => void;
}) {
  const isMessage = kind === "message";
  return (
    <div className="flex min-w-0 flex-1 basis-64 items-center gap-1 rounded-xl border border-line bg-white pr-1.5 transition hover:border-primary">
      <button
        type="button"
        onClick={onViewAll}
        title={`View all ${isMessage ? "messages" : "notes"}`}
        className="flex min-w-0 flex-1 items-center gap-3 rounded-xl px-3 py-2 text-left"
      >
        <span
          className={`flex h-9 w-9 shrink-0 items-center justify-center rounded-lg ${
            isMessage && hasContent ? "bg-[#F6E4DC] text-[#A8421F]" : "bg-primary-soft text-primary"
          }`}
        >
          {isMessage ? (
            <MessageSquare className="size-[18px]" />
          ) : (
            <StickyNote className="size-[18px]" />
          )}
        </span>
        <span className="min-w-0 flex-1">
          <span className="flex items-center gap-2">
            <span className="text-sm font-semibold">{isMessage ? "Messages" : "Notes"}</span>
            <span
              className={`rounded-full px-1.5 text-[11px] font-bold ${
                badgeRed ? "bg-[#A8421F] text-white" : "bg-[#F3F4F1] text-[#6B7280]"
              }`}
            >
              {count}
            </span>
            {hasContent && time ? <span className="ml-auto text-[11px] text-[#6B7280]">{shortDate(time)}</span> : null}
          </span>
          <span className={`block truncate text-xs ${hasContent ? "text-[#101827]/70" : "text-[#6B7280]"}`}>
            {hasContent ? last : isMessage ? "No messages yet" : "No notes yet"}
          </span>
        </span>
      </button>
      {hasContent ? (
        <button
          type="button"
          onClick={onReadFull}
          title={`Read latest ${isMessage ? "message" : "note"}`}
          aria-label={`Read latest ${isMessage ? "message" : "note"}`}
          className="shrink-0 rounded-lg p-2 text-[#6B7280] hover:bg-primary-soft hover:text-primary"
        >
          <Eye className="size-4" />
        </button>
      ) : null}
    </div>
  );
}

/** First three letters of each month, for parsing API date stamps. */
const MONTHS = ["jan", "feb", "mar", "apr", "may", "jun", "jul", "aug", "sep", "oct", "nov", "dec"];

/**
 * The tile's short creation date, `MM/DD`, from whatever the backend formats:
 * the design sample and sidebar give `MM/DD/YYYY · HH:MM`, the thread API gives
 * `M d · H:i` (no slashes), and notes give `YYYY-MM-DD` for the date input.
 * Empty when no date-shaped fragment is found.
 */
function shortDate(value: string): string {
  const slash = value.match(/\d{1,2}\/\d{1,2}/);
  if (slash) return slash[0];
  const dash = value.match(/(\d{4})-(\d{1,2})-(\d{1,2})/);
  if (dash) return `${dash[2]}/${dash[3]}`;
  const named = value.match(/[A-Za-z]{3}[a-z]*\s+(\d{1,2})/);
  if (named) {
    const month = MONTHS.indexOf(named[0].slice(0, 3).toLowerCase()) + 1;
    if (month) return `${String(month).padStart(2, "0")}/${named[1].padStart(2, "0")}`;
  }
  return "";
}
