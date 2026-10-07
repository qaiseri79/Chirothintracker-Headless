"use client";

import { useState } from "react";
import { UserPlus } from "lucide-react";
import {
  BUTTON_OUTLINE,
  Field,
  FormTextField,
  FormToggle,
  FormWeightField,
  INPUT,
  Select,
} from "@/components/portal/patients/patients-ui";
import {
  PHASE_OPTIONS,
  type ArchivedRow,
  type ClinicLocation,
  type PhaseCode,
} from "@/lib/patients/types";
import { parseWeightInput, weightProblem } from "@/lib/patients/weight";
import type { EnrollPatientInput } from "@/lib/patients/api";

/**
 * The confirmation step behind a row's Re-enroll button.
 *
 * ## Why it shows the whole record, not just the location
 *
 * This began as one question — which branch is the patient returning to — because
 * that was the only thing the operation could not work out for itself. It grew into
 * the patient's full detail form for two reasons:
 *
 * - **Re-enrolling is the moment a returning patient's record is next looked at.**
 *   The chiropractor is opening this patient anyway. Asking them to confirm a
 *   location while their phone number sits in a field they cannot see is an
 *   invitation to fix that later from memory, or never.
 * - **Nothing here is new information to retype.** Every value is already on the
 *   account, so the form opens filled in. What is being confirmed is the decision
 *   to bring this person back — the fields are there to be checked and, if the
 *   chiropractor has just spoken to them, corrected.
 *
 * ## Why it is prefilled and not blank
 *
 * A blank form would be a lie about what the site knows. It would also be
 * dangerous in the other direction: a field left blank here reads as "nothing on
 * file", and confirming it would be recorded as clearing the value. So every field
 * starts as what the patient actually has, including the empties, and the empties
 * are left empty.
 *
 * ## What a blank field means on submit
 *
 * A blank field is *not* sent, and the endpoint reads an absent key as "leave this
 * alone". That is what makes confirming an untouched form safe: the patient is
 * re-enrolled exactly as they were, and the chiropractor cannot wipe a real
 * measurement by not touching a box. The note under the buttons says so, because a
 * silent rule about what a blank does is the kind of thing that gets discovered the
 * hard way.
 *
 * ## Why the start date is checked against today, and only when it changed
 *
 * A start date in the past is not a start date, so the rule wanted here is "refuse a
 * date before today". Written against this form as drawn, that rule would break the
 * feature it is meant to protect: the form is prefilled, and the prefilled value is
 * the date the patient *originally* started — in the past for every archived patient
 * with any history at all. Confirming without touching the date would then fail on the
 * record's own data, and re-enrolling someone with real history would be impossible.
 *
 * So the rule is applied to the change rather than to the value. An untouched date is
 * left out of the payload, which the endpoint already reads as "leave this alone", so a
 * date that *is* sent is by definition one just chosen. Only that case is checked, here
 * and again on the server.
 *
 * The stored date is untouched by all of this: a patient who really did start in 2023
 * is still shown as having started in 2023, and confirming does not quietly move them.
 */

/**
 * Mirrors the archived row, with every control held as the string its input wants.
 *
 * The weights are the reason this is a separate shape rather than the row itself:
 * `number | null` on one side, `""` in the input on the other. Converting here means
 * the conversion exists once.
 */
interface FormState {
  name: string;
  email: string;
  phone: string;
  start: string;
  startWeight: string;
  goalWeight: string;
  phase: PhaseCode | "";
  clinic: string;
  notifications: boolean;
}

function weightToInput(value: number | null): string {
  // Null is "never recorded" and renders as an empty box; 0 is a real answer and
  // renders as 0. Collapsing them, as a truthiness check would, would show a
  // measured patient as having nothing on file.
  return value === null ? "" : String(value);
}

/**
 * The form's opening state, taken from the patient's own record.
 *
 * `clinic` preselects the branch they were at only when it is still one of the
 * clinic's. A location from a closed practice, or one this snapshot does not carry,
 * would preselect an option the dropdown cannot show — and confirming it would store
 * a branch the chiropractor never saw.
 */
function fromArchivedRow(row: ArchivedRow, clinicLocations: ClinicLocation[]): FormState {
  const held = row.clinicLocation;
  const stillValid = held !== null && clinicLocations.some((l) => l.id === held);

  return {
    name: row.name,
    email: row.email,
    phone: row.phone,
    start: row.programStart,
    startWeight: weightToInput(row.startWeight),
    goalWeight: weightToInput(row.goalWeight),
    phase: row.phase,
    clinic: stillValid ? String(held) : "",
    notifications: row.emailNotifications,
  };
}

/**
 * A number input's contents as a number, or `undefined` when it is not a value.
 *
 * `undefined` rather than `null` because the key is then omitted from the payload
 * entirely, which is what "leave this field alone" looks like on the wire. Returning
 * 0 for an empty box would send a real measurement that nobody typed.
 */
/** The fields this form can hold an error against. */
type FormErrors = Partial<
  Record<"programStart" | "startWeight" | "goalWeight", string>
>;

