/** UTC calendar days keep date ranges independent of time zones and DST. */
const DAY_MS = 86_400_000;

export interface ChartWindow { from: number; to: number }
export type ChartRange = "30" | "60" | "program" | "all";

export function chartDay(value: string | null | undefined): number | null {
  if (!value) return null;
  const us = /^(\d{2})\/(\d{2})\/(\d{4})$/.exec(value);
  const iso = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value);
  if (!us && !iso) return null;
  const year = Number(us ? us[3] : iso![1]);
  const month = Number(us ? us[1] : iso![2]);
  const day = Number(us ? us[2] : iso![3]);
  const timestamp = Date.UTC(year, month - 1, day);
  const date = new Date(timestamp);
  if (date.getUTCFullYear() !== year || date.getUTCMonth() !== month - 1 || date.getUTCDate() !== day) return null;
  return timestamp / DAY_MS;
}

export function chartDate(day: number, short = false): string {
  return new Date(Math.round(day) * DAY_MS).toLocaleDateString("en-US", {
    timeZone: "UTC", month: "short", day: "numeric", ...(short ? {} : { year: "numeric" }),
  });
}

export function chartBounds(days: number[]): ChartWindow | null {
  if (!days.length) return null;
  return days.reduce((bounds, day) => ({ from: Math.min(bounds.from, day), to: Math.max(bounds.to, day) }), { from: days[0], to: days[0] });
}

export function presetWindow(range: ChartRange, full: ChartWindow, programStart: number | null): ChartWindow {
  if (range === "all") return full;
  if (range === "program") return programStart === null ? full : { from: programStart, to: Math.max(programStart, full.to) };
  return { from: Math.max(full.from, full.to - Number(range) + 1), to: full.to };
}

export function clampWindow(window: ChartWindow, full: ChartWindow): ChartWindow {
  const span = Math.min(Math.max(0, window.to - window.from), full.to - full.from);
  const from = Math.max(full.from, Math.min(window.from, full.to - span));
  return { from: Math.round(from), to: Math.round(from + span) };
}

export function zoomWindow(window: ChartWindow, full: ChartWindow, factor: number): ChartWindow {
  const span = Math.max(1, Math.round(Math.max(1, window.to - window.from) * factor));
  const from = Math.round((window.from + window.to - span) / 2);
  return clampWindow({ from, to: from + span }, full);
}

/** Binary search a date-ordered index; it never changes the plotted sequence. */
export function nearestDateIndex(days: number[], day: number): number {
  if (!days.length) return -1;
  let low = 0;
  let high = days.length - 1;
  while (low < high) {
    const mid = Math.floor((low + high) / 2);
    if (days[mid] < day) low = mid + 1;
    else high = mid;
  }
  return low > 0 && day - days[low - 1] <= days[low] - day ? low - 1 : low;
}