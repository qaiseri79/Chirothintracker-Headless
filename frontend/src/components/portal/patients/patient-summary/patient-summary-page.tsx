"use client";

import { useMemo, useState } from "react";
import { useSummaryData } from "./summary-data-provider";
import type { PatientSummarySnapshot } from "@/lib/patients/summary";
import { StatCards, type QuickFilter } from "./stat-cards";
import { PatientFilters } from "./patient-filters";
import { PatientTable, type SortDirection, type SortKey } from "./patient-table";
import { PatientWorkspacePanel } from "./patient-workspace-panel";
import { Toast } from "../../toast";

/** Filters and sorts the shared Drupal roster; statistics use all enrolled patients. */
export function PatientSummaryPage({ options }: { options: PatientSummarySnapshot }) {
  const { patients, write } = useSummaryData();
  const [query, setQuery] = useState("");
  const [clinicFilter, setClinicFilter] = useState("");
  const [phaseFilter, setPhaseFilter] = useState("");
  const [programFilter, setProgramFilter] = useState("");
  const [reviewFilter, setReviewFilter] = useState("");
  const [quickFilter, setQuickFilter] = useState<QuickFilter | null>(null);
  const [sortKey, setSortKey] = useState<SortKey>("name");
  const [sortDirection, setSortDirection] = useState<SortDirection>("asc");
  /** The patient whose workspace panel is open, and which tab it opened on. */
  const [workspacePatientId, setWorkspacePatientId] = useState<number | null>(null);
  const [workspaceTab, setWorkspaceTab] = useState("sessions");
  /** The global toast's current message; null when nothing is showing. */
  const [toastMessage, setToastMessage] = useState<string | null>(null);

  const stats = useMemo(
    () => [
      { label: "Patients enrolled", value: String(patients.length) },
      {
        label: "Needs review",
        value: String(patients.filter((row) => row.review === "New").length),
        quickFilter: "review" as const,
      },
      {
        label: "Needs attention",
        value: options.attentionAvailable ? String(patients.filter((row) => row.attention).length) : "—",
        ...(options.attentionAvailable ? { quickFilter: "attention" as const } : {}),
      },
      {
        label: "Total weight lost",
        value: patients.some((row) => row.netLoss !== null) ? `${patients.reduce((sum, row) => sum + (row.netLoss ?? 0), 0).toFixed(0)} lbs` : "—",
      },
    ],
    [patients, options.attentionAvailable],
  );

  /**
   * Filter, then sort — the order the spec fixes.
   *
   * An empty dropdown or search box matches everything, so each control only
   * narrows the list when it holds a value. The quick filter ANDs with the
   * dropdowns rather than replacing them: it is a shortcut to a common
   * narrowing, and the pill in the filter bar shows that it is still on.
   * Sorting compares numbers numerically and everything else as text, which is
   * why the two numeric columns are checked by type rather than by key.
   */
  const rows = useMemo(() => {
    const needle = query.trim().toLowerCase();
    const filtered = patients.filter(
      (patient) =>
        (!needle ||
          patient.name.toLowerCase().includes(needle) ||
          patient.email.toLowerCase().includes(needle)) &&
        (!clinicFilter || patient.clinic === clinicFilter) &&
        (!phaseFilter || patient.phase === phaseFilter) &&
        (!programFilter || patient.program === programFilter) &&
        (!reviewFilter || patient.review === reviewFilter) &&
        (quickFilter !== "review" || patient.review === "New") &&
        (quickFilter !== "attention" || patient.attention),
    );

    const direction = sortDirection === "asc" ? 1 : -1;
    return [...filtered].sort((a, b) => {
      const aValue = a[sortKey];
      const bValue = b[sortKey];
      if (typeof aValue === "number" && typeof bValue === "number") {
        return (aValue - bValue) * direction;
      }
      return String(aValue).localeCompare(String(bValue)) * direction;
    });
  }, [patients, query, clinicFilter, phaseFilter, programFilter, reviewFilter, quickFilter, sortKey, sortDirection]);

  /**
   * Clicking the active column flips its direction; clicking a new column
   * sorts by it ascending first, the way the design's `cmp` behaves.
   */
  function toggleSort(key: SortKey) {
    if (key === sortKey) {
      setSortDirection((current) => (current === "asc" ? "desc" : "asc"));
    } else {
      setSortKey(key);
      setSortDirection("asc");
    }
  }

  /** Clicking the active quick filter card again turns it off. */
  function toggleQuickFilter(filter: QuickFilter) {
    setQuickFilter((current) => (current === filter ? null : filter));
  }

  async function update(id: number, payload: unknown) {
    try { await write(id, "", payload); }
    catch (error) { setToastMessage(error instanceof Error ? error.message : "Unable to save patient details."); }
  }
  function handlePhaseChange(id: number, phase: string) {
    const code = options.phases.find((option) => option.label === phase)?.code;
    if (code) void update(id, { action: "phase", phase: code });
  }
  function handleReviewToggle(id: number) {
    const patient = patients.find((row) => row.id === id);
    if (patient) void update(id, { action: "review", status: patient.review === "New" ? "Reviewed" : "New" });
  }
  async function handleUpdateLastSeen(id: number, display: string) {
    const date = `${display.slice(6)}-${display.slice(0, 2)}-${display.slice(3, 5)}`;
    await write(id, "", { action: "lastSeen", date });
  }

  /** A session tile (or "New session") opened the workspace panel. */
  function handleOpenWorkspace(id: number, tab: string) {
    setWorkspacePatientId(id);
    setWorkspaceTab(tab);
  }

  /** Every filter and the search box, back to empty. Sort is left alone. */
  function clearAll() {
    setQuery("");
    setClinicFilter("");
    setPhaseFilter("");
    setProgramFilter("");
    setReviewFilter("");
    setQuickFilter(null);
  }

  return (
    <div className="mx-auto max-w-[1400px] space-y-5">
      <div>
        <h1 className="font-serif text-2xl text-foreground">Patient summary</h1>
        <p className="mt-1 text-sm text-[#6B7280]">
          Click a patient to open their card directly below the row.
        </p>
      </div>

      <StatCards
        stats={stats}
        activeQuickFilter={quickFilter}
        onToggleQuickFilter={toggleQuickFilter}
      />

      <PatientFilters
        clinicOptions={options.locations.map((location) => location.name)}
        phaseOptions={options.phases.map((phase) => phase.label)}
        programOptions={[...new Set(patients.map((patient) => patient.program).filter(Boolean))]}
        query={query}
        onQueryChange={setQuery}
        clinicFilter={clinicFilter}
        onClinicFilterChange={setClinicFilter}
        phaseFilter={phaseFilter}
        onPhaseFilterChange={setPhaseFilter}
        programFilter={programFilter}
        onProgramFilterChange={setProgramFilter}
        reviewFilter={reviewFilter}
        onReviewFilterChange={setReviewFilter}
        quickFilter={quickFilter}
        onQuickFilterChange={setQuickFilter}
        filteredCount={rows.length}
        totalCount={patients.length}
        onClearAll={clearAll}
      />

      <PatientTable
        rows={rows}
        sortKey={sortKey}
        sortDirection={sortDirection}
        onSort={toggleSort}
        onPhaseChange={handlePhaseChange}
        onReviewToggle={handleReviewToggle}
        onUpdateLastSeen={handleUpdateLastSeen}
        onOpenWorkspace={handleOpenWorkspace}
        showToast={setToastMessage}
        phaseOptions={options.phases.map((phase) => phase.label)}
        locations={options.locations}
        statuses={options.statuses}
      />

      <PatientWorkspacePanel
        patient={patients.find((row) => row.id === workspacePatientId) ?? null}
        tab={workspaceTab}
        onClose={() => setWorkspacePatientId(null)}
        onTabChange={setWorkspaceTab}
        showToast={setToastMessage}
      />


      {toastMessage ? (
        <Toast message={toastMessage} onClose={() => setToastMessage(null)} />
      ) : null}
    </div>
  );
}
