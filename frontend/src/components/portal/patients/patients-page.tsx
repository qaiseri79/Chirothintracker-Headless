"use client";

import { useEffect, useRef, useState } from "react";
import { useIntakeCount } from "@/components/providers/intake-count-provider";
import { useAuth } from "@/lib/auth";
import { resolvePortalAccess } from "@/lib/portal";
import { useRouter } from "next/navigation";
import { Lock, Plus } from "lucide-react";
import { enrollmentCapacity } from "@/lib/patients/enrollment";
import { AddPatientTab } from "@/components/portal/patients/add-patient-tab";
import { ArchivedTab } from "@/components/portal/patients/archived-tab";
import { IntakeTab } from "@/components/portal/patients/intake-tab";
import { IntakeView } from "@/components/portal/patients/intake-view";
import { DeleteIntakeDialog } from "@/components/portal/patients/delete-intake-dialog";
import { PatientListTab } from "@/components/portal/patients/patient-list-tab";
import type { ArchivedRow, EnrollmentAllowance, IntakeRow, IntakeStatus, PatientsSnapshot } from "@/lib/patients/types";
import { enrollPatient, PatientAuthError, PatientRequestError, type EnrollPatientInput } from "@/lib/patients/api";

/**
 * The chiropractor's Patients page: one page, four tabs.
 *
 * Transcribed from "New Design/ChiroThin — Patients.html". That design is a
 * single view with `data-view` switching between four views — three lists and a
 * form — behind a shared heading, a shared tab bar and a shared table shell.
 * Everything structural is here: the tab order (Patient List, Archived, Add New
 * Patient, Intake Forms), the per-view heading and blurb, the tab pills, and the
 * active-tab treatment. The bodies are the four sibling components.
 *
 * ## Tabs, not links
 *
 * The design switches views in place without touching the URL, so this holds the
 * selection in state and behaves as an ARIA tablist — arrow keys move between
 * tabs and only the selected tab is in the page tab order. If these four ever
 * need to be linkable or survive a reload, the change is to render `Link`s
 * against `?tab=` and read `searchParams`; the tab order and headings stay.
 *
 * ## Where the rows come from
 *
 * `lib/patients/data.ts` decides that, and this component only renders what it
 * is handed. Every row here is a real account in the chiropractor's clinic,
 * fetched from `GET /api/headless/patients`; a failure to reach it is handled as
 * a failure in the page rather than papered over here.
 */
export interface PatientsPageProps {
  snapshot: PatientsSnapshot;
}

type TabId = "list" | "archived" | "add" | "intake";

interface TabDefinition {
  id: TabId;
  label: string;
  /** The heading above the tab bar, which follows the selected tab. */
  heading: string;
  blurb: string;
  /** Draws the leading plus the design gives the Add tab. */
  isForm?: boolean;
  /** Carries a count pill, if this tab has one. Add New Patient has none. */
  hasCount?: boolean;
  /** Names the extra pill as unreviewed submissions rather than a row count. */
  isReviewQueue?: boolean;
}

const TABS: TabDefinition[] = [
  {
    id: "list",
    label: "Patient List",
    heading: "Patient List",
    blurb: "Everyone currently enrolled in a program.",
    hasCount: true,
  },
  {
    id: "archived",
    label: "Archived",
    heading: "Archived Patients",
    blurb: "Former patients. Re-enroll anyone to bring them back into the program.",
    hasCount: true,
  },
  {
    id: "add",
    label: "Add New Patient",
    heading: "Add New Patient",
    blurb: "Create a patient account and set up their program.",
    isForm: true,
  },
  {
    id: "intake",
    label: "Intake Forms",
    heading: "Intake Forms",
    blurb: "New submissions from your clinic intake link.",
    hasCount: true,
    isReviewQueue: true,
  },
];

