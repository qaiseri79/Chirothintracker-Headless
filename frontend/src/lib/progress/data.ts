import "server-only";

import { drupalFetch } from "@/lib/drupal/client";
import type { ProgressSnapshot, ProgressStat, ProgressSummary } from "@/lib/progress/types";

/**
 * Thrown when the patient's progress cannot be read.
 *
 * A distinct type so `dashboard/page.tsx` can tell "the backend is broken" apart
 * from an unexpected crash, and show the patient a sentence about their data
 * rather than a stack trace.
 */
export class ProgressUnavailableError extends Error {
  constructor(
    message: string,
    /** The upstream HTTP status, or 0 when the request never completed. */
    readonly status: number,
    options?: { cause?: unknown },
  ) {
    // The original failure is kept as `cause` rather than dropped, so the single
    // `console.error` in the page still reaches the network or parse error that
    // explains *why* the data is unavailable.
    super(message, options);
    this.name = "ProgressUnavailableError";
  }
}

/**
 * Where "My Progress" gets its numbers.
 *
 * **This is the seam.** Everything above it — the page, the summary strip, the
 * chart, the log history — is written against `ProgressSnapshot` and knows
 * nothing about where the figures came from.
 *
 * ## There is no placeholder data any more
 *
 * This used to fall back to `MOCK_PROGRESS` — a transcription of the design —
 * on any failure, and that fallback was removed deliberately rather than left
 * as a safety net. It made a broken backend indistinguishable from a working
 * one: a 500 logged to the console and then rendered a page of invented figures,
 * so a patient could be shown 219.0 lbs and "Day 14" that were not theirs, with
 * nothing on screen to say so. On a weight-loss dashboard those numbers drive
 * treatment, so a failure has to be visible rather than papered over. The
 * caller renders an honest unavailable state instead.
 *
 * The seam is unchanged, so putting the placeholder back is a one-line change if
 * it is ever wanted for design work — but it should never be reachable in a
 * deployed environment.
 *
 * - Read through the patient's **own** Drupal session, the way
 *   `lib/drupal/session.ts` does, and do not accept a patient id from the
 *   request. Every read here describes the caller, so an id in a query string
 *   would be a way to read somebody else's chart.
 * - `netInchesLost` and the other nullable fields are `null` because the design
 *   has no figure for them. Keep them `null` rather than `0` — see the note in
 *   `types.ts`.
 * - Nothing here caches. These are the patient's own measurements, and a stale
 *   weight on a progress chart is worse than a slow one.
 */
export async function getProgress(): Promise<ProgressSnapshot> {
  let response: Response;
  try {
    response = await drupalFetch("/api/headless/progress");
  } catch (cause) {
    // Network-level failure: no status to report, but still not the patient's
    // fault and still not something to render invented numbers over.
    throw new ProgressUnavailableError("Could not reach the progress service.", 0, { cause });
  }

  if (!response.ok) {
    throw new ProgressUnavailableError(
      `The progress service responded ${response.status}.`,
      response.status,
    );
  }

  try {
    return (await response.json()) as ProgressSnapshot;
  } catch (cause) {
    // A 200 with a body that is not JSON means something is wrong upstream that
    // a status code alone will not show.
    throw new ProgressUnavailableError(
      "The progress service returned a response that could not be read.",
      response.status,
      { cause },
    );
  }
}

/**
 * Derives the five summary tiles from the summary measurements.
 *
 * Lives here rather than in a component so the tile order, labels, decimal
 * places and colours are decided in one place, and so a figure can never be
 * stored twice: the design shows `goalProgress` in both the welcome line and
 * the "Goal Achieved" tile, and that is one field formatted twice.
 *
 * Card values retain Drupal's rollups; the display component applies the layout.
 */
export function toStats(summary: ProgressSummary): ProgressStat[] {
  return [
    { label: "Goal Weight", value: summary.goalWeight, unit: "lbs", decimals: 1, tone: "default" },
    {
      label: "Net Weight Loss",
      value: summary.netWeightLoss,
      unit: "lbs",
      decimals: 1,
      tone: "positive",
    },
    {
      label: "Net Inches Lost",
      value: summary.netInchesLost,
      unit: "in",
      decimals: 1,
      tone: "accent",
    },
    {
      // `goalProgress` is a fraction; the tile is a percentage.
      label: "Goal Achieved",
      value: summary.goalProgress * 100,
      unit: "%",
      decimals: 0,
      tone: "accent",
    },
    {
      label: "Overall Weight Loss",
      value: summary.overallWeightLoss,
      unit: "lbs",
      decimals: 1,
      tone: "positive",
    },
  ];
}

/** `0.8` → `"80%"`, for the welcome line. */
export function formatPercent(fraction: number): string {
  return `${Math.round(fraction * 100)}%`;
}
