import { useId, type ReactNode } from "react";
import { Flag } from "lucide-react";

import { progressCardDetails } from "@/lib/progress/summary-cards";
import type { ProgressEntry, ProgressStat, ProgressSummary, StatTone } from "@/lib/progress/types";

const TONE_CLASS: Record<StatTone, string> = {
  default: "text-foreground", positive: "text-positive", accent: "text-flame",
};
const LABEL_CLASS = "text-[11px] font-medium uppercase tracking-wide text-muted-foreground";

/** The goal panel and three summary cards from New Design/progress.html. */
export function StatStrip({ stats, summary, entries }: { stats: ProgressStat[]; summary: ProgressSummary; entries: ProgressEntry[] }) {
  const details = progressCardDetails(summary, entries);
  const stat = (label: string) => stats.find((item) => item.label === label);
  const goal = stat("Goal Weight");
  const achieved = stat("Goal Achieved");
  const ringLength = 2 * Math.PI * 52;

  return (
    <div className="space-y-4" aria-label="Progress summary" style={{ fontFamily: "var(--font-inter), system-ui, sans-serif" }}>
      <section aria-label="Goal progress" className="grid grid-cols-1 gap-6 rounded-xl border border-border bg-surface p-4 shadow-panel sm:p-6 md:grid-cols-[auto_1fr] md:items-center md:gap-10">
        <div className="flex min-w-0 items-center gap-3 sm:gap-5">
          <div className="relative size-28 shrink-0 sm:size-32" role="progressbar" aria-label="Goal achieved" aria-valuemin={0} aria-valuemax={100} aria-valuenow={details.visualPercent} aria-valuetext={`${achieved?.value?.toFixed(0) ?? "N/A"} percent of goal achieved`}>
            <svg viewBox="0 0 128 128" className="size-full -rotate-90" aria-hidden="true">
              <circle cx={64} cy={64} r={52} fill="none" className="stroke-primary-soft" strokeWidth={12} />
              <circle cx={64} cy={64} r={52} fill="none" className="stroke-primary" strokeWidth={12} strokeLinecap="round" strokeDasharray={ringLength} strokeDashoffset={ringLength * (1 - details.visualPercent / 100)} />
            </svg>
            <div className="absolute inset-[25%] flex flex-col items-center justify-center p-0.5 text-center">
              <p className="flex items-baseline justify-center gap-0.5 whitespace-nowrap font-serif text-2xl leading-none text-foreground sm:text-3xl">
                <span>{achieved?.value?.toFixed(achieved.decimals) ?? "N/A"}</span>
                {achieved?.value != null ? <span className="text-xs text-muted-foreground sm:text-sm">%</span> : null}
              </p>
              <span className="mt-1 max-w-full text-[9px] leading-[10px] font-medium uppercase text-muted-foreground sm:text-[10px] sm:leading-3">Goal<br />achieved</span>
            </div>
          </div>
          <div className="min-w-0 md:hidden"><Metric stat={goal} label="Goal Weight" /></div>
        </div>
        <div className="min-w-0">
          <div className="mb-5 grid grid-cols-2 gap-4 md:grid-cols-3">
            <div className="hidden md:block"><Metric stat={goal} label="Goal Weight" /></div>
            <Metric stat={{ label: "Current", value: details.currentWeight, unit: "lbs", decimals: 1, tone: "default" }} />
            <Metric stat={{ label: "To go", value: details.remaining, unit: "lbs", decimals: 1, tone: "accent" }} />
          </div>
          <div className="relative h-3 rounded-full bg-primary-soft" aria-hidden="true">
            <div className="h-full rounded-full bg-gradient-to-r from-primary to-positive" style={{ width: `${details.visualPercent}%` }} />
            <div className="absolute top-1/2 size-5 -translate-x-1/2 -translate-y-1/2 rounded-full border-4 border-white bg-primary shadow" style={{ left: `${details.visualPercent}%` }} />
          </div>
          <div className="mt-2 flex flex-wrap justify-between gap-2 text-xs text-muted-foreground">
            <span>Start {details.startWeight === null ? "N/A" : `${details.startWeight.toFixed(1)} lbs`}</span>
            <span className="flex items-center gap-1 font-medium text-flame"><Flag className="size-3" aria-hidden="true" />Goal {summary.goalWeight === null ? "N/A" : `${summary.goalWeight.toFixed(1)} lbs`}</span>
          </div>
        </div>
      </section>

      <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
        <SummaryCard label="Net Weight Loss">
          <Metric stat={stat("Net Weight Loss")} label="Net Weight Loss" />
          <div className="mt-3"><WeightSparkline entries={details.preview} /></div>
          <p className="mt-1 text-xs text-muted-foreground">{details.preview.length ? `Weight trend · ${previewLabel(details.preview.length, entries.length)}` : "Log your weight to see your trend."}</p>
        </SummaryCard>
        <SummaryCard label="Overall Weight Loss">
          <Metric stat={stat("Overall Weight Loss")} label="Overall Weight Loss" />
          <div className="mt-3"><LossBars entries={details.preview} /></div>
          <p className="mt-1 text-xs text-muted-foreground">{details.preview.length ? `Loss to date · ${previewLabel(details.preview.length, entries.length)}` : "Log your weight to track your loss."}</p>
        </SummaryCard>
        <SummaryCard label="Net Inches Lost">
          <Metric stat={stat("Net Inches Lost")} label="Net Inches Lost" />
          <div className="mt-3 space-y-1.5" aria-label="Largest recorded inch reductions">
            {details.topSites.map((site) => <div key={site.area} className="flex items-center gap-2 text-xs" title={`${site.area}: ${site.first.toFixed(1)} → ${site.current.toFixed(1)} in (${site.readings} readings)`}>
              <span className="w-20 shrink-0 truncate text-muted-foreground">{site.area}</span>
              <div className="h-1.5 min-w-0 flex-1 rounded-full bg-flame-soft" aria-hidden="true"><div className="h-full rounded-full bg-flame" style={{ width: `${site.lost! / details.topSites[0].lost! * 100}%` }} /></div>
              <span className="w-9 text-right font-medium text-foreground">{site.lost!.toFixed(1)}<span className="sr-only"> inches lost</span></span>
            </div>)}
          </div>
          <p className="mt-2 text-xs text-muted-foreground">{details.topSites.length ? `First to latest · ${details.sites.length} sites measured` : !details.sites.length ? "Add measurements to a daily log to track inches." : details.sites.every((site) => site.readings === 1) ? "Add a second measurement to compare changes." : "No reductions recorded yet."}</p>
        </SummaryCard>
      </div>
    </div>
  );
}

