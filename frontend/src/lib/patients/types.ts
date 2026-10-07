/**
 * The Patients page's data contract.
 *
 * Written before the endpoint exists, from "New Design/ChiroThin — Patients.html"
 * and from what the `user` entity already carries, so the components above have a
 * fixed target to code against while `lib/patients/data.ts` is still a stub.
 *
 * ## Field provenance
 *
 * Every column the design draws maps to a real Drupal field on the `user`
 * entity. Nothing here is invented for the sake of the mock:
 *
 * | Column             | Drupal field                                |
 * | ------------------ | ------------------------------------------- |
 * | `name`             | `getDisplayName()`                          |
 * | `email`            | `mail`                                      |
 * | `role`             | `enrolled_patient` / `archived_patient`      |
 * | `phase`            | `field_weight_loss_phase` (`phase-0`..`4`)   |
 * | `programStart`     | `field_program_start_date`                  |
 * | `programDay`       | `field_user_current_program_day_c`           |
 * | `startWeight`      | `field_program_start_weight`                |
 * | `netLoss`          | `field_net_weight_loss`                     |
 *
 * Two notes that will matter when the endpoint lands:
 *
 * - **Archived is a role, not a field.** `custom_module`'s `ArchievedPatient`
 *   form swaps `enrolled_patient` for `archived_patient`; there is no
 *   `field_archived`. Do not use core's `status` — that is the blocked/active
 *   flag and means "cannot log in". Because archiving is a swap, an archived
 *   account no longer holds `enrolled_patient`, so the backend resolves both
 *   patient roles to the single `patient` code: otherwise every archived patient
 *   would fall through to the staff default and be labelled a health coach.
 * - **Phase is a stored string.** `field_weight_loss_phase` holds `phase-0`…
 *   `phase-4`; the human labels are hard-coded in `SetLockField.php`. The
 *   mapping below mirrors that file, so the two must be changed together.
 *
 * ## Zero is not a measurement
 *
 * `startWeight` and `netLoss` are both `number | null`, and `0` is a real answer
 * the backend stores for either — `ResetStartingValues.php` initialises them, and
 * a patient who has logged nothing has genuinely lost zero. The distinction is
 * carried through rather than flattened, so the UI can render `null` as an em
 * dash and `0` as `0.0 lbs`: "nothing recorded" and "nothing lost" are different
 * facts about a patient.
 */

/** Portal roles the design's Role filter offers. */
export type PatientRole = "patient" | "coach" | "doctor";

/** `phase-0`..`phase-4`, as stored in `field_weight_loss_phase`. */
export type PhaseCode = "L" | "D" | "Z" | "C" | "M";

export interface PatientRow {
  id: number;
  role: PatientRole;
  name: string;
  email: string;
  /** Empty when the patient has never had a phase assigned. */
  phase: PhaseCode | "";
  /** ISO `YYYY-MM-DD`, or `""` when no program has started. */
  programStart: string;
  programDay: number;
  /** Net starting weight in lbs, or `null` when not recorded. */
  startWeight: number | null;
  /**
   * Net loss in lbs, or `null` when not recorded. Negative means gained.
   *
   * `0` is a real answer: a patient who logged nothing has lost nothing, and
   * showing that as an em dash would read as a missing measurement instead. This
   * is why the sort and the colour both have to handle `null` explicitly.
   */
  netLoss: number | null;
}

export interface ArchivedRow {
  id: number;
  /**
   * The same `patient` / `coach` / `doctor` code the roster uses.
   *
   * Not displayed — the archived table has no Role column, because an archived
   * account is a patient by definition and a column of constants would say
   * nothing. It is here because the Archived tab's role filter reads it, and
   * because both tabs come from one snapshot: a row that is a patient on the
   * Patient List must not be filtered as staff once it has been archived.
   *
   * `archived_patient` resolves to `patient`, not to a separate code.
   */
  role: PatientRole;
  name: string;
  email: string;
  programStart: string;
  programDay: number;
  /**
   * `field_clinic_location`, or `null` when none is set.
   *
   * Mostly `null` in practice: the field was only populated for part of the
   * program's life, so most archived patients have none. That is exactly why
   * re-enrolling has to ask which location the patient is returning to rather
   * than assuming one — and why the re-enrollment step preselects this value only
   * when it is actually there.
   */
  clinicLocation: number | null;
  /**
   * The re-enroll form's prefill, beyond identity.
   *
   * Re-enrolling does not create a patient, it brings one back, so the form opens
   * filled in with what the account already says rather than blank: the chiropractor
   * is confirming a decision about the *return*, not re-keying a record they have
   * had in front of them all along. That also means a blank field here is a fact
   * about the patient — most archived accounts genuinely have no phone and no
   * measurements — and is shown as such rather than filled with a default.
   *
   * `phase` and `emailNotifications` arrive already decoded for display: the code
   * rather than the stored `phase-N`, and the positive "send them" form rather than
   * the opt-out the site stores. Mirroring the inversions here is what keeps the two
   * forms reading the same field two different ways.
   */
  phone: string;
  phase: PhaseCode | "";
  /** Net starting weight in lbs, or `null` when never recorded. */
  startWeight: number | null;
  /** Goal weight in lbs, or `null` when never recorded. */
  goalWeight: number | null;
  /** Whether the patient is subscribed to the daily emails. */
  emailNotifications: boolean;
}

