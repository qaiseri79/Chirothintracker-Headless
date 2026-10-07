"use client";

import { useAuth } from "@/lib/auth";
import { resolvePortalAccess } from "@/lib/portal";
import { useMemo, useState } from "react";
import { Info, NotebookPen } from "lucide-react";
import {
  Actions,
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
} from "@/components/portal/patients/patients-ui";
import { ReenrollForm } from "@/components/portal/patients/reenroll-form";
import {
  ROLE_LABELS,
  type ArchivedRow,
  type ClinicLocation,
  type PatientRole,
} from "@/lib/patients/types";
import type { EnrollPatientInput } from "@/lib/patients/api";

/**
 * Archived — former patients, re-enrollable.
 *
 * The design's `archRows` / `viewArch`. Four columns, and the gap against the
 * patient list's seven is the whole point: an archived account has no program to
 * report on, so there is no phase, no weights and no net loss — only when it
 * enrolled and how far it got.
 *
 * Note the Program start and Program day are *separate* columns here, where the
 * active list stacks the day under the date. That is the design's choice, not an
 * oversight: with no phase or weight columns competing, the extra column fits
 * comfortably, and the two values sort independently.
 *
 * ## Why there is a Role filter but no Role column
 *
 * The design offered "All / Enrolled Patient / Archived Patient" here, which was
 * one filter expressing the tab it was already on. The roster's filter is now by
 * role — Patient, Health Coach, Clinic Doctor — and this tab shares the same
 * `row.role` value, so the same filter is meaningful on both without inventing a
 * second vocabulary.
 *
 * The column stays away. Archiving only ever swaps `enrolled_patient` for
 * `archived_patient`, so every row here already holds `patient`: a column reading
 * "Patient" down the page would be a column of constants. The filter earns its
 * place by narrowing, the column would not.
 *
 * `ARCH` in the design starts at id 100 and the active list at 1, so the two
 * never collide when the design's `archive` handler moves a row between them.
 *
 * **Re-enroll is live**, and it opens the patient's details for confirmation before it
 * does anything. It posts to the endpoint's own re-enrollment route, which swaps
 * `archived_patient` for `enrolled_patient` on an account that already exists. The
 * design moves the row in memory optimistically, which would diverge the moment the
 * page reloads, so the row only leaves this tab after the server has confirmed the
 * swap.
 *
 * The confirmation step is not a location question, it is the patient's whole record:
 * name, email, phone, program start, weights, phase, location and email preference.
 * It started as a single location dropdown and grew to this, because re-enrolling is
 * the moment a returning patient's record is next looked at — the chiropractor is
 * opening this patient anyway, so the fields are there to be checked rather than
 * retyped from memory later. Which branch a returning patient belongs to remains the
 * one thing that genuinely cannot be inferred, which is why the location control sits
 * alongside the rest rather than instead of them.
 *
 * Every field is prefilled from the archived row, and a blank field is left alone on
 * submit rather than being written as empty. See `reenroll-form.tsx` for why that
 * makes confirming an untouched form safe.
 *
 * **Still inert:** Patient details and Patient notes, which need read paths that do
 * not exist yet.
 */

const COLUMNS = "grid-cols-1 md:grid-cols-[minmax(0,2.4fr)_1.2fr_1fr_auto]";

type SortKey = "name" | "start" | "day";

const SORT_OPTIONS: [SortKey, string][] = [
  ["name", "Full name"],
  ["start", "Program start date"],
  ["day", "Current program day"],
];

const PER_PAGE = 10;

