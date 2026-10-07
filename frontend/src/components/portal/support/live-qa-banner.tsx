"use client";

import { useSyncExternalStore } from "react";
import { Video } from "lucide-react";
import { LIVE_QA } from "@/lib/support/config";

/**
 * The "Live Q&A with the ChiroThin team" banner.
 *
 * ## The one section copied from the design
 *
 * Everything else on the Support Center is either real content (the GoHighLevel
 * form, the PDF) or a layout, but this banner is design work: its markup and classes
 * are ported line-for-line from the `Live Q&A banner` in
 * "New Design/ChiroThin — Support Center.html", including the two decorative
 * circles, the pulsing dot, and the four timezone boxes.
 *
 * That includes the odd bits. The circles are `pointer-events-none` in the design so
 * they cannot swallow the join button, and they are kept that way. The timezone boxes
 * are fixed in the design's order rather than sorted by the reader's zone.
 *
 * Token names differ. The design's `accent` and `accent-soft` become `flame` and
 * `flame-soft` here, because `--accent` in this app is a near-white grey
 * (`#f3f4f1`) and would have rendered the orange ping dot and glow as white on
 * white. `primary-dark` becomes `brand-dark` and `ink` becomes `foreground`. The
 * table in `@/lib/support/config` has the full mapping and explains why it is
 * written as a table rather than as values: Tailwind finds class names by scanning
 * source text, so these are spelled out in full rather than assembled from constants.
 */
export function LiveQaBanner() {
  return (
    <section
      className="relative overflow-hidden rounded-2xl bg-gradient-to-br from-brand-dark to-foreground p-6 text-white shadow-panel sm:p-8"
    >
      {/* Decoration only — `pointer-events-none` in the design, and it matters here:
          the circles overlap the top-right corner where the join button sits. */}
      <div
        aria-hidden="true"
        className="pointer-events-none absolute -right-16 -top-16 h-56 w-56 rounded-full bg-white/5"
      />
      <div
        aria-hidden="true"
        className="pointer-events-none absolute -bottom-20 right-24 h-40 w-40 rounded-full bg-flame/20 blur-2xl"
      />

      <div className="relative flex flex-col gap-6 md:flex-row md:items-center md:justify-between">
        <div className="max-w-lg">
          <span className="inline-flex items-center gap-2 rounded-full bg-white/10 px-3 py-1 text-[11px] font-medium tracking-wide">
            {/* The design's "live now" ping: an expanding ring behind a solid dot. */}
            <span className="relative flex h-2 w-2">
              <span className="absolute inline-flex h-full w-full animate-ping rounded-full bg-flame opacity-75" />
              <span className="relative inline-flex h-2 w-2 rounded-full bg-flame" />
            </span>
            {LIVE_QA.cadence}
          </span>

          <h3 className="mt-3 font-serif text-2xl leading-snug sm:text-3xl">
            {LIVE_QA.title}
          </h3>
          <p className="mt-2 text-sm text-white/70">{LIVE_QA.blurb}</p>

          <div className="mt-4 grid grid-cols-4 gap-2 text-center sm:max-w-md">
            {LIVE_QA.timeZoneLabels.map(({ time, zone }) => (
              <div key={zone} className="rounded-xl bg-white/10 px-2 py-2">
                <p className="text-sm font-semibold">{time}</p>
                <p className="text-[10px] tracking-wider text-white/60">{zone}</p>
              </div>
            ))}
          </div>
        </div>

        <div className="flex shrink-0 flex-col items-start gap-2 md:items-end">
          <a
            href={LIVE_QA.joinUrl}
            target="_blank"
            rel="noopener noreferrer"
            className={`inline-flex items-center gap-2 rounded-xl bg-white px-5 py-3 text-sm font-semibold text-primary shadow-lg transition hover:-translate-y-0.5 hover:bg-primary-soft`}
          >
            <Video className="h-[18px] w-[18px]" aria-hidden="true" />
            {LIVE_QA.joinLabel}
          </a>
          <NextSession />
        </div>
      </div>
    </section>
  );
}

/**
 * "Today · Thursday, Oct 8" / "Next session · Thursday, Oct 15".
 *
 * Computed after mount rather than during render on purpose: it reads the viewer's
 * clock and locale, so evaluating it on the server would bake in the *server's*
 * Thursday and then disagree with the browser on hydration. The design's own
 * arithmetic is kept — including the `|| 0`, which is what turns today itself into
 * `0` days ahead and yields "Today".
 *
 * Renders nothing until mounted rather than a placeholder of the same width, so the
 * server and first client render agree exactly.
 */
function NextSession() {
  // `useSyncExternalStore`, not `useState` + `useEffect`.
  //
  // This reads the viewer's clock and locale, so evaluating it during render would
  // bake the *server's* Thursday into the HTML and then disagree with the browser on
  // hydration. The usual fix is to render null on the server and fill in from an
  // effect — but that flashes the label in after mount and trips
  // `react-hooks/set-state-in-effect`, which is right: the value was never going to
  // change between renders, only between environments.
  //
  // Subscribing to the store instead makes the distinction explicit. The server
  // snapshot is null; the client takes the real value, and React reuses that node
  // rather than discarding the server's, so there is no mismatch and no flash.
  const label = useSyncExternalStore(subscribeToNothing, getClientLabel, getServerLabel);
  if (!label) return null;

  return (
    <p className="text-xs text-white/60" suppressHydrationWarning>
      {label}
    </p>
  );
}

/** The label depends only on the clock, so there is nothing to subscribe to. */
function subscribeToNothing(): () => void {
  return () => {};
}

/** Design arithmetic, unchanged: the `|| 0` is what makes today itself read "Today". */
function getClientLabel(): string {
  const now = new Date();
  const target = new Date(now);
  target.setDate(now.getDate() + ((LIVE_QA.weekday - now.getDay() + 7) % 7 || 0));
  const date = target.toLocaleDateString(undefined, {
    weekday: "long",
    month: "short",
    day: "numeric",
  });
  return `${now.getDay() === LIVE_QA.weekday ? "Today · " : "Next session · "}${date}`;
}

/** Server render: omit the line rather than guess at the reader's timezone. */
function getServerLabel(): null {
  return null;
}