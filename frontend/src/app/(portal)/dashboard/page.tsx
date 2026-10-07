import { ProgressView } from "@/components/portal/progress/progress-view";
import { getProgress, ProgressUnavailableError } from "@/lib/progress/data";

/**
 * The patient dashboard — "My Progress".
 *
 * A server component, and the only place on this route that reaches for data.
 * `getProgress()` reads the signed-in patient's own snapshot from Drupal.
 *
 * `force-dynamic` because everything here is per-account: the snapshot describes
 * the signed-in patient, and a cached copy of one patient's chart served to
 * another would be a data leak rather than a stale render. The guard in
 * `layout.tsx` already redirects anyone without a patient session.
 */
export const dynamic = "force-dynamic";

export default async function DashboardPage() {
  let snapshot;
  try {
    snapshot = await getProgress();
  } catch (error) {
    // Previously this fell back to placeholder figures, which made a broken
    // backend look like a working page. It now says so instead.
    console.error("[progress] dashboard could not load the snapshot:", error);
    return <ProgressUnavailable status={error instanceof ProgressUnavailableError ? error.status : 0} />;
  }

  return <ProgressView snapshot={snapshot} />;
}

/**
 * Shown when the patient's own progress cannot be read.
 *
 * Deliberately says the data is unavailable and does not attempt to render the
 * chart, log or summary tiles: every one of those would need a figure, and a
 * figure that is not the patient's is worse than no figure. The status is
 * included for the log line and for support, not for the patient.
 */
function ProgressUnavailable({ status }: { status: number }) {
  return (
    <section className="rounded-xl border border-border bg-surface p-6 shadow-panel">
      <h2 className="font-serif text-lg text-foreground">Your progress is unavailable</h2>
      <p className="mt-2 max-w-prose text-sm text-muted-foreground">
        We could not load your weigh-ins just now, so nothing is shown here rather than
        showing you figures that are not yours. Please refresh in a moment. If this keeps
        happening, let your chiropractor know.
      </p>
      {status > 0 ? (
        <p className="mt-3 text-xs text-muted-foreground">Reference: HTTP {status}</p>
      ) : null}
    </section>
  );
}
