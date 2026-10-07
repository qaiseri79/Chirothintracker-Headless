import type { ProgressEntry, ProgressSummary } from "@/lib/progress/types";

export interface MeasurementComparison {
  area: string;
  first: number;
  current: number;
  readings: number;
  lost: number | null;
}

/** Compare each area independently: missing sites in a partial log stay missing. */
export function measurementComparisons(entries: ProgressEntry[]): MeasurementComparison[] {
  const areas = new Map<string, MeasurementComparison>();
  // Preserve the snapshot's existing submission order; date-order corrections
  // are deliberately outside this card redesign.
  for (const entry of [...entries].reverse()) {
    for (const measurement of entry.measurements ?? []) {
      if (!Number.isFinite(measurement.value)) continue;
      const site = areas.get(measurement.area);
      if (!site) {
        areas.set(measurement.area, { area: measurement.area, first: measurement.value, current: measurement.value, readings: 1, lost: null });
      } else {
        site.current = measurement.value;
        site.readings += 1;
        site.lost = Math.round((site.first - site.current) * 10) / 10;
      }
    }
  }
  return [...areas.values()];
}

export function progressCardDetails(summary: ProgressSummary, entries: ProgressEntry[]) {
  const currentWeight = entries[0]?.weight ?? null;
  const startWeight = summary.startWeight ?? null;
  const goalWeight = summary.goalWeight;
  const remaining = currentWeight === null || goalWeight === null ? null : Math.max(0, currentWeight - goalWeight);
  const sites = measurementComparisons(entries);
  const topSites = sites.filter((site) => site.lost !== null && site.lost > 0).sort((a, b) => b.lost! - a.lost!).slice(0, 3);
  // These small previews use at most 24 actual records; they do not average or
  // replace the full history already shown by the main chart.
  const preview = [...entries].reverse().slice(-24);
  return {
    currentWeight, startWeight, remaining, sites, topSites, preview,
    visualPercent: Math.max(0, Math.min(100, summary.goalProgress * 100)),
  };
}