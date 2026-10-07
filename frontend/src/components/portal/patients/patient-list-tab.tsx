"use client";

import { useMemo, useState } from "react";
import {
  Cell,
  EmptyRow,
  Field,
  FilterBar,
  GridHead,
  GridRow,
  Pager,
  Person,
  PhasePill,
  PlainHeader,
  ResultsCard,
  SearchBox,
  Select,
  SortHeader,
  formatDate,
  formatLbs,
} from "@/components/portal/patients/patients-ui";
import {
  PHASE_OPTIONS,
  ROLE_LABELS,
  type PatientRole,
  type PatientRow,
  type PhaseCode,
} from "@/lib/patients/types";

/**
 * Patient List — the enrolled accounts, one row each.
 *
 * The design's `listRows` / `viewList`, column for column. Six columns at `md`
 * and above, collapsing below it to a stacked card where each value keeps its own
 * label — which is why `Cell` renders the label it is given rather than the table
 * hiding columns on small screens.
 *
 * ## No actions column
 *
 * The design drew a trailing column of three icon buttons: details, notes,
 * archive. None of them were wired to anything, so they were three controls that
 * looked live and did nothing — and the archive one is the dangerous shape of
 * that: a destructive action with a button and no handler behind it. The column
 * is gone rather than disabled, because a disabled control still promises the
 * feature is coming and still invites the click.
 *
 * The archived tab keeps its own actions for now, because re-enrolling is a
 * different job from archiving and is worth revisiting on its own terms.
 *
 * Roles here are `patient` / `coach` / `doctor`, from `enrolled_patient`, the
 * chiropractor roles and the staff fallback. Archived accounts are not on this
 * tab at all.
 */

const COLUMNS = "grid-cols-1 md:grid-cols-[minmax(0,2.4fr)_1fr_1.5fr_1.1fr_.9fr_.9fr]";

const PER_PAGE = 10;

type SortKey = "name" | "start" | "day" | "net";

const SORT_OPTIONS: [SortKey, string][] = [
  ["name", "Full name"],
  ["start", "Program start date"],
  ["day", "Current program day"],
  ["net", "Net weight loss"],
];

/**
 * A sortable number for a value that may be unrecorded.
 *
 * `null` sorts as 0, which keeps the comparator a plain subtraction. Note what
 * that gives up: unrecorded rows tie with each other and with a genuine 0, so
 * they land in the middle of the list rather than at one end. That is a
 * deliberate choice — sorting unknowns to an extreme would imply they are the
 * best or worst patients in the clinic, which is a stronger claim than "not
 * measured".
 */
function netLossValue(value: number | null): number {
  return value ?? 0;
}

