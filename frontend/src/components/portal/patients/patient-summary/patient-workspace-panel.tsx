"use client";

import { useCallback, useEffect, useState } from "react";
import { createPortal } from "react-dom";
import { Maximize, Minimize } from "lucide-react";
import type { PatientSummaryRow } from "@/lib/patients/summary";
import { SummarySectionStatus, useSummaryData } from "./summary-data-provider";
import { SECTION_FIELDS, type SummarySection } from "@/lib/patients/summary";
import { SessionForm } from "./session-form";
import { SessionList } from "./session-list";
import { NotesTab } from "./notes-tab";
import { MessagesTab } from "./messages-tab";
import { AttachmentsTab } from "./attachments-tab";
import { ProgressTab } from "./progress-tab";
import { IntakeTab } from "./intake-tab";

/**
 * The patient workspace panel, with lazily loaded Drupal sections.
 *
 * Opens over the page from a session tile (or any future entry point) with the
 * patient's name, photo and program in the header, a tab bar, and a content
 * area whose real bodies land with each tab. The overlay closes on backdrop
 * click or the Close button; the panel itself stops the click so interacting
 * with it does not dismiss it.
 *
 * The program badge uses the same two-colour logic as the roster's Program
 * column — "Red Light + Weight Loss" in the accent red, everything else in the
 * brand green — so the two views cannot disagree about what a program looks
 * like. Tab counts come from the patient data where the design shows them
 * (Sessions today); the rest get their counts when their tabs are built.
 *
 * The Sessions tab hosts the add-session form: `activeSessionForm` holds the
 * session type being added, and is reset whenever the tab changes or the panel
 * closes, so a half-filled form never leaks into another patient or tab.
 *
 * `patient` is null when nothing is open, and the component renders nothing
 * then — the container decides whether the panel exists, this only decides
 * what it shows.
 *
 * The Messages tab is the one tab with no summary section behind it: it reads
 * the patient's conversation through the shared message endpoints, so it is
 * excluded from the section load below and renders outside the section
 * status/guard. Its count lands through `MessagesTab`'s callback once the
 * thread has been read, like the other late counts.
 *
 * The header's Full screen toggle, from the design's `drawWS()`, widens the
 * panel to the viewport and centres its content on a `max-w-6xl` column; the
 * `fullScreen` flag drives both the aside's width and the body's wrapper class.
 */