export function PatientsPage({ snapshot }: PatientsPageProps) {
  const { user } = useAuth();
  const readOnly = resolvePortalAccess(user?.roles, user?.capabilities, user?.portalAccess)?.readOnly ?? true;
  const { setIntakeCount } = useIntakeCount();
  const [reviewedIntake, setReviewedIntake] = useState<{
    source: PatientsSnapshot;
    rows: IntakeRow[];
  } | null>(null);
  const intakeRows = reviewedIntake?.source === snapshot ? reviewedIntake.rows : snapshot.intake;
  const [active, setActive] = useState<TabId>("list");
  /**
   * The message shown under the heading, or null.
   *
   * Carries three things, all of which are the page's to report rather than a row's:
   * the soft enrollment-limit warning a create or re-enrol came back with, and the
   * refusal when a re-enrol hits the hard limit. One place to read it, and it survives
   * the switch to another tab — a per-row message on a tab the chiropractor has left
   * is a message nobody reads.
   */
  const [notice, setNotice] = useState<string | null>(null);
  /**
   * The intake submission the Add form is currently filled in from, if any.
   *
   * Held here rather than in the tab because the tab does not choose its own
   * starting point — the intake table does. `null` means the Add form opens blank,
   * which is the Add-tab case.
   */
  const [intakePrefill, setIntakePrefill] = useState<IntakeRow | null>(null);
  /**
   * The clinic's intake link once this session has generated or replaced one.
   *
   * `null` means "still whatever the server said". The link arrives on the snapshot,
   * which is a server prop and not ours to mutate, and generating a new one has to
   * show the result without a reload — so this holds the override and the tab reads
   * `this ?? snapshot.intakeLink`.
   *
   * Held here rather than in the tab because the tab unmounts on a tab switch, and a
   * chiropractor who generates a link and comes back should find it rather than be
   * offered the button again.
   */
  const [intakeLinkOverride, setIntakeLinkOverride] = useState<string | null>(null);
  const tabRefs = useRef<Record<string, HTMLButtonElement | null>>({});
  const router = useRouter();

  const activeTab = TABS.find((tab) => tab.id === active) ?? TABS[0];
  const AddIcon = enrollmentCapacity(snapshot.enrollmentAllowance).full ? Lock : Plus;

  const counts: Partial<Record<TabId, number>> = {
    list: snapshot.active.length,
    archived: snapshot.archived.length,
    intake: snapshot.intake.length,
  };

  // Counted by the endpoint, so the badge and the backend's own view of what is
  // unreviewed cannot drift apart. Equal to filtering the rows when the whole
  // list has been fetched, and correct when it has not.
  const originalStatuses = new Map(snapshot.intake.map((row) => [row.id, row.status]));
  const intakeNewCount = Math.max(0, snapshot.intakeNewCount + intakeRows.reduce(
    (change, row) => change + Number(row.status === "new") - Number(originalStatuses.get(row.id) === "new"),
    0,
  ));
  useEffect(() => {
    setIntakeCount(intakeNewCount);
  }, [intakeNewCount, setIntakeCount]);

  function handleIntakeReviewed(ids: number[], status: IntakeStatus) {
    const accepted = new Set(ids);
    setReviewedIntake((current) => ({
      source: snapshot,
      rows: (current?.source === snapshot ? current.rows : snapshot.intake)
        .map((row) => accepted.has(row.id) ? { ...row, status } : row),
    }));
  }

  // Checked against every account already in hand, so the duplicate-email check
  // on the Add form covers the archived list too — an address that was archived
  // still has a user record behind it.
  const existingEmails = [
    ...snapshot.active.map((row) => row.email),
    ...snapshot.archived.map((row) => row.email),
  ].map((email) => email.toLowerCase());

  /**
   * A patient was created: move to the list and reload it.
   *
   * `router.refresh()` rather than pushing the new row into local state. The rows
   * here come from a server component, so this prop is not ours to mutate, and the
   * counts and the header pill are derived from it — a local insert would show the
   * new patient while leaving every count stale. Refresh re-runs the server
   * component, which re-queries and hands back a consistent snapshot.
   *
   * Not awaited: `refresh()` returns void, so there is no completion to wait for
   * and nothing to block the tab switch on. It is fired and the switch happens.
   */
  function handleCreated(warning: string | null) {
    setNotice(warning);
    setActive("list");
    // The submission has been consumed and the account exists, so the next visit to
    // the Add tab should be a fresh blank form rather than the row just enrolled.
    setIntakePrefill(null);
    router.refresh();
  }

  /**
   * Opens the Add form filled in from an intake row.
   *
   * This is the whole of "one form, two places": Enroll on the intake table does not
   * have its own form or its own endpoint. It opens the same form with the values the
   * submission already carries — start date included — and that form posts to the same
   * endpoint it posts to when it was typed by hand.
   */
  function handleEnrollFromIntake(row: IntakeRow) {
    setIntakePrefill(row);
    setActive("add");
  }

  /**
   * The row the chiropractor opened, for "View enroll information".
   *
   * Held here rather than in the tab so the view outlives the click and so the
   * tab keeps its filters and page while it is open — the tab owns the list, this
   * owns what is being looked at.
   */
  const [viewingIntake, setViewingIntake] = useState<IntakeRow | null>(null);

  /** The row pending deletion, for the confirm dialog. `null` when closed. */
  const [deletingIntake, setDeletingIntake] = useState<IntakeRow | null>(null);

  /**
   * Deletion refreshes the snapshot inside `DeleteIntakeDialog`, which owns the
   * request. All this does is let the dialog go, so the confirmed row cannot be
   * reopened afterwards from stale state.
   */
  function handleIntakeDeleted() {
    setDeletingIntake(null);
  }

  /**
   * Re-enrols an archived patient.
   *
   * The other operation, and genuinely a different one: the account exists, so there
   * is no form and nothing to collect — except the clinic location, which the
   * Archived tab asks for because re-enrolling used to leave the patient on whichever
   * location they already had and most archived patients have none. It still spends a
   * slot from the clinic's enrollment cap, so it can come back 409 exactly as a create
   * can.
   *
   * `changes` carries only the fields the confirmation form holds a value for, and
   * is passed straight through rather than being defaulted here. The endpoint reads
   * an absent key as "leave this field alone", so the rule about what a blank field
   * means belongs to the form that renders the blank, not to a component that cannot
   * see it.
   */
  async function handleReenroll(row: ArchivedRow, changes: EnrollPatientInput) {
    setNotice(null);
    try {
      const { warning } = await enrollPatient(row.id, changes);
      setNotice(warning);
      setActive("list");
      router.refresh();
    } catch (error) {
      // Kept in the same banner as the soft-limit notice rather than a per-row
      // error, so the chiropractor reads one thing in one place. 409 lands here too:
      // being at the cap is the answer, and the endpoint's own wording says what to
      // do about it better than anything written here.
      setNotice(
        error instanceof PatientRequestError || error instanceof PatientAuthError
          ? error.message
          : "Unable to reach the server. Please try again.",
      );
    }
  }

  /**
   * Every route onto a tab goes through here.
   *
   * Choosing "Add New Patient" from the tab bar has to mean the *blank* form. Without
   * this, enrolling one intake row and then clicking the tab would still show that
   * row's values, and the chiropractor would create a second patient from it.
   */
  function selectTab(id: TabId) {
    setActive(id);
    if (id === "add") setIntakePrefill(null);
  }

  /**
   * Arrow keys move between tabs, Home and End jump to the ends.
   *
   * Selection follows focus rather than waiting for Enter or Space. That is the
   * recommended behaviour when switching panels is cheap, and these panels are all
   * already in memory; the automatic pattern stops being right when a panel
   * fetches, at which point this should move focus and let the reader activate.
   */
  function handleKeyDown(event: React.KeyboardEvent<HTMLDivElement>) {
    const index = TABS.findIndex((tab) => tab.id === active);
    let next: number;

    switch (event.key) {
      case "ArrowRight":
        next = (index + 1) % TABS.length;
        break;
      case "ArrowLeft":
        next = (index - 1 + TABS.length) % TABS.length;
        break;
      case "Home":
        next = 0;
        break;
      case "End":
        next = TABS.length - 1;
        break;
      default:
        return;
    }

    event.preventDefault();
    const id = TABS[next].id;
    selectTab(id);
    tabRefs.current[id]?.focus();
  }

  return (
    <div>
      <div className="mb-5">
        <h2 className="font-serif text-2xl text-foreground">{activeTab.heading}</h2>
        <p className="mt-1 text-muted-foreground text-sm">{activeTab.blurb}</p>
      </div>

      {/* Shown here rather than on the form or the row that produced it: after a create or
          a re-enrol the chiropractor has been moved to the list, and a message left on
          a tab they have left would never be read. Dismissible, because it stays true
          until they change their plan rather than until they have looked at it once.
          `role="status"` rather than `alert` because it is the page's own report and
          should not interrupt whatever the screen reader is currently saying. */}
      {notice ? (
        <p
          role="status"
          className="mb-5 flex items-start justify-between gap-4 rounded-lg bg-flame-soft px-4 py-3 text-flame text-sm"
        >
          <span>{notice}</span>
          <button
            type="button"
            onClick={() => setNotice(null)}
            aria-label="Dismiss"
            className="shrink-0 font-semibold"
          >
            Dismiss
          </button>
        </p>
      ) : null}

      <div
        role="tablist"
        aria-label="Patient sections"
        onKeyDown={handleKeyDown}
        className="mb-5 flex gap-1 overflow-x-auto border-b border-line"
      >
        {TABS.map((tab) => {
          const selected = tab.id === active;
          const count = counts[tab.id];
          const reviewCount = tab.isReviewQueue ? intakeNewCount : 0;

          return (
            <button
              key={tab.id}
              ref={(node) => {
                tabRefs.current[tab.id] = node;
              }}
              type="button"
              role="tab"
              id={`patients-tab-${tab.id}`}
              aria-selected={selected}
              aria-controls="patients-panel"
              // Roving tabindex: one stop for the whole bar, so Tab moves past
              // the tabs rather than through all four.
              tabIndex={selected ? 0 : -1}
              onClick={() => selectTab(tab.id)}
              className={[
                "flex shrink-0 items-center gap-2 border-b-2 px-3.5 py-2.5 font-medium text-sm transition-colors",
                selected
                  ? "border-primary text-primary"
                  : "border-transparent text-muted-foreground hover:text-foreground",
              ].join(" ")}
            >
              {tab.isForm ? <AddIcon className="size-3.5" aria-hidden="true" /> : null}
              {tab.label}
              {/* A zero is not a count, it is the absence of one. The pill's whole job is to
                  say how many rows are waiting behind the tab; drawing "0" makes an
                  empty tab look like something that needs attention, which is the
                  opposite of what an empty clinic is. Guarded like the "N new"
                  marker below it. */}
              {tab.hasCount && count != null && count > 0 ? (
                <Pill active={selected}>{count}</Pill>
              ) : null}
              {reviewCount > 0 ? (
                <span className="inline-flex h-5 min-w-5 items-center gap-1 rounded-full bg-flame-soft px-1.5 font-semibold text-[11px] text-flame">
                  <span className="size-1.5 rounded-full bg-flame" aria-hidden="true" />
                  {reviewCount} new
                </span>
              ) : null}
            </button>
          );
        })}
      </div>

      <div
        role="tabpanel"
        id="patients-panel"
        aria-labelledby={`patients-tab-${active}`}
        tabIndex={0}
      >
        {active === "list" ? <PatientListTab rows={snapshot.active} /> : null}
        {active === "archived" ? (
          <ArchivedTab
            rows={snapshot.archived}
            clinicLocations={snapshot.clinicLocations}
            onReenroll={handleReenroll}
          />
        ) : null}
        {active === "add" ? (
          // Keyed on the submission so opening a different intake row mounts a fresh
          // form. React reuses the instance for the same key, and the form reads its
          // initial values once on mount, so without this the second Enroll would show
          // the first row's values.
          <AddPatientTab
            key={intakePrefill?.id ?? "blank"}
            existingEmails={existingEmails}
            allowance={snapshot.enrollmentAllowance}
            enrolledCount={snapshot.enrolledCount}
            onPatientList={() => selectTab("list")}
            onRefresh={() => router.refresh()}
            clinicLocations={snapshot.clinicLocations}
            prefill={intakePrefill ?? undefined}
            onCreated={handleCreated}
          />
        ) : null}
        {active === "intake" ? (
          <IntakeTab
            rows={intakeRows}
            onReviewed={handleIntakeReviewed}
            intakeLink={intakeLinkOverride ?? snapshot.intakeLink}
            onIntakeLinkChange={setIntakeLinkOverride}
            onEnroll={handleEnrollFromIntake}
            onView={setViewingIntake}
            onDelete={setDeletingIntake}
          />
        ) : null}

        {/* Both dialogs sit outside the tab so they survive a tab switch and are
            not unmounted by it — closing a tab is not a reason to throw away a
            half-read health screening or a pending confirmation. */}
        <IntakeView
          submissionId={viewingIntake?.id ?? null}
          open={viewingIntake !== null}
          onClose={() => setViewingIntake(null)}
        />
        <DeleteIntakeDialog
          submissionId={readOnly ? null : deletingIntake?.id ?? null}
          patientName={deletingIntake?.name ?? ""}
          onClose={handleIntakeDeleted}
        />
      </div>
    </div>
  );
}

