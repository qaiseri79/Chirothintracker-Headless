"use client";

import { useAuth } from "@/lib/auth";
import { resolvePortalAccess } from "@/lib/portal";
import { useId, useState } from "react";
import {
  CARD,
  Field,
  FormTextField as TextField,
  FormToggle,
  FormWeightField,
  INPUT,
  PersonAvatar,
  Select,
  formatDate,
} from "@/components/portal/patients/patients-ui";
import {
  PHASE_CLASSES,
  PHASE_OPTIONS,
  type ClinicLocation,
  type IntakeRow,
  type PhaseCode,
} from "@/lib/patients/types";
import {
  createPatient,
  PatientAuthError,
  PatientRequestError,
} from "@/lib/patients/api";
import { weightProblem } from "@/lib/patients/weight";
import type { EnrollmentAllowance } from "@/lib/patients/types";
import { enrollmentCapacity } from "@/lib/patients/enrollment";
import { PatientEnrollmentAllowance, EnrollmentLimitCard } from "./enrollment-allowance";

/**
 * Add New Patient — create an account and start its program.
 *
 * The design's `viewAdd` / `paintForm` / `bindAdd`. Two pieces: a form, and a
 * Summary card that repaints on every keystroke so the chiropractor can read back
 * what they are about to create before committing to it.
 *
 * ## Submitting
 *
 * `POST /api/patients` → `POST /api/headless/patients`, which creates the account,
 * enrols them, runs the legacy program-day setup and mails them their login link.
 * The design's handler instead unshifted a row into the in-memory `ACTIVE` array and
 * switched to the list, which looks identical and evaporates on reload — the
 * clinician would be looking for a patient account the backend never received. So
 * the list is only updated once the server has confirmed the write, via `onCreated`.
 *
 * ## The duplicate-email check
 *
 * It runs twice, and the client-side one is the optimistic half. It can only see
 * the rows already in hand, so it catches the common case instantly and costs
 * nothing; the endpoint's own clinic-scoped check is the one that decides, because
 * it can see accounts this page has not loaded.
 */

/**
 * The design labels `phase-0` "Weight Loss" in the *form's* status select while
 * calling the same phase "Losing Phase" in the list's Phase column and pill.
 * Both are transcribed as-is; see `types.ts` on `SetLockField.php`.
 */
const STATUS_OPTIONS: [PhaseCode, string][] = PHASE_OPTIONS.map(([code, label]) => [
  code,
  code === "L" ? "Weight Loss" : label,
]);

function todayIso(): string {
  const now = new Date();
  const pad = (value: number) => String(value).padStart(2, "0");
  return `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}`;
}

interface FormState {
  name: string;
  email: string;
  phone: string;
  start: string;
  goal: string;
  /**
   * The chosen location's id, or `""` for none.
   *
   * A string rather than `number | null` because that is what the `Select` works in,
   * and `""` is the value of its "not chosen" option. It is never sent as-is:
   * {@link clinicLocation} maps `""` to `undefined`, which the payload builder omits.
   */
  clinic: string;
  phase: PhaseCode;
  notifications: boolean;
}

const BLANK: FormState = {
  name: "",
  email: "",
  phone: "",
  start: "",
  goal: "",
  clinic: "",
  phase: "L",
  notifications: true,
};

type Errors = Partial<Record<"name" | "email" | "phone" | "start" | "goal", string>>;

/**
 * The form filled in from an intake submission.
 *
 * This is the second way the same form is opened. Everything the intake table
 * already knows is placed in its field, including the start date, so the chiropractor
 * confirms what the patient submitted rather than retyping it — and so the value that
 * gets saved is the one that was submitted, not a fresh "next Saturday".
 *
 * The two fields intake cannot supply are left at their defaults rather than guessed
 * at: phase defaults to the form's own starting phase, and notifications to opted-in.
 * Both remain editable, so a default here is a starting point and not a decision.
 *
 * Clinic location is the third such field and is left unset for the same reason: an
 * intake submission is not associated with a branch, and guessing one from the
 * chiropractor's clinic would be inventing a fact about where the patient is treated.
 */
