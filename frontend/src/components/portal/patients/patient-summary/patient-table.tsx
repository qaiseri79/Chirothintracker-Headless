"use client";

import { useState } from "react";
import type { PatientSummaryRow } from "@/lib/patients/summary";
import { PatientRow } from "./patient-row";

/** Columns the roster can be sorted by. */
export type SortKey = "name" | "phase" | "program" | "percentOfGoal" | "netLoss" | "review";

export type SortDirection = "asc" | "desc";

interface PatientTableProps {
  phaseOptions: string[];
  locations: Array<{ id: number; name: string }>;
  statuses: Array<{ id: number; name: string }>;
  rows: PatientSummaryRow[];
  sortKey: SortKey;
  sortDirection: SortDirection;
  onSort: (key: SortKey) => void;
  onPhaseChange: (id: number, phase: string) => void;
  onReviewToggle: (id: number) => void;
  onUpdateLastSeen: (id: number, lastSeen: string) => Promise<void>;
  onOpenWorkspace: (id: number, tab: string) => void;
  showToast?: (message: string) => void;
}

/**
 * The roster table from the design's `#roster`.
 *
 * Column for column from the design's `<thead>`: Patient, Phase, Program,
 * % of goal, Net loss, Review, plus the empty trailing column the chevron
 * lives in. The responsive visibility classes are the design's own — the table
 * is the one element that keeps every column at every width by hiding the
 * optional ones, rather than reflowing.
 *
 * Every header is a sort toggle: clicking sorts by that column, clicking again
 * flips the direction. The arrow is always present — fully visible on the
 * active column (pointing its way), dimmed on the inactive ones so the whole
 * row of headers reads as clickable. `hover:text-foreground` is this theme's
 * `hover:text-ink`; the design's ink is `#101827`, which is exactly what
 * `foreground` holds here.
 *
 * The table owns the expanded patient ID so opening a row closes the previous
 * detail card. Section data remains cached by the shared summary provider.
 */
export function PatientTable({
  phaseOptions,
  locations,
  statuses,
  rows,
  sortKey,
  sortDirection,
  onSort,
  onPhaseChange,
  onReviewToggle,
  onUpdateLastSeen,
  onOpenWorkspace,
  showToast,
}: PatientTableProps) {
  const [expandedPatientId, setExpandedPatientId] = useState<number | null>(null);

  function togglePatient(id: number) {
    setExpandedPatientId((current) => current === id ? null : id);
  }

  return (
    <div className="rounded-xl border border-line bg-surface shadow-panel">
      <table className="w-full table-fixed text-sm">
        <thead>
          <tr className="border-b border-line text-left">
            <SortableHeader
              label="Patient"
              sortId="name"
              sortKey={sortKey}
              sortDirection={sortDirection}
              onSort={onSort}
              className="px-5 py-3"
            />
            <SortableHeader
              label="Phase"
              sortId="phase"
              sortKey={sortKey}
              sortDirection={sortDirection}
              onSort={onSort}
              className="hidden w-44 px-3 py-3 xl:table-cell"
            />
            <SortableHeader
              label="Program"
              sortId="program"
              sortKey={sortKey}
              sortDirection={sortDirection}
              onSort={onSort}
              className="hidden w-52 px-3 py-3 md:table-cell"
            />
            <SortableHeader
              label="% of goal"
              sortId="percentOfGoal"
              sortKey={sortKey}
              sortDirection={sortDirection}
              onSort={onSort}
              className="hidden w-44 px-3 py-3 sm:table-cell"
            />
            <SortableHeader
              label="Net loss"
              sortId="netLoss"
              sortKey={sortKey}
              sortDirection={sortDirection}
              onSort={onSort}
              className="hidden w-24 px-3 py-3 text-right md:table-cell"
            />
            <SortableHeader
              label="Review"
              sortId="review"
              sortKey={sortKey}
              sortDirection={sortDirection}
              onSort={onSort}
              className="w-28 px-3 py-3"
            />
            <th className="w-20" />
          </tr>
        </thead>
        <tbody>
          {rows.length === 0 ? (
            <tr>
              <td colSpan={7} className="px-5 py-10 text-center text-sm text-[#6B7280]">
                No patients match your filters.
              </td>
            </tr>
          ) : (
            rows.map((row) => (
              <PatientRow
                key={row.id}
                patient={row}
                isExpanded={expandedPatientId === row.id}
                onToggleExpansion={() => togglePatient(row.id)}
                phaseOptions={phaseOptions}
                locations={locations}
                statuses={statuses}
                onPhaseChange={(phase) => onPhaseChange(row.id, phase)}
                onReviewToggle={onReviewToggle}
                onUpdateLastSeen={onUpdateLastSeen}
                onOpenWorkspace={(tab) => onOpenWorkspace(row.id, tab)}
                showToast={showToast}
              />
            ))
          )}
        </tbody>
      </table>
    </div>
  );
}

/** The design's `.lbl`, shared by every header. */
const LABEL_CLASSES =
  "text-[12px] font-semibold tracking-[0.05em] uppercase text-[#6B7280]";

function SortableHeader({
  label,
  sortId,
  sortKey,
  sortDirection,
  onSort,
  className,
}: {
  label: string;
  sortId: SortKey;
  sortKey: SortKey;
  sortDirection: SortDirection;
  onSort: (key: SortKey) => void;
  className: string;
}) {
  const active = sortKey === sortId;

  return (
    <th scope="col" className={`${LABEL_CLASSES} ${className}`}>
      <button
        type="button"
        onClick={() => onSort(sortId)}
        className={`inline-flex items-center gap-1 hover:text-foreground ${
          active ? "text-[#101827]" : "text-[#6B7280]"
        }`}
      >
        {label}
        <span aria-hidden="true" className={active ? "" : "opacity-30"}>
          {active && sortDirection === "desc" ? "▼" : "▲"}
        </span>
      </button>
    </th>
  );
}
