"use client";

import { useEffect, useState } from "react";
import { createPortal } from "react-dom";
import type { PatientSummaryRow } from "@/lib/patients/summary";
import { SummarySectionStatus, useSummaryData } from "./summary-data-provider";
import type { SummarySection } from "@/lib/patients/summary";
import { SessionForm } from "./session-form";
import { SessionList } from "./session-list";
import { NotesTab } from "./notes-tab";
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
  useEffect(() => { if (patientId) void load(patientId, tab as SummarySection); }, [patientId, tab, load]);
  const [activeSessionForm, setActiveSessionForm] = useState<string | null>(null);

  if (!patient) return null;

  /** Tab changes drop any open form: it belongs to the tab being left. */
  function handleTabChange(next: string) {
    setActiveSessionForm(null);
    onTabChange(next);
  }

  /** Closing drops it too, so reopening starts clean. */
  function handleClose() {
    setActiveSessionForm(null);
    onClose();
  }

  const tabs = [
    { id: "progress", label: "Progress" },
    { id: "sessions", label: "Sessions", count: patient.sessions?.length || 0 },
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
        className="flex h-full w-full max-w-4xl flex-col bg-[#F3F4F1] shadow-2xl"
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
        </div>
      </aside>
    </div>,
    document.body,
  );
}