function fromIntake(row: IntakeRow): FormState {
  const fullName = row.name?.trim() || [row.firstName, row.lastName].filter(Boolean).join(" ");

  return {
    name: fullName,
    email: row.email?.trim() ?? "",
    phone: row.phone ?? "",
    // The intake table sends a Drupal `created` value, `YYYY-MM-DD HH:mm`. The date
    // input wants the date alone and silently ignores the rest, so it is trimmed
    // here rather than left to produce an empty field.
    start: (row.programStart ?? "").slice(0, 10),
    // 0 is the table's way of saying the submission left it blank, not a goal of zero.
    goal: row.goalWeight ? String(row.goalWeight) : "",
    clinic: "",
    phase: BLANK.phase,
    notifications: BLANK.notifications,
  };
}

export function AddPatientTab({
  existingEmails,
  allowance,
  enrolledCount,
  onPatientList,
  onRefresh,
  clinicLocations,
  prefill,
  onCreated,
}: {
  existingEmails: string[];
  allowance: EnrollmentAllowance | null;
  enrolledCount: number;
  onPatientList: () => void;
  onRefresh: () => void;
  /**
   * The caller's own clinic locations, from the snapshot.
   *
   * Empty renders no control at all rather than an empty one: a clinic with no
   * `clinic_location` entities has nothing to choose between, and a select with a
   * single "None" would be a field that cannot be filled in.
   */
  clinicLocations: ClinicLocation[];
  /**
   * The intake submission to fill the form from, when it was opened from one.
   *
   * Omitted on the Add New Patient tab, where the form starts blank. The parent
   * remounts this component per submission (via `key`) rather than syncing the state
   * afterwards, so opening a second intake row cannot leave values from the first
   * behind in a field that submission did not have.
   */
  prefill?: IntakeRow;
  /**
   * Called once the endpoint confirms the patient exists, with the soft quota
   * warning if there was one.
   */
  onCreated: (warning: string | null) => void;
}) {
  const [form, setForm] = useState<FormState>(() =>
    prefill ? fromIntake(prefill) : { ...BLANK, start: todayIso() },
  );
  const [errors, setErrors] = useState<Errors>({});
  const [notice, setNotice] = useState<string | null>(null);
  const [pending, setPending] = useState(false);
  const id = useId();
  const { user } = useAuth();
  const access = resolvePortalAccess(user?.roles, user?.capabilities, user?.portalAccess);
  const readOnly = access?.readOnly ?? true;
  const canManageBilling = user?.portalAccess?.canManageBilling === true;
  const { full } = enrollmentCapacity(allowance);

  function update<K extends keyof FormState>(key: K, value: FormState[K]) {
    setForm((current) => ({ ...current, [key]: value }));
    // Clear a field's error as soon as it is edited; leaving it red while the
    // clinician is mid-fix is just noise.
    if (key in errors) setErrors((current) => omitError(current, key as keyof Errors));
    setNotice(null);
  }

  /**
   * The clinic location, or undefined when none is chosen.
   *
   * `""` — the "None" option — becomes `undefined` so the key is left out of the
   * payload entirely, and the endpoint's `?? NULL` reads that as "no choice made"
   * and does not touch `field_clinic_location`. Sending `""` instead would be
   * rejected: the field's value has to be a location id, and an empty string is
   * not one.
   */
  function clinicLocation(): number | undefined {
    return form.clinic === "" ? undefined : Number(form.clinic);
  }

  function validate(): Errors {
    const found: Errors = {};

    if (!form.name.trim()) {
      found.name = "Enter the patient's full name.";
    }

    if (!/^\S+@\S+\.\S+$/.test(form.email.trim())) {
      found.email = "Enter a valid email address.";
    } else if (existingEmails.includes(form.email.trim().toLowerCase())) {
      found.email = "A patient with this email already exists.";
    }

    if (form.phone.replace(/\D/g, "").length < 7) {
      found.phone = "Enter a valid phone number.";
    }

    // Blank stays legal: the endpoint reads an absent start as "next Saturday", which
    // is the cohort the patient would actually join. Only a date that was typed gets
    // judged, because a blank one is a decision not yet made rather than a wrong one.
    //
    // A date this form did not choose is exempt too. Opened from an intake
    // submission the field is prefilled with what the patient asked for, and a
    // submission made before their start date arrived is the normal case, not a
    // mistake — so the same rule as re-enrolling applies: judge the change, not the
    // value. Touching the date is what makes it the chiropractor's answer.
    const submittedStart = (prefill?.programStart ?? "").slice(0, 10);
    if (form.start !== "" && form.start !== submittedStart && form.start < todayIso()) {
      found.start = "Program start cannot be in the past.";
    }

    // A goal weight is a number of pounds a person is going to reach, so unlike a
    // recorded measurement it has to be real: over 0 and inside the bound. The bound
    // lives in `lib/patients/weight.ts` so this cannot drift from the endpoint's.
    if (!(Number(form.goal) > 0)) {
      found.goal = "Enter a goal weight in lbs.";
    } else {
      const problem = weightProblem(Number(form.goal), "Goal weight");
      if (problem) found.goal = problem;
    }

    return found;
  }

  async function onSubmit(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (readOnly || full || pending) return;
    const found = validate();
    setErrors(found);

    const firstField = Object.keys(found)[0];
    if (firstField) {
      document.getElementById(`${id}-${firstField}`)?.focus();
      return;
    }

    setNotice(null);
    setPending(true);

    try {
      const { warning: quotaWarning } = await createPatient({
        name: form.name.trim(),
        email: form.email.trim(),
        phone: form.phone.trim() || undefined,
        programStart: form.start || undefined,
        goalWeight: form.goal ? Number(form.goal) : undefined,
        emailNotifications: form.notifications,
        phase: form.phase,
        clinicLocation: clinicLocation(),
        // Only on the prefilled path. This is what links the new account back to the
        // submission and flags it as processed; sending it from the blank form would
        // flag an arbitrary submission the chiropractor never looked at.
        intakeSubmission: prefill?.id,
      });

      // The patient exists. Reset before handing over, so returning to this tab
      // shows a clean form rather than the one that was just submitted — blank even
      // when it was prefilled, because those values are now on the account.
      setForm({ ...BLANK, start: todayIso() });
      setErrors({});

      // A soft quota notice is not a failure, and the chiropractor is being moved to
      // the list where they would never read a message left behind on a form they
      // have just left. So the patient is shown first and the warning is handed to
      // the page, which owns the tab and can surface it where it is still seen.
      onCreated(quotaWarning);
    } catch (error) {
      if (error instanceof PatientAuthError) {
        setNotice(error.message);
        return;
      }

      if (error instanceof PatientRequestError) {
        if (error.status === 409) onRefresh();
        // The endpoint's own per-field messages win over the local ones: they are
        // the rules that actually decided, and they cover fields this form does not
        // even render. Anything the local pass caught stays until it is edited.
        const fromServer = Object.keys(error.fields).filter(
          (key): key is keyof Errors => key in { name: 1, email: 1, phone: 1, goal: 1 },
        );
        if (fromServer.length > 0) {
          setErrors((current) => ({ ...current, ...pick(error.fields, fromServer) }));
          document.getElementById(`${id}-${fromServer[0]}`)?.focus();
        }
        setNotice(error.message);
        return;
      }

      setNotice("Unable to reach the server. Please try again.");
    } finally {
      setPending(false);
    }
  }

  const statusLabel = STATUS_OPTIONS.find(([code]) => code === form.phase)?.[1] ?? "";

  if (full && allowance) {
    return (
      <div>
        <PatientEnrollmentAllowance allowance={allowance} enrolledCount={enrolledCount} canManageBilling={canManageBilling} />
        <EnrollmentLimitCard allowance={allowance} canManageBilling={canManageBilling} onPatientList={onPatientList} />
      </div>
    );
  }

  return (
    <div>
      <PatientEnrollmentAllowance allowance={allowance} enrolledCount={enrolledCount} canManageBilling={canManageBilling} />
    <div className="grid gap-5 lg:grid-cols-3">
      <form onSubmit={onSubmit} noValidate className={`${CARD} space-y-5 p-5 sm:p-6 lg:col-span-2`}>
        <fieldset disabled={readOnly || pending} className="min-w-0 space-y-5">
        {/* Says where the values came from. Without it this form looks identical to the
            blank one from the Add tab, and the chiropractor has no way to tell that
            pressing Create is about to enrol the person in the intake row they just
            clicked — or that a field left empty is empty because intake left it empty. */}
        {prefill ? (
          <p className="flex items-start gap-2 rounded-lg bg-primary-soft px-3 py-2.5 text-primary text-sm">
            <span>
              Filled in from intake submission <b>#{prefill.id}</b>
              {prefill.submitted ? `, received ${formatDate(prefill.submitted)}` : ""}. Check
              anything the patient left blank before you create the account.
            </span>
          </p>
        ) : null}

        <SectionLabel>Patient details</SectionLabel>

        <TextField
          id={`${id}-name`}
          label="Patient full name"
          value={form.name}
          onChange={(value) => update("name", value)}
          error={errors.name}
          autoComplete="off"
          required
        />

        <div className="grid gap-5 sm:grid-cols-2">
          <TextField
            id={`${id}-email`}
            label="Email address"
            type="email"
            value={form.email}
            onChange={(value) => update("email", value)}
            error={errors.email}
            required
          />
          <TextField
            id={`${id}-phone`}
            label="Phone number"
            type="tel"
            value={form.phone}
            onChange={(value) => update("phone", value)}
            error={errors.phone}
            required
          />
        </div>

        {/* Shared with the re-enrollment form, which edits the same preference on the
            same record — one control means the two can never disagree about what
            "Enabled" means. */}
        <FormToggle
          legend="Daily email notifications"
          value={form.notifications}
          onChange={(value) => update("notifications", value)}
        />

        <hr className="border-line" />
        <SectionLabel>Program</SectionLabel>

        <div className="grid gap-5 sm:grid-cols-2">
          <Field
            label="Program start date"
            variant="form"
            error={errors.start}
            errorId={`${id}-start-error`}
          >
            <input
              id={`${id}-start`}
              type="date"
              value={form.start}
              onChange={(event) => update("start", event.target.value)}
              aria-invalid={errors.start ? true : undefined}
              aria-describedby={errors.start ? `${id}-start-error` : undefined}
              className={INPUT}
            />
          </Field>
          {/* The design's number-plus-suffix pair, shared with the re-enrollment
              form so a weight looks the same wherever it is edited. */}
          <FormWeightField
            id={`${id}-goal`}
            label="Goal weight"
            value={form.goal}
            onChange={(value) => update("goal", value)}
            required
            error={errors.goal}
          />
        </div>

        <div className="grid gap-5 sm:grid-cols-2">
          {clinicLocations.length > 0 ? (
            <Field label="Clinic location" variant="form">
              <Select
                id={`${id}-clinic`}
                value={form.clinic}
                onChange={(value) => update("clinic", value)}
              >
                {/* Not an "empty option" in the HTML sense — it carries the design's
                    own "None" label and is the default, because a location is
                    optional. Leaving it alone is different from clearing it, which is
                    why this is a real choice rather than a missing value. */}
                <option value="">None</option>
                {clinicLocations.map((location) => (
                  <option key={location.id} value={String(location.id)}>
                    {location.name}
                  </option>
                ))}
              </Select>
            </Field>
          ) : null}
          <Field label="Patient status" variant="form" required>
            <Select
              id={`${id}-phase`}
              value={form.phase}
              onChange={(value) => update("phase", value as PhaseCode)}
            >
              {STATUS_OPTIONS.map(([code, label]) => (
                <option key={code} value={code}>
                  {label}
                </option>
              ))}
            </Select>
          </Field>
        </div>

        {notice ? (
          <p role="alert" className="rounded-lg bg-flame-soft px-3 py-2 text-flame text-sm">
            {notice}
          </p>
        ) : null}

        <div className="flex items-center gap-3 pt-1">
          <button
            type="submit"
            disabled={pending}
            className="rounded-lg bg-primary px-6 py-2.5 font-semibold text-sm text-white hover:bg-brand-dark disabled:cursor-not-allowed disabled:opacity-60"
          >
            {pending ? "Adding…" : "Add patient"}
          </button>
          <button
            type="button"
            disabled={pending}
            onClick={() => {
              setForm({ ...BLANK, start: todayIso() });
              setErrors({});
              setNotice(null);
            }}
            className="rounded-lg px-4 py-2.5 font-medium text-muted-foreground text-sm hover:bg-canvas disabled:cursor-not-allowed disabled:opacity-60"
          >
            Clear form
          </button>
        </div>
        </fieldset>
      </form>

      {/* `h-fit` plus `sticky` so the summary stays put while a long form scrolls
          past it — otherwise the clinician loses sight of what they have typed
          exactly when they need to check it. */}
      <aside className={`${CARD} h-fit p-5 lg:sticky lg:top-6`}>
        <SectionLabel>Summary</SectionLabel>

        <div className="mt-4 space-y-3 text-sm">
          <div className="flex items-center gap-3">
            <PersonAvatar id={1} name={form.name.trim() || "?"} />
            <div className="min-w-0">
              <p className="truncate font-medium">{form.name.trim() || "New patient"}</p>
              <p className="truncate text-muted-foreground text-xs">
                {form.email.trim() || "No email yet"}
              </p>
            </div>
          </div>

          <hr className="border-line" />

          <SummaryRow label="Status">
            <span
              className={`rounded-full px-2 py-0.5 text-[11px] ${PHASE_CLASSES[form.phase]}`}
            >
              {statusLabel}
            </span>
          </SummaryRow>
          <SummaryRow label="Starts">{formatDate(form.start || todayIso())}</SummaryRow>
          <SummaryRow label="Goal weight">
            {form.goal ? `${form.goal} lbs` : null}
          </SummaryRow>
          <SummaryRow label="Clinic">{clinicLocations.find((location) => String(location.id) === form.clinic)?.name}</SummaryRow>
          <SummaryRow label="Daily emails">
            {form.notifications ? "Enabled" : "Disabled"}
          </SummaryRow>
        </div>
      </aside>
    </div>
    </div>
  );
}

function SectionLabel({ children }: { children: React.ReactNode }) {
  return (
    <p className="font-semibold text-muted-foreground text-xs uppercase tracking-wide">
      {children}
    </p>
  );
}

/** A summary row: an em dash rather than a gap when there is nothing to say. */
function SummaryRow({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div className="flex justify-between gap-3">
      <span className="text-muted-foreground">{label}</span>
      <span className="text-right font-medium">
        {children || <span className="font-normal text-muted-foreground">—</span>}
      </span>
    </div>
  );
}

function omitError(errors: Errors, key: keyof Errors): Errors {
  const next = { ...errors };
  delete next[key];
  return next;
}

/**
 * The subset of a server error map this form can display.
 *
 * The endpoint validates more than this form renders, so its `errors` object is
 * routinely wider than the set of fields on screen. Narrowing to the known keys
 * here is what keeps an unknown key from being written into `errors` and silently
 * never shown.
 */
function pick(
  source: Record<string, string>,
  keys: (keyof Errors)[],
): Errors {
  const next: Errors = {};
  for (const key of keys) {
    const message = source[key];
    if (typeof message === "string") next[key] = message;
  }
  return next;
}