export type IntakeStatus = "new" | "checked";

/**
 * One of the clinic's locations, for the location dropdowns.
 *
 * A location is a `clinic` entity in the `clinic_location` ECK bundle whose
 * `field_clinic` points at the parent practice. The clinic sends its own list,
 * already scoped and sorted, so the ids offered here are by construction the ids
 * the backend will accept — the alternative, a hard-coded list in this file, is
 * one more place to forget to update when a practice opens or closes a branch.
 */
export interface ClinicLocation {
  id: number;
  name: string;
}

export interface IntakeRow {
  id: number;
  firstName: string;
  lastName: string;
  name: string;
  email: string;
  phone: string;
  /** `0` means the submission left it blank. */
  goalWeight: number;
  /** `YYYY-MM-DD HH:mm`, from the core `created` field. */
  submitted: string;
  programStart: string;
  status: IntakeStatus;
}

/**
 * One intake submission in full, for the view action.
 *
 * ## Why `fields` is left raw
 *
 * `fields` is Drupal's own storage shape keyed by field name, deliberately not
 * flattened here. Two reasons: the answer labels live in the intake blueprint,
 * and a client-side component that resolved them would be a second place to keep
 * in step with the form; and the raw shape is the only one that survives a field
 * being added to the form without this file knowing about it.
 *
 * `intake-fields.ts` is where the two are joined. Nothing outside this file should
 * read `fields` directly.
 */
export interface IntakeSubmission {
  id: number;
  /**
   * Unix seconds from the core `created` field.
   *
   * A timestamp rather than the `YYYY-MM-DD HH:mm` string {@link IntakeRow} uses:
   * the two come from different endpoints and this one is only ever rendered
   * through `IntakeView`, which formats it for a reader rather than sorting on it.
   */
  submitted: number;
  status: IntakeStatus;
  fields: Record<string, unknown>;
}

export interface EnrollmentAllowance {
  planName: string;
  scheduledPlanName?: string | null;
  /** null means unlimited; a missing allowance object means unavailable. */
  limit: number | null;
  used: number;
  remaining: number | null;
}

/** Everything the four tabs read. One fetch, four views. */
export interface PatientsSnapshot {
  active: PatientRow[];
  archived: ArchivedRow[];
  intake: IntakeRow[];
  /**
   * The clinic's shareable intake URL, shown on the Intake Forms tab.
   *
   * Empty when the clinic has no usable invite token. The endpoint derives it
   * from `headless_intake`'s own `intake_base_url` config, never from the current
   * request, so it is the real Next.js `/intake/{token}` address and not a
   * back-reference to Drupal.
   */
  intakeLink: string;
  /** Patients holding `enrolled_patient`, for the header pill. */
  enrolledCount: number;
  /** Subscription/package terms shared by the clinic's doctors, without billing details. */
  enrollmentAllowance: EnrollmentAllowance | null;
  /** Submissions still unreviewed, for the Intake Forms tab's badge. */
  intakeNewCount: number;
  /**
   * The clinic's locations, for Add Patient and for re-enrolling an archived
   * patient.
   *
   * Empty is a real state, not a placeholder: a clinic with no `clinic_location`
   * entities gets an empty dropdown rather than a default. Both callers treat it
   * that way — the form omits the field, and re-enrollment still works, leaving
   * the patient on whatever location they already had.
   */
  clinicLocations: ClinicLocation[];
}

export const ROLE_LABELS: Record<PatientRole, string> = {
  patient: "Patient",
  coach: "Health Coach",
  doctor: "Clinic Doctor",
};

export const PHASE_LABELS: Record<PhaseCode, string> = {
  L: "Losing Phase",
  D: "Loading Phase",
  Z: "Zero-Day (Pre-Loading)",
  C: "Continuity Phase",
  M: "Cycling / Maintenance Phase",
};

/**
 * Phase pill classes, transcribed from the design's `PH_CLS`.
 *
 * The design's `accent` is this app's `flame` — the same substitution
 * `log-history.tsx` documents. Zero-Day uses the app's canvas/muted pair, which
 * is within a couple of percent of the design's `slate-100`/`slate-600`.
 * Continuity and Cycling have no token in `globals.css`, so they keep raw
 * Tailwind hues; the design gave each phase its own colour deliberately, so they
 * are left distinct rather than collapsed onto `flame`.
 */
export const PHASE_CLASSES: Record<PhaseCode, string> = {
  L: "bg-primary-soft text-primary",
  D: "bg-flame-soft text-flame",
  Z: "bg-canvas text-muted-foreground",
  C: "bg-sky-50 text-sky-700",
  M: "bg-violet-50 text-violet-700",
};

export const PHASE_OPTIONS = Object.entries(PHASE_LABELS) as [PhaseCode, string][];