export function ArchivedTab({
  rows,
  clinicLocations,
  onReenroll,
}: {
  rows: ArchivedRow[];
  /**
   * The caller's own clinic locations, from the snapshot.
   *
   * Empty means the practice has no branches configured, and the re-enrollment step
   * collapses back to a single click — there would be nothing to choose between.
   */
  clinicLocations: ClinicLocation[];
  /**
   * Re-enrols this archived patient, carrying any edits made on the confirmation
   * form.
   *
   * `changes` holds only the fields that carry a value; an absent key is the
   * endpoint's "leave this field alone", so a form submitted without edits re-enrols
   * the patient as they were.
   */
  onReenroll: (row: ArchivedRow, changes: EnrollPatientInput) => void;
}) {
  const [query, setQuery] = useState("");
  const { user } = useAuth();
  const readOnly = resolvePortalAccess(user?.roles, user?.capabilities, user?.portalAccess)?.readOnly ?? true;
  const [role, setRole] = useState("");
  const [sortKey, setSortKey] = useState<SortKey>("name");
  const [ascending, setAscending] = useState(true);
  const [page, setPage] = useState(1);
  // The row being confirmed. The row itself rather than its id, because the form
  // needs the whole record to prefill from — and holding the object means the value
  // cannot outlive the row it belongs to, so opening a second row starts from that
  // patient's own details.
  const [confirming, setConfirming] = useState<ArchivedRow | null>(null);

  function startReenroll(row: ArchivedRow) {
    if (readOnly) return;
    setConfirming(row);
  }

  function finishReenroll(changes: EnrollPatientInput) {
    if (confirming === null) return;
    const row = confirming;
    // Cleared rather than left set, so the row closes the moment the parent starts
    // the request. The parent's own pending state takes over from here.
    setConfirming(null);
    onReenroll(row, changes);
  }

  const sorted = useMemo(() => {
    const needle = query.trim().toLowerCase();
    const direction = ascending ? 1 : -1;

    return rows
      .filter(
        (row) =>
          (!role || row.role === role) &&
          (!needle ||
            row.name.toLowerCase().includes(needle) ||
            row.email.toLowerCase().includes(needle)),
      )
      .sort((a, b) => {
        if (sortKey === "name") return a.name.localeCompare(b.name) * direction;
        // `start` is ISO `YYYY-MM-DD`, so a string compare is already
        // chronological and skips constructing 26 `Date`s to order them.
        if (sortKey === "start") return a.programStart.localeCompare(b.programStart) * direction;
        return (a.programDay - b.programDay) * direction;
      });
  }, [rows, query, role, sortKey, ascending]);

  const pageCount = Math.max(1, Math.ceil(sorted.length / PER_PAGE));
  const currentPage = Math.min(page, pageCount);
  const slice = sorted.slice((currentPage - 1) * PER_PAGE, currentPage * PER_PAGE);

  function toggleSort(key: SortKey) {
    if (key === sortKey) {
      setAscending((current) => !current);
    } else {
      setSortKey(key);
      // The design's `data-sort` handler resets the direction to ascending
      // whenever the sorted column changes, which stops a new column inheriting
      // the previous one's polarity.
      setAscending(true);
    }
    setPage(1);
  }

  return (
    <>
      <FilterBar>
        <Field label="Name or email" span="lg:col-span-6">
          <SearchBox
            id="archived-search"
            placeholder="Search archived patients..."
            value={query}
            onChange={(value) => {
              setQuery(value);
              setPage(1);
            }}
          />
        </Field>
        <Field label="Role" span="lg:col-span-2">
          <Select
            id="archived-role"
            value={role}
            onChange={(value) => {
              setRole(value as PatientRole | "");
              setPage(1);
            }}
          >
            <option value="">All roles</option>
            {Object.entries(ROLE_LABELS).map(([value, label]) => (
              <option key={value} value={value}>
                {label}
              </option>
            ))}
          </Select>
        </Field>
        <Field label="Sort by" span="lg:col-span-2">
          <Select
            id="archived-sort"
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
            id="archived-order"
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
          <SortHeader
            label="Program start"
            active={sortKey === "start"}
            ascending={ascending}
            onSort={() => toggleSort("start")}
          />
          <SortHeader
            label="Program day"
            active={sortKey === "day"}
            ascending={ascending}
            onSort={() => toggleSort("day")}
          />
          <PlainHeader>
            <span className="sr-only">Actions</span>
          </PlainHeader>
        </GridHead>

        {slice.length === 0 ? (
          <EmptyRow message="No archived patients found." />
        ) : (
          slice.map((row) => (
            <GridRow key={row.id} columns={COLUMNS}>
              <div className="col-span-full md:col-span-1">
                <Person id={row.id} name={row.name} email={row.email} />
              </div>
              <Cell label="Program start">
                {row.programStart ? (
                  <span className="whitespace-nowrap">{formatDate(row.programStart)}</span>
                ) : (
                  <span className="text-muted-foreground">—</span>
                )}
              </Cell>
              <Cell label="Program day">
                {row.programDay ? (
                  <span className="tabular-nums">Day {row.programDay}</span>
                ) : (
                  <span className="text-muted-foreground">—</span>
                )}
              </Cell>
              <Actions>
                <EnrollButton
                  onClick={() => startReenroll(row)}
                  label="Re-enroll"
                  disabled={readOnly}
                />
                <IconAction icon={Info} label="Patient details" />
                <IconAction icon={NotebookPen} label="Patient notes" />
              </Actions>
              {!readOnly && confirming?.id === row.id ? (
                /* Inline rather than a modal: there is no dialog primitive here, and
                   a panel in the row it belongs to is both less code and less
                   disruptive on a table the chiropractor is scanning. `col-span-full`
                   puts it on its own line under the row instead of squeezing the
                   fields into the actions cell. */
                <ReenrollForm
                  key={row.id}
                  row={row}
                  clinicLocations={clinicLocations}
                  onConfirm={finishReenroll}
                  onCancel={() => setConfirming(null)}
                />
              ) : null}
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