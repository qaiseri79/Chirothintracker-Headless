/**
 * The shape "My Progress" renders.
 *
 * Declared separately from the mock so that swapping the placeholder for a
 * Drupal read is a change to `lib/progress/data.ts` alone: the components below
 * only ever see these types, and `getProgress()` is the only thing that knows
 * where the numbers came from.
 *
 * Two conventions worth keeping when the real endpoint lands:
 *
 * - **Numbers are numbers.** Weight and water arrive as `number | null`, never
 *   as a pre-formatted string, so the components decide how many decimals a
 *   figure gets. A missing measurement is `null`, which renders as the design's
 *   "N/A" — not `0`, which would read as a real measurement of nothing.
 * - **One figure, one field.** `summary` holds each measurement once, and
 *   `toStats()` in `data.ts` derives the display tiles from it. The design
 *   repeats `goalProgress` in two places (the welcome line and the "Goal
 *   Achieved" tile); that is handled by one field plus a formatter, not by
 *   storing the same number twice.
 */

/** One day in the patient's log. */
export interface ProgressEntry {
  /** The contact_message entity ID, used for editing. */
  id: number;
  /** Display date, `mm/dd/yyyy` as in the design. */
  date: string;
  /** Day number within the program. */
  day: number;
  /**
   * `field_program_day_computed`, kept apart from `day` because the chart's axis
   * label branches on it: `0` means the log predates the program start and reads
   * "Previous", anything above `0` reads "Day N". `day` is clamped to 0 as well,
   * so it cannot carry that distinction on its own.
   */
  programDayComputed: number;
  /**
   * The day's patient flags, as labels rather than taxonomy term IDs. Empty when
   * the patient selected none — a `string[]` rather than a nullable string so
   * callers join it without a guard.
   */
  flags: string[];
  /** Adherence score out of ten, as reported by the patient. */
  adherence: number;
  /** Weight that morning, in pounds. */
  weight: number;
  /** Cumulative loss to date, in pounds. */
  loss: number;
  /** Water intake, in ounces. */
  water: number;
  /** Hours slept. */
  sleep: number;
  /** Free-text note, or `null` when the day was unremarkable. */
  note: string | null;
  lunch: string;
  dinner: string;
  other: string;
  /** Optional per-area body measurements already returned by the progress API. */
  measurements?: Array<{ area: string; value: number }>;
}

/** How a figure is coloured in the summary strip. */
export type StatTone = "default" | "positive" | "accent";

/** One tile in the five-across summary strip. */
export interface ProgressStat {
  label: string;
  value: number | null;
  /** Unit shown at a smaller size beside the figure, or `null` to omit it. */
  unit: string | null;
  /** Decimal places. `0` for percentages, `1` for pounds. */
  decimals: number;
  tone: StatTone;
}

/** The measurements behind the summary strip, chart and welcome line. */
export interface ProgressSummary {
  /** Authoritative saved starting weight for the current program. */
  startWeight?: number | null;
  /** Saved program start date (YYYY-MM-DD), used by the chart range selector. */
  programStartDate?: string | null;
  /** Day number within the program, as in "Day 14 of your program". */
  programDay: number;
  /**
   * Progress towards the goal, as a fraction (`0.8`) rather than a percentage
   * (`80`) so the value cannot be misread once.
   */
  goalProgress: number;
  /** The goal line on the chart and the "Goal Weight" tile. `null` if unset. */
  goalWeight: number | null;
  /** `null` where the program does not track the measurement. */
  netWeightLoss: number | null;
  netInchesLost: number | null;
  overallWeightLoss: number | null;
}

export interface ProgressSnapshot {
  summary: ProgressSummary;
  /** Newest first, which is the order the log history renders them in. */
  entries: ProgressEntry[];
}