/** What a weight field amounts to, once "was it changed?" has been answered. */
type WeightOutcome =
  /** Nothing to send: empty, cleared, or identical to what is stored. */
  | { kind: "skip" }
  /** A changed, acceptable weight. */
  | { kind: "send"; weight: number }
  /** A changed weight that cannot be right. */
  | { kind: "problem"; problem: string };

/**
 * A weight this form is about to send, checked but only if it was changed.
 *
 * Returns `skip` for three cases that all mean the same thing to the endpoint: an empty
 * box, a cleared box, and a box holding the value the record already has.
 *
 * That last one is why this is not a plain bounds check. The archive genuinely holds
 * goal weights of 5155770000 and 3434340, and a patient carrying one still has to be
 * able to come back. Their own number is not re-sent — so it is never judged — and it
 * survives untouched, while anything the chiropractor actually types is held to a
 * realistic range. The stored data is wrong and stays wrong until somebody fixes it on
 * purpose; the point here is that fixing it is never a prerequisite for the patient
 * returning.
 *
 * @param raw    What the input holds.
 * @param stored What the record holds: `number`, or NULL for never recorded.
 * @param label  The field's name, for the message.
 */
function changedWeight(
  raw: string,
  stored: number | null,
  label: string,
): WeightOutcome {
  const parsed = parseWeightInput(raw);
  // Compared as numbers rather than as text so "162" and "162.0" are the same answer,
  // and so the comparison works at all given the row holds a number and the input a
  // string. `undefined === undefined` is what makes an untouched empty box agree with
  // a record that has no weight.
  if (parsed === (stored ?? undefined)) {
    return { kind: "skip" };
  }
  // Cleared, which the endpoint reads as "leave it alone" — the same answer as above.
  if (parsed === undefined) {
    return { kind: "skip" };
  }
  const problem = weightProblem(parsed, label);
  return problem ? { kind: "problem", problem } : { kind: "send", weight: parsed };
}

/**
 * Today as `YYYY-MM-DD` in the reader's own timezone.
 *
 * Neither a date input nor `field_program_start` carries a timezone, so "today" has to
 * mean the chiropractor's today rather than the server's — otherwise someone
 * re-enrolling in Auckland is told their start date has already passed for the first
 * few hours of their morning. Shifted onto local time before being sliced, because
 * `toISOString` reads as UTC and would otherwise report yesterday for anyone west of
 * Greenwich in the evening.
 */
function todayLocal(): string {
  const now = new Date();
  return new Date(now.getTime() - now.getTimezoneOffset() * 60_000)
    .toISOString()
    .slice(0, 10);
}