/**
 * The header pill from the design's `head()`.
 *
 * Lives in the shell header, not in this page, so it is exported separately and
 * handed to `PortalShell` as `headerAside` by the route layout.
 */
export function PatientsHeaderPill({ enrolled, allowance }: { enrolled: number; allowance: EnrollmentAllowance | null }) {
  const { user } = useAuth();
  const plan = user?.subscription?.plan;
  const limitLabel = allowance ? allowance.limit === null ? "Unlimited" : String(allowance.limit) : plan ? plan.patientLimit === null ? "Unlimited" : String(plan.patientLimit) : "—";
  return (
    <div className="hidden items-center gap-2 rounded-full border border-flame/40 px-3 py-1 text-[11px] sm:flex">
      <span className="text-muted-foreground">Subscription</span>
      <b className="text-foreground">{limitLabel}</b>
      <span aria-hidden="true" className="text-muted-foreground/40">
        |
      </span>
      <span className="text-muted-foreground">Enrolled</span>
      <b className="text-foreground tabular-nums">{enrolled}</b>
    </div>
  );
}

/** The count pill the design puts beside a tab label. */
function Pill({ active, children }: { active: boolean; children: React.ReactNode }) {
  return (
    <span
      className={[
        "inline-flex h-5 min-w-5 items-center justify-center rounded-full px-1.5 font-semibold tabular-nums text-[11px]",
        active ? "bg-primary-soft text-primary" : "bg-canvas text-muted-foreground",
      ].join(" ")}
    >
      {children}
    </span>
  );
}