export function PatientWorkspacePanel({
  patient,
  tab,
  onClose,
  onTabChange,
  showToast,
}: {
  patient: PatientSummaryRow | null;
  tab: string;
  onClose: () => void;
  onTabChange: (tab: string) => void;
  showToast: (message: string) => void;
}) {
  const { load, sections } = useSummaryData();
  const patientId = patient?.id;
  useEffect(() => { if (patientId && tab in SECTION_FIELDS) void load(patientId, tab as SummarySection); }, [patientId, tab, load]);
  const [activeSessionForm, setActiveSessionForm] = useState<string | null>(null);
  /**
   * The message thread's length, reported by the tab once its thread loads,
   * tagged with the patient it belongs to so a count can never outlive them.
   */
  const [messagesCount, setMessagesCount] = useState<{ patientId: number; count: number } | null>(null);
  const reportMessageCount = useCallback((count: number) => setMessagesCount({ patientId: patientId ?? 0, count }), [patientId]);
  /** Whether the panel spans the full viewport instead of its default width. */
  const [fullScreen, setFullScreen] = useState(false);

  if (!patient) return null;

  /** Tab changes drop any open form: it belongs to the tab being left. */
  function handleTabChange(next: string) {
    setActiveSessionForm(null);
    onTabChange(next);
  }

  /** Closing drops it too, so reopening starts clean. */
  function handleClose() {
    setActiveSessionForm(null);
    setMessagesCount(null);
    setFullScreen(false);
    onClose();
  }

  /** The badge shows only the count reported for the patient now open. */
  const messageCount = messagesCount?.patientId === patient.id ? messagesCount.count : 0;
  const tabs = [
    { id: "progress", label: "Progress" },
    { id: "sessions", label: "Sessions", count: patient.sessions?.length || 0 },
    { id: "messages", label: "Messages", count: messageCount },
    { id: "notes", label: "Notes" },
    { id: "attachments", label: "Attachments", count: patient.attachmentsList?.length || 0 },
    { id: "intake", label: "Intake" },
  ];

  return createPortal(
    <div
      className="fixed inset-0 z-50 flex justify-end bg-[#101827]/40"
      onClick={handleClose}
    >
      <aside
        role="dialog"
        aria-modal="true"
        className={`flex h-full flex-col bg-[#F3F4F1] shadow-2xl ${fullScreen ? "w-full" : "w-full max-w-4xl"}`}
        onClick={(e) => e.stopPropagation()}
      >
        <header className="border-b border-line bg-white px-5 pt-4 sm:px-8">
          <div className="flex items-center gap-4">
            {patient.avatar ? <img
              src={patient.avatar}
              alt=""
              className="h-12 w-12 rounded-full border border-line object-cover"
            /> : <span className="flex h-12 w-12 items-center justify-center rounded-full bg-primary-soft text-primary">{patient.name.slice(0, 2).toUpperCase()}</span>}
            <div className="min-w-0 flex-1">
              <h2 className="truncate font-serif text-2xl text-[#101827]">{patient.name}</h2>
              <span
                className={`mt-0.5 inline-block rounded-md px-2 py-0.5 text-xs font-semibold text-white ${
                  patient.program === "Red Light + Weight Loss"
                    ? "bg-[#A8421F]"
                    : "bg-[#0B5D52]"
                }`}
              >
                {patient.program}
              </span>
            </div>
            <button
              type="button"
              onClick={() => setFullScreen((current) => !current)}
              aria-label={fullScreen ? "Exit full screen" : "Full screen"}
              title={fullScreen ? "Exit full screen" : "Full screen"}
              className="hidden items-center gap-1.5 rounded-full border border-line px-3 py-1.5 text-sm font-semibold hover:border-[#0B5D52] md:inline-flex"
            >
              {fullScreen ? <Minimize className="size-4" /> : <Maximize className="size-4" />}
              <span className="hidden lg:inline">{fullScreen ? "Exit full screen" : "Full screen"}</span>
            </button>
            <button
              type="button"
              onClick={handleClose}
              className="rounded-full border border-line px-3 py-1.5 text-sm font-semibold hover:border-[#0B5D52]"
            >
              Close ✕
            </button>
          </div>
          <nav className="-mb-px mt-3 flex gap-1 overflow-x-auto" role="tablist">
            {tabs.map((t) => (
              <button
                key={t.id}
                type="button"
                onClick={() => handleTabChange(t.id)}
                role="tab"
                aria-selected={tab === t.id}
                className={`whitespace-nowrap border-b-2 px-3 py-3 text-sm font-semibold ${
                  tab === t.id
                    ? "border-[#0B5D52] text-[#0B5D52]"
                    : "border-transparent text-[#6B7280] hover:text-[#101827]"
                }`}
              >
                {t.label}
                {t.count ? (
                  <span className="ml-1.5 rounded-full bg-[#F3F4F1] px-1.5 py-0.5 text-xs text-[#6B7280]">
                    {t.count}
                  </span>
                ) : null}
              </button>
            ))}
          </nav>
        </header>
        <div className="flex-1 overflow-y-auto px-5 py-6 sm:px-8">
          <div className={fullScreen ? "mx-auto max-w-6xl" : undefined}>
          {tab === "messages" ? (
            <MessagesTab
              key={patient.id}
              patientId={patient.id}
              patientName={patient.name}
              onCountChange={reportMessageCount}
            />
          ) : (
            <>
              <SummarySectionStatus id={patient.id} section={tab as SummarySection} />
              {!sections[`${patient.id}:${tab}`]?.loaded ? null : tab === "progress" ? (
                <ProgressTab
                  weightHistory={patient.weightHistory}
                  measurementChanges={patient.measurementChanges}
                />
              ) : tab === "sessions" && activeSessionForm ? (
                <SessionForm
                  patientId={patient.id}
                  profileId={patient.sessions.find((s) => s.name === activeSessionForm)?.id ?? 0}
                  sessionType={activeSessionForm}
                  remainingCount={
                    patient.sessions.find((s) => s.name === activeSessionForm)?.count ?? 0
                  }
                  onBack={() => setActiveSessionForm(null)}
                  onSave={() => setActiveSessionForm(null)}
                />
              ) : tab === "sessions" ? (
                <SessionList
                  sessions={patient.sessions}
                  onAddSession={(type) => setActiveSessionForm(type)}
                />
              ) : tab === "notes" ? (
                <NotesTab
                  notesList={patient.notesList}
                  patientId={patient.id}
                  showToast={showToast}
                />
              ) : tab === "attachments" ? (
                <AttachmentsTab
                  attachmentsList={patient.attachmentsList}
                  patientId={patient.id}
                  showToast={showToast}
                />
              ) : tab === "intake" ? (
                <IntakeTab
                  intake={patient.intake}
                  name={patient.name}
                  email={patient.email}
                />
              ) : (
                <div className="rounded-xl border border-line bg-white p-6 shadow-sm">
                  <p className="text-center text-[#6B7280]">
                    Workspace content for {tab} coming soon...
                  </p>
                </div>
              )}
            </>
          )}
          </div>
        </div>
      </aside>
    </div>,
    document.body,
  );
}