function previewLabel(count: number, total: number) {
  return count < total ? `latest ${count} logs` : `${count} ${count === 1 ? "entry" : "entries"}`;
}

function SummaryCard({ label, children }: { label: string; children: ReactNode }) {
  return <section aria-label={label} className="min-w-0 rounded-xl border border-border bg-surface p-5 shadow-panel">{children}</section>;
}

function Metric({ stat, label }: { stat: ProgressStat | undefined; label?: string }) {
  return <div><p className={LABEL_CLASS}>{label ?? stat?.label}</p><p className={`mt-1.5 font-serif text-3xl ${TONE_CLASS[stat?.tone ?? "default"]}`}><Amount stat={stat} /></p></div>;
}

function Amount({ stat }: { stat: ProgressStat | undefined }) {
  if (stat?.value === null || stat?.value === undefined) return <>N/A</>;
  return <>{stat.value.toFixed(stat.decimals)}{stat.unit ? <span className={`${stat.unit === "%" ? "text-base" : "text-lg"} text-muted-foreground`}> {stat.unit}</span> : null}</>;
}

/** A preview of actual logged weights, with a safe centre for a single reading. */
function WeightSparkline({ entries }: { entries: ProgressEntry[] }) {
  const gradientId = `weight-spark-${useId().replace(/:/g, "")}`;
  if (!entries.length) return <div className="h-11" />;
  const weights = entries.map((entry) => entry.weight);
  const min = Math.min(...weights), max = Math.max(...weights);
  const x = (index: number) => entries.length === 1 ? 80 : 2 + index / (entries.length - 1) * 156;
  const y = (value: number) => max === min ? 22 : 4 + (1 - (value - min) / (max - min)) * 36;
  const points = weights.map((weight, index) => `${x(index)},${y(weight)}`).join(" ");
  return <svg viewBox="0 0 160 44" className="h-11 w-full" preserveAspectRatio="none" role="img" aria-label={`Weight trend across ${entries.length} logged readings`}>
    <title>{entries.map((entry) => `${entry.date}: ${entry.weight.toFixed(1)} lbs`).join("; ")}</title>
    <defs><linearGradient id={gradientId} x1={0} y1={0} x2={0} y2={1}><stop offset="0" stopColor="var(--positive)" stopOpacity={0.25} /><stop offset="1" stopColor="var(--positive)" stopOpacity={0} /></linearGradient></defs>
    {entries.length > 1 ? <polygon points={`2,44 ${points} 158,44`} fill={`url(#${gradientId})`} /> : null}
    <polyline points={points} fill="none" className="stroke-positive" strokeWidth={2} strokeLinejoin="round" strokeLinecap="round" vectorEffect="non-scaling-stroke" />
    <circle cx={x(entries.length - 1)} cy={y(weights[weights.length - 1])} r={3} className="fill-positive" />
  </svg>;
}

function LossBars({ entries }: { entries: ProgressEntry[] }) {
  const max = Math.max(1, ...entries.map((entry) => entry.loss));
  return <div className="flex h-11 items-end gap-0.5" role="img" aria-label={`Logged weight loss to date across ${entries.length} entries`}>
    {entries.map((entry, index) => <div key={entry.id} className={`min-w-0 flex-1 rounded-sm ${index === entries.length - 1 ? "bg-primary" : "bg-primary/25"}`} style={{ height: `${Math.max(0, entry.loss) / max * 100}%` }} title={`${entry.date}: ${entry.loss.toFixed(1)} lbs lost to date`} />)}
  </div>;
}