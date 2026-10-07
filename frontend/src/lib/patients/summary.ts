/**
 * The Patient Summary view's data contract.
 *
 * Written before the endpoint exists, from "New Design/ChiroThin — Patient
 * Summary (Doctor Dashboard).html". The backend is a headless Drupal instance
 * served from a single user API endpoint, so every field here maps to a `user`
 * entity field the same way `lib/patients/types.ts` documents for the Patients
 * page. When the endpoint lands, `MOCK_PATIENTS` is replaced by a normaliser
 * over its response and the components do not change.
 */

/** Review state, from the design's `review` field. */
export type ReviewStatus = "New" | "Reviewed";

export interface PatientSummaryRow {
  id: number;
  phaseCode: string | null;
  name: string;
  email: string;
  /** Profile photo URL, from the design's `avatar` field. */
  avatar: string;
  /** Current program day, for the row subtext. */
  day: number | null;
  /** Program start date as `MM/DD/YYYY`, for the row subtext. */
  startDate: string;
  /** Clinic location, from the design's `clinic` field. */
  clinic: string;
  /** Human phase label, e.g. "Losing Phase". */
  phase: string;
  /** Program name, e.g. "Weight Loss". */
  program: string;
  /** Percent of goal weight lost, 0–100. */
  percentOfGoal: number | null;
  /** Net loss in lbs. */
  netLoss: number | null;
  review: ReviewStatus;
  /** Flagged for the chiropractor's attention, for the stat card. */
  attention: boolean | null;
  /** Starting weight in lbs, from the design's `stats.sw`. */
  startWeight: number | null;
  /** Goal weight in lbs, from the design's `stats.gw`. */
  goalWeight: number | null;
  /** Overall loss in lbs, from the design's `stats.overall`. */
  overallLoss: number | null;
  /** Inches lost, from the design's `stats.inches`. */
  inchesLost: number | null;
  /** Most recent daily loss as a display string, from `stats.daily`. */
  dailyLoss: number | null;
  /** Average lbs lost per day, from the design's `stats.avg`. */
  avgLossPerDay: number | null;
  /** Last seen date as `MM/DD/YYYY`, from the design's `stats.seen`. */
  lastSeen: string;
  /**
   * Session packages, from the design's `sessions`.
   *
   * `count` is what the patient has left and `total` what the package holds, so
   * `count + history.length` should equal `total` for a package that has been
   * used. `history` is the completed sessions, newest first.
   */
  sessions: Array<{
    id: number;
    bundle: string;
    completed: number;
    historyTruncated?: boolean;
    name: string;
    count: number;
    total: number;
    history: Array<{ date: string; weight: string; areas: string[]; notes: string }>;
  }>;
  /** The chiropractor's private notes, from the design's `notes`. */
  notesList: Array<{ id: number; date: string; text: string; isPinned: boolean }>;
  /** Files attached to the patient, from the design's `attachments`. */
  attachmentsList: Array<{ id: number; messageId: number; url: string; name: string; size: number; date: string }>;
  /** Periodic weight readings for the progress chart, oldest first. */
  weightHistory: Array<{ date: string; weight: number }>;
  /** Each measurement area's first and latest reading, for the comparison table. */
  measurementChanges: Array<{ area: string; first: number; latest: number }>;
  /** The patient's intake submission, or null when they have not submitted one. */
  intake: import("./types").IntakeSubmission | null;
  sessionSchemas?: Record<string, SessionField[]>;
  logsTotal?: number;
  /** The patient's daily logs, newest first, for the expanded row's scroller. */
  logsList: Array<{
    id: number;
    date: string;
    dayNumber: number;
    adherence: number | null;
    doctorEntered?: boolean;
    bloodSugar?: number | null;
    bloodPressure?: string;
    weight: number;
    weightDelta: number | null;
    flag: string | null;
    flags: string[];
    water: number;
    sleep: string | null;
    hasFood: boolean;
    hasMeasurements: boolean;
    isOnPlan: boolean;
    /** What the patient ate that day, grouped by meal, for the food dropdown. */
    foodDetails: Array<{ category: string; items: string[] }>;
    /** The measurements taken that day, for the card's measurements dropdown. */
    measurementDetails: Array<{ area: string; value: string }>;
  }>;
}


export type SummarySection = "logs" | "progress" | "sessions" | "notes" | "attachments" | "intake";
export interface PatientSummarySnapshot {
  patients: PatientSummaryRow[];
  locations: Array<{ id: number; name: string }>;
  phases: Array<{ code: string; stored: string; label: string }>;
  /** Laser Patient Status terms, for the account form's "Patient status" select. */
  statuses: Array<{ id: number; name: string }>;
  attentionAvailable: boolean;
}
export const SECTION_FIELDS: Record<SummarySection, keyof PatientSummaryRow> = {
  logs: "logsList", progress: "weightHistory", sessions: "sessions", notes: "notesList", attachments: "attachmentsList", intake: "intake",
};
export function formatSummaryNumber(value: number | null | undefined, digits = 1, unit = ""): string {
  return value == null ? "—" : `${value.toFixed(digits)}${unit ? ` ${unit}` : ""}`;
}

export interface SessionField { name: string; label: string; type: string; multiple: boolean; required: boolean; options: Array<{ id: number; label: string }> }
