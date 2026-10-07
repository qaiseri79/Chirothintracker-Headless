"use client";

import { useState } from "react";
import { createPortal } from "react-dom";
import { ChevronDown, Search, X } from "lucide-react";

/** Clinic, phase, and program options come from Drupal. */
const REVIEW_OPTIONS = ["New", "Reviewed"];

/**
 * The search bar, the four dropdown filters, and the filter state.
 *
 * The filters are custom dropdowns rather than native `<select>`s because a
 * select sizes itself to its longest hidden option — "Patient status" next to
 * "Red Light + Weight Loss" forced the whole bar wide. Each dropdown is a pill
 * button showing the selected value, or the field's own name ("Clinic",
 * "Phase", "Patient status", "Review status") when nothing is selected, with
 * the menu opening below it. The first menu item is that same name, which
 * clears the filter back to the unselected state.
 *
 * When a quick filter is active it appears here as a dismissible pill (the
 * stat cards that set it are up in the stat strip, and the pill is where the
 * chiropractor sees and clears it). "Clear all" resets every filter and the
 * search box; the count on the right reports the filtered total against the
 * roster.
 *
 * Everything is controlled from the container, which owns the filter state and
 * does the filtering; this component only reports what was picked.
 */
export function PatientFilters({
  clinicOptions, phaseOptions, programOptions,
  query,
  onQueryChange,
  clinicFilter,
  onClinicFilterChange,
  phaseFilter,
  onPhaseFilterChange,
  programFilter,
  onProgramFilterChange,
  reviewFilter,
  onReviewFilterChange,
  quickFilter,
  onQuickFilterChange,
  filteredCount,
  totalCount,
  onClearAll,
}: {
  clinicOptions: string[];
  phaseOptions: string[];
  programOptions: string[];
  query: string;
  onQueryChange: (value: string) => void;
  clinicFilter: string;
  onClinicFilterChange: (value: string) => void;
  phaseFilter: string;
  onPhaseFilterChange: (value: string) => void;
  programFilter: string;
  onProgramFilterChange: (value: string) => void;
  reviewFilter: string;
  onReviewFilterChange: (value: string) => void;
  quickFilter: "review" | "attention" | null;
  onQuickFilterChange: (filter: "review" | "attention" | null) => void;
  filteredCount: number;
  totalCount: number;
  onClearAll: () => void;
}) {
  return (
    <div className="rounded-xl border border-line bg-surface p-3 shadow-panel">
      <div className="flex flex-wrap items-center gap-2">
        <div className="relative min-w-0 flex-1">
          <Search
            className="pointer-events-none absolute left-3.5 top-1/2 size-4 -translate-y-1/2 text-[#6B7280]"
            aria-hidden="true"
          />
          <input
            type="search"
            value={query}
            onChange={(event) => onQueryChange(event.target.value)}
            placeholder="Search patients..."
            aria-label="Search patients"
            className="w-full rounded-full border border-line bg-white py-2 pl-10 pr-4 text-sm shadow-sm transition focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20"
          />
        </div>

        <FilterDropdown
          label="Clinic"
          value={clinicFilter}
          onChange={onClinicFilterChange}
          options={clinicOptions}
        />
        <FilterDropdown
          label="Phase"
          value={phaseFilter}
          onChange={onPhaseFilterChange}
          options={phaseOptions}
        />
        <FilterDropdown
          label="Patient status"
          value={programFilter}
          onChange={onProgramFilterChange}
          options={programOptions}
        />
        <FilterDropdown
          label="Review status"
          value={reviewFilter}
          onChange={onReviewFilterChange}
          options={REVIEW_OPTIONS}
        />

        {quickFilter ? (
          <button
            type="button"
            onClick={() => onQuickFilterChange(null)}
            className="inline-flex items-center gap-1.5 rounded-full border border-[#0B5D52] bg-[#E4EEEC] px-3.5 py-2 text-sm font-medium text-[#08423A]"
          >
            {quickFilter === "review" ? "Needs review" : "Needs attention"}
            <X className="size-4" aria-hidden="true" />
          </button>
        ) : null}

        <button
          type="button"
          onClick={onClearAll}
          className="text-sm font-medium text-[#6B7280] hover:text-[#101827] hover:underline"
        >
          Clear all
        </button>

        <span className="ml-auto text-xs text-[#6B7280]">
          Showing {filteredCount} of {totalCount} patients
        </span>
      </div>
    </div>
  );
}

/**
 * One filter dropdown: the pill button, the chevron that signals it opens, and
 * the menu below.
 *
 * The button shows the selected value, or `label` when nothing is selected —
 * the field's own name is the unselected text, so the pills stay as small as
 * the design's. The menu's first item is that same name, which clears the
 * filter. A transparent backdrop (ported to `document.body` so it never lands
 * inside the table's DOM) closes the menu on any outside click.
 */
function FilterDropdown({
  label,
  value,
  onChange,
  options,
}: {
  label: string;
  value: string;
  onChange: (value: string) => void;
  options: string[];
}) {
  const [open, setOpen] = useState(false);

  return (
    <div className="relative">
      <button
        type="button"
        onClick={() => setOpen((current) => !current)}
        aria-haspopup="listbox"
        aria-expanded={open}
        className="rounded-full border border-line bg-white px-4 py-2 pr-8 text-sm font-medium text-[#101827] hover:border-[#0B5D52] focus:outline-none"
      >
        {value || label}
      </button>
      <ChevronDown
        className="pointer-events-none absolute right-3 top-1/2 size-4 -translate-y-1/2 text-[#6B7280]"
        aria-hidden="true"
      />
      {open ? (
        <>
          {createPortal(
            <div
              className="fixed inset-0 z-40"
              onClick={() => setOpen(false)}
              aria-hidden="true"
            />,
            document.body,
          )}
          <div
            className="absolute z-50 mt-1 w-56 rounded-xl border border-line bg-surface p-1.5 shadow-xl"
            role="listbox"
          >
            <button
              type="button"
              role="option"
              aria-selected={value === ""}
              onClick={() => {
                onChange("");
                setOpen(false);
              }}
              className="flex w-full items-center gap-2.5 rounded-lg px-3 py-2 text-left text-sm font-medium hover:bg-[#E4EEEC]"
            >
              {label}
            </button>
            {options.map((option) => (
              <button
                key={option}
                type="button"
                role="option"
                aria-selected={value === option}
                onClick={() => {
                  onChange(option);
                  setOpen(false);
                }}
                className="flex w-full items-center gap-2.5 rounded-lg px-3 py-2 text-left text-sm font-medium hover:bg-[#E4EEEC]"
              >
                {option}
              </button>
            ))}
          </div>
        </>
      ) : null}
    </div>
  );
}
