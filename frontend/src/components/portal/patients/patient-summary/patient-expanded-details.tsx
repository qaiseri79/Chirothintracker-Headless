"use client";

import { useState } from "react";
import { createPortal } from "react-dom";
import { useRouter } from "next/navigation";
import { Calendar, ChevronRight, MessageSquare, Plus, StickyNote, Zap } from "lucide-react";
import { formatSummaryNumber, type PatientSummaryRow } from "@/lib/patients/summary";
import { SummarySectionStatus, useSummaryData } from "./summary-data-provider";
import { LogProgressDialog } from "./log-progress-dialog";
import { DailyLogs } from "@/components/patients/daily-logs";

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
}: {
  patient: PatientSummaryRow;
  onUpdateLastSeen: (id: number, lastSeen: string) => Promise<void>;
  onOpenWorkspace: (tab: string) => void;
}) {
  const { readOnly, pending, sections, load } = useSummaryData();
  const logsState = sections[`${patient.id}:logs`];
  const [logDialogOpen, setLogDialogOpen] = useState(false);
  const [saveError, setSaveError] = useState<string | null>(null);
  const router = useRouter();
  const [readFull, setReadFull] = useState<{ kind: "message" | "note"; time: string; text: string } | null>(null);
  const [isModalOpen, setIsModalOpen] = useState(false);
  /** The date input's value, held in `YYYY-MM-DD` as the input requires. */
  const [draftDate, setDraftDate] = useState("");
  const latestNote = [...patient.notesList].sort((a, b) => b.date.localeCompare(a.date))[0];

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
      <div className="mb-4 rounded-xl border border-line bg-white px-4 py-3">
        <p className="text-[12px] font-semibold tracking-[0.05em] uppercase text-[#6B7280]">
          Messages &amp; notes
        </p>
        <div className="mt-1 divide-y divide-line">
          <CommRow
            icon={<MessageSquare className="size-4" />}
            name="Message"
            count={PLACEHOLDER_MESSAGES.count}
            last={PLACEHOLDER_MESSAGES.last}
            time={PLACEHOLDER_MESSAGES.time}
            onReadFull={() =>
              setReadFull({
                kind: "message",
                time: PLACEHOLDER_MESSAGES.time,
                text: PLACEHOLDER_MESSAGES.last,
              })
            }
            onViewAll={() => router.push("/chiropractor/messages")}
          />
          <CommRow
            icon={<StickyNote className="size-4" />}
            name="Note"
            count={patient.notesList.length}
            last={latestNote?.text ?? ""}
            time={latestNote?.date ?? ""}
            onReadFull={() => {
              if (!latestNote) return;
              setReadFull({ kind: "note", time: latestNote.date, text: latestNote.text });
            }}
            onViewAll={() => onOpenWorkspace("notes")}
          />
        </div>
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
                        else router.push("/chiropractor/messages");
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
 * One "Messages & notes" row: count + kind, the latest item truncated, "Read
 * full", and "View all" — the design's `comm()`.
 */
function CommRow({
  icon,
  name,
  count,
  last,
  time,
  onReadFull,
  onViewAll,
}: {
  icon: React.ReactNode;
  name: string;
  count: number;
  last: string;
  time: string;
  onReadFull: () => void;
  onViewAll: () => void;
}) {
  return (
    <div className="flex items-center gap-3 py-2">
      <span className="flex w-28 shrink-0 items-center gap-1.5 text-sm font-semibold text-[#0B5D52]">
        {icon}
        {count} {count === 1 ? name : `${name}s`}
      </span>
      {count > 0 ? (
        <>
          <p className="min-w-0 flex-1 truncate text-sm text-[#101827]/80">
            {time ? <span className="text-[#6B7280]">{time} · </span> : null}
            {last}
          </p>
          <button
            type="button"
            onClick={onReadFull}
            className="shrink-0 rounded-md border border-line px-2.5 py-1 text-xs font-medium hover:border-[#0B5D52] hover:text-[#0B5D52]"
          >
            Read full
          </button>
          <button
            type="button"
            onClick={onViewAll}
            className="hidden shrink-0 text-xs font-medium text-[#0B5D52] hover:underline sm:block"
          >
            View all
          </button>
        </>
      ) : (
        <p className="flex-1 text-sm text-[#6B7280]">Nothing yet.</p>
      )}
    </div>
  );
}

/**
 * Dummy messages row until the summary payload carries real conversation data:
 * the design demos count 1 + a welcome message, so the count is 1 until it is
 * confirmed against real conversations.
 */
const PLACEHOLDER_MESSAGES = {
  count: 1,
  last: "Welcome to ChiroThin Dr. I'm excited to begin my program and work with you toward my goals!",
  time: "",
} as const;
