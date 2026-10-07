/**
 * The four-stat strip from the design's `#stats`.
 *
 * Purely presentational: the container computes the four figures from the
 * patient array and hands them over, so this component knows nothing about
 * where the numbers come from and can be pointed at the Drupal endpoint's
 * response without changing.
 *
 * Two of the four cards are also quick filters, per the design: "Needs review"
 * and "Needs attention" toggle the table's quick filter rather than being
 * inert figures. A card carrying a `quickFilter` renders as a button —
 * `aria-pressed` tells a screen reader whether it is the active filter — and
 * its label gains the design's " · filter" suffix, but only while it is the
 * active filter: an inactive card is just a figure, and the suffix would
 * otherwise promise a state the card is not in. The other two stay plain
 * panels.
 *
 * The label is the design's `.lbl`, transcribed as the mandated utilities.
 * Note: `text-muted` cannot be used for it — in this theme `muted` is the
 * `#f3f4f1` background token, so `text-muted` renders near-white on the white
 * card and the label disappears. The design's label colour is `#6B7280`, so
 * the arbitrary value is used instead.
 */

export type QuickFilter = "review" | "attention";

export interface StatItem {
  label: string;
  value: string;
  /** When set, the card toggles this quick filter instead of being inert. */
  quickFilter?: QuickFilter;
}

interface StatCardsProps {
  stats: StatItem[];
  activeQuickFilter: QuickFilter | null;
  onToggleQuickFilter: (filter: QuickFilter) => void;
}

export function StatCards({ stats, activeQuickFilter, onToggleQuickFilter }: StatCardsProps) {
  return (
    <div className="flex flex-col divide-y divide-line rounded-xl border border-line bg-surface shadow-panel sm:flex-row sm:divide-x sm:divide-y-0">
      {stats.map((stat) => {
        const quickFilter = stat.quickFilter;
        const filterActive = quickFilter != null && activeQuickFilter === quickFilter;

        const label = (
          <p className="text-[12px] font-semibold tracking-[0.05em] uppercase text-[#6B7280]">
            {stat.label}
            {quickFilter ? (
              <span className="normal-case tracking-normal text-[#0B5D52]"> · filter</span>
            ) : null}
          </p>
        );
        const value = <p className="mt-1 font-serif text-3xl">{stat.value}</p>;

        if (!quickFilter) {
          return (
            <div key={stat.label} className="flex-1 px-6 py-4 text-left">
              {label}
              {value}
            </div>
          );
        }

        return (
          <button
            key={stat.label}
            type="button"
            onClick={() => onToggleQuickFilter(quickFilter)}
            aria-pressed={filterActive}
            className={`flex-1 px-6 py-4 text-left hover:bg-[#E4EEEC]/50 ${
              filterActive ? "bg-[#E4EEEC]" : ""
            }`}
          >
            {label}
            {value}
          </button>
        );
      })}
    </div>
  );
}
