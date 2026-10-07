import { LogHistory } from "@/components/portal/progress/log-history";
import { StatStrip } from "@/components/portal/progress/stat-strip";
import { WeightChartPanel } from "@/components/portal/progress/weight-chart";
import { WelcomeHeader } from "@/components/portal/progress/welcome-header";
import { formatPercent, toStats } from "@/lib/progress/data";
import type { ProgressSnapshot } from "@/lib/progress/types";

/**
 * "My Progress", the patient's dashboard.
 *
 * A server component: it takes a snapshot and renders it, and the two pieces
 * that need interactivity — the greeting and the expandable log — are client
 * components underneath it. That split is what makes the eventual swap to Drupal
 * a change to `getProgress()` alone; nothing here fetches, and nothing here
 * knows where the numbers came from.
 *
 * The vertical order and spacing come from "New Design/ChiroThin — My Progress.html":
 * greeting, summary strip, chart, log history. The `space-y-8` between them is
 * the shell's, not this component's, so the page composes with whatever else the
 * dashboard grows.
 */
export function ProgressView({ snapshot }: { snapshot: ProgressSnapshot }) {
  const { summary, entries } = snapshot;

  return (
    <>
      <WelcomeHeader programDay={summary.programDay} goalPercent={formatPercent(summary.goalProgress)} />
      <StatStrip stats={toStats(summary)} summary={summary} entries={entries} />
      <WeightChartPanel entries={entries} goal={summary.goalWeight} programStartDate={summary.programStartDate} />
      <LogHistory entries={entries} />
    </>
  );
}