export function PatientListTab({ rows }: { rows: PatientRow[] }) {
  const [query, setQuery] = useState("");
  const [role, setRole] = useState("");
  const [phase, setPhase] = useState("");
  const [sortKey, setSortKey] = useState<SortKey>("start");
  const [ascending, setAscending] = useState(false);
  const [page, setPage] = useState(1);

  const filtered = useMemo(() => {
    const needle = query.trim().toLowerCase();
    return rows.filter(
      (row) =>
        (!role || row.role === role) &&
        (!phase || row.phase === phase) &&
        (!needle ||
          row.name.toLowerCase().includes(needle) ||
          row.email.toLowerCase().includes(needle)),
    );
  }, [rows, query, role, phase]);

  const sorted = useMemo(() => {
    const direction = ascending ? 1 : -1;
    return [...filtered].sort((a, b) => {
      // Mirrors the design's `cmp`: strings compare with `localeCompare`,
      // everything else numerically. `programStart` is ISO `YYYY-MM-DD`, so it
      // is already chronological as a string.
      if (sortKey === "name") return a.name.localeCompare(b.name) * direction;
      if (sortKey === "start") return a.programStart.localeCompare(b.programStart) * direction;
      if (sortKey === "day") return (a.programDay - b.programDay) * direction;
      return (netLossValue(a.netLoss) - netLossValue(b.netLoss)) * direction;
    });
  }, [filtered, sortKey, ascending]);

  const pageCount = Math.max(1, Math.ceil(sorted.length / PER_PAGE));
  const currentPage = Math.min(page, pageCount);
  const slice = sorted.slice((currentPage - 1) * PER_PAGE, currentPage * PER_PAGE);

  // Any filter change invalidates the page number, or a list that just shrank
  // leaves the reader on a page that no longer exists.
  function resetToFirstPage(update: () => void) {
    update();
    setPage(1);
  }

  function toggleSort(key: SortKey) {
    if (key === sortKey) {
      setAscending((current) => !current);
    } else {
      setSortKey(key);
      // The design sorts every newly chosen column ascending first.
      setAscending(true);
    }
    setPage(1);
  }

  return (
    <>
      <FilterBar>
        <Field label="Patient search" span="lg:col-span-4">
          <SearchBox
            id="patient-search"
            placeholder="Search by name or email..."
            value={query}
            onChange={(value) => resetToFirstPage(() => setQuery(value))}
          />
        </Field>
        <Field label="Role" span="lg:col-span-2">
          <Select
            id="patient-role"
            value={role}
            onChange={(value) => resetToFirstPage(() => setRole(value as PatientRole | ""))}
          >
            <option value="">All roles</option>
            {Object.entries(ROLE_LABELS).map(([value2, label]) => (
              <option key={value2} value={value2}>
                {label}
              </option>
            ))}
          </Select>
        </Field>
        <Field label="Phase" span="lg:col-span-2">
          <Select
            id="patient-phase"
            value={phase}
            onChange={(value) => resetToFirstPage(() => setPhase(value as PhaseCode | ""))}
          >
            <option value="">All phases</option>
            {PHASE_OPTIONS.map(([value2, label]) => (
              <option key={value2} value={value2}>
                {label}
              </option>
            ))}
          </Select>
        </Field>
        <Field label="Sort by" span="lg:col-span-2">
          <Select
            id="patient-sort"
            value={sortKey}
            onChange={(value) => toggleSort(value as SortKey)}
          >
            {SORT_OPTIONS.map(([value2, label]) => (
              <option key={value2} value={value2}>
                {label}
              </option>
            ))}
          </Select>
        </Field>
        <Field label="Order" span="lg:col-span-2">
          <Select
            id="patient-order"
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

      <ResultsCard>
        <GridHead columns={COLUMNS}>
          <SortHeader
            label="Patient"
            active={sortKey === "name"}
            ascending={ascending}
            onSort={() => toggleSort("name")}
          />
          <PlainHeader>Role</PlainHeader>
          <PlainHeader>Phase</PlainHeader>
          <SortHeader
            label="Program start"
            active={sortKey === "start"}
            ascending={ascending}
            onSort={() => toggleSort("start")}
          />
          <PlainHeader>Start weight</PlainHeader>
          <SortHeader
            label="Net loss"
            active={sortKey === "net"}
            ascending={ascending}
            onSort={() => toggleSort("net")}
          />
        </GridHead>

        {slice.length === 0 ? (
          <EmptyRow message="No patients match your filters." />
        ) : (
          slice.map((row) => (
            <GridRow key={row.id} columns={COLUMNS}>
              <div className="col-span-full md:col-span-1">
                <Person id={row.id} name={row.name} email={row.email} />
              </div>
              <Cell label="Role">
                <span className="text-foreground/80">{ROLE_LABELS[row.role]}</span>
              </Cell>
              <Cell label="Phase">
                <PhasePill phase={row.phase} />
              </Cell>
              <Cell label="Program start">
                {row.programStart ? (
                  <>
                    <p className="whitespace-nowrap">{formatDate(row.programStart)}</p>
                    <p className="text-muted-foreground text-xs">Day {row.programDay}</p>
                  </>
                ) : (
                  <span className="text-muted-foreground">—</span>
                )}
              </Cell>
              <Cell label="Start weight">
                <span className="text-foreground/80 tabular-nums">
                  {formatLbs(row.startWeight)}
                </span>
              </Cell>
              <Cell label="Net loss">
                {/* Toned by sign, not size: a loss is the brand colour, a gain is
                    the accent, and "nothing recorded" is muted rather than
                    pretending to be a zero-pound loss. */}
                <span
                  className={`whitespace-nowrap font-medium tabular-nums ${
                    row.netLoss === null
                      ? "text-muted-foreground"
                      : row.netLoss > 0
                        ? "text-primary"
                        : row.netLoss < 0
                          ? "text-flame"
                          : "text-muted-foreground"
                  }`}
                >
                  {row.netLoss === null ? (
                    "—"
                  ) : (
                    <>
                      {row.netLoss > 0 ? "▼ " : ""}
                      {row.netLoss.toFixed(1)} lbs
                    </>
                  )}
                </span>
              </Cell>
            </GridRow>
          ))
        )}

        <Pager
          total={sorted.length}
          page={currentPage}
          pageCount={pageCount}
          perPage={PER_PAGE}
          onPage={setPage}
        />
      </ResultsCard>
    </>
  );
}