export function ReenrollForm({
  row,
  clinicLocations,
  onConfirm,
  onCancel,
}: {
  row: ArchivedRow;
  /**
   * The clinic's branches. Empty renders no location control, because a select with
   * one "None" option is a field that cannot be filled in.
   */
  clinicLocations: ClinicLocation[];
  /** Called with only the fields that carry a value. */
  onConfirm: (changes: EnrollPatientInput) => void;
  onCancel: () => void;
}) {
  const id = `reenroll-${row.id}`;
  // Keyed by id rather than initialised once: React keeps state across a prop change,
  // so without this a second row opened in the same component would inherit the first
  // patient's answers. Remounting on the id is what guarantees each form opens from
  // its own row.
  const [form, setForm] = useState<FormState>(() => fromArchivedRow(row, clinicLocations));
  // Local, per-field, and deliberately not the page banner. This is the only check
  // that can stop the click, so it has to sit on the input it is about: a message at
  // the top of a long form is a message about a field the reader is not looking at.
  const [errors, setErrors] = useState<FormErrors>({});

  function update<K extends keyof FormState>(key: K, value: FormState[K]) {
    setForm((current) => ({ ...current, [key]: value }));
    // Cleared by the edit rather than by the next submit: an error that outlives the
    // correction reads as the form arguing with what was just typed into it.
    if (key === "start") setErrors((e) => ({ ...e, programStart: undefined }));
    if (key === "startWeight") setErrors((e) => ({ ...e, startWeight: undefined }));
    if (key === "goalWeight") setErrors((e) => ({ ...e, goalWeight: undefined }));
  }

  function submit() {
    const changes: EnrollPatientInput = {
      name: form.name.trim(),
      email: form.email.trim(),
      phone: form.phone.trim(),
      emailNotifications: form.notifications,
    };

    // Sent only when the chiropractor actually changed it. An untouched date, and a
    // cleared one, are both left out and so read as "keep what is stored" — which is
    // what stops a legitimately old start date from being rejected as a newly chosen
    // one, and what stops a no-op write of the record's own value.
    if (form.start !== "" && form.start !== row.programStart) {
      if (form.start < todayLocal()) {
        setErrors((e) => ({
          ...e,
          programStart: "Program start cannot be in the past.",
        }));
        return;
      }
      changes.programStart = form.start;
    }

    // Same rule as the date, for the same reason: the archive holds weights well
    // outside any realistic range, so an untouched one is never judged. Collected
    // before either is sent so a bad goal weight does not arrive alongside a good
    // start weight and leave a half-applied change.
    const start = changedWeight(form.startWeight, row.startWeight, "Starting weight");
    if (start.kind === "problem") {
      setErrors((e) => ({ ...e, startWeight: start.problem }));
      return;
    }
    const goal = changedWeight(form.goalWeight, row.goalWeight, "Goal weight");
    if (goal.kind === "problem") {
      setErrors((e) => ({ ...e, goalWeight: goal.problem }));
      return;
    }
    if (start.kind === "send") changes.startWeight = start.weight;
    if (goal.kind === "send") changes.goalWeight = goal.weight;

    if (form.phase !== "") changes.phase = form.phase;
    if (form.clinic !== "") changes.clinicLocation = Number(form.clinic);

    onConfirm(changes);
  }

  return (
    /* `bg-surface`, not `bg-canvas`: every control in here is an `INPUT`, which is
       itself `bg-canvas`, so a canvas panel left canvas inputs with nothing to read
       against and the whole form flattened into the row behind it. Surface is also
       what the surrounding results card is, so the border is what separates the two. */
    <div className="col-span-full rounded-xl border border-line bg-surface p-4">
      <p className="mb-3 font-medium text-foreground text-sm">
        Re-enrolling {row.name}
      </p>

      <div className="grid gap-4 md:grid-cols-3">
        <FormTextField
          id={`${id}-name`}
          label="Patient full name"
          value={form.name}
          onChange={(value) => update("name", value)}
          autoComplete="off"
        />
        <FormTextField
          id={`${id}-email`}
          label="Email address"
          type="email"
          value={form.email}
          onChange={(value) => update("email", value)}
        />
        <FormTextField
          id={`${id}-phone`}
          label="Phone number"
          type="tel"
          value={form.phone}
          onChange={(value) => update("phone", value)}
        />

        <Field
          label="Program start date"
          variant="form"
          error={errors.programStart}
          errorId={`${id}-start-error`}
        >
          {/* The shared INPUT rather than a hand-rolled class: it supplies the same
              focus ring as every other field here, which the previous bespoke
              background colour did not, and it keeps this form's fields one colour
              instead of two. */}
          <input
            id={`${id}-start`}
            type="date"
            value={form.start}
            onChange={(event) => update("start", event.target.value)}
            aria-invalid={errors.programStart ? true : undefined}
            aria-describedby={errors.programStart ? `${id}-start-error` : undefined}
            className={INPUT}
          />
        </Field>

        <FormWeightField
          id={`${id}-start-weight`}
          label="Starting weight"
          value={form.startWeight}
          onChange={(value) => update("startWeight", value)}
          error={errors.startWeight}
        />
        <FormWeightField
          id={`${id}-goal-weight`}
          label="Goal weight"
          value={form.goalWeight}
          onChange={(value) => update("goalWeight", value)}
          error={errors.goalWeight}
        />

        <Field label="Patient status" variant="form">
          <Select
            id={`${id}-phase`}
            value={form.phase}
            onChange={(value) => update("phase", value as PhaseCode | "")}
          >
            {/* Only offered when the patient has no phase on file. An archive that
                has one has a real phase to bring back to, so this is the difference
                between "unset" and "unchanged". */}
            {form.phase === "" ? <option value="">No phase on file</option> : null}
            {PHASE_OPTIONS.map(([code, label]) => (
              <option key={code} value={code}>
                {label}
              </option>
            ))}
          </Select>
        </Field>

        {clinicLocations.length > 0 ? (
          <Field label="Clinic location" variant="form">
            <Select
              id={`${id}-clinic`}
              value={form.clinic}
              onChange={(value) => update("clinic", value)}
            >
              <option value="">Keep current</option>
              {clinicLocations.map((location) => (
                <option key={location.id} value={String(location.id)}>
                  {location.name}
                </option>
              ))}
            </Select>
          </Field>
        ) : null}

        {/* In the grid's third column rather than on a row of its own, beside the
            location it affects. With no locations configured the field above is
            absent and the toggle sits one column along, which still reads as a row
            of settings rather than as a stray full-width control. */}
        <FormToggle
          legend="Daily email notifications"
          value={form.notifications}
          onChange={(value) => update("notifications", value)}
        />
      </div>

      <div className="mt-4 flex flex-wrap items-center gap-3">
        {/* `ml-auto` on the buttons alone rather than `justify-end` on the row: the
            note below is a paragraph, and justifying the row would right-align its
            ragged left edge along with the buttons. */}
        <div className="ml-auto flex flex-wrap items-center gap-3">
          <button
            type="button"
            onClick={submit}
            className="inline-flex items-center gap-1.5 rounded-lg bg-primary px-3 py-2 font-semibold text-white text-xs hover:bg-brand-dark"
          >
            <UserPlus className="size-3.5" aria-hidden="true" />
            Confirm re-enroll
          </button>
          <button
            type="button"
            onClick={onCancel}
            className={`${BUTTON_OUTLINE} px-3 py-2 text-xs`}
          >
            Cancel
          </button>
        </div>
        <p className="basis-full text-muted-foreground text-xs">
          Fields are filled in from what is already on the patient. Anything left blank
          keeps its current value — confirming will not clear a field you did not
          change.
        </p>
      </div>
    </div>
  );
}