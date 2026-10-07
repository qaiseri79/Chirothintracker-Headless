"use client";

import { useState } from "react";
import type { PatientSummaryRow } from "@/lib/patients/summary";

/**
 * The Progress tab: the patient's weight trend and how their measurements
 * have moved.
 *
 * The chart is a small hand-rolled SVG line — the project has no chart
 * library — with a date-range selector above it (30/60/90 days, or the whole
 * history). The measurements table compares each area's first and latest
 * reading and colours the change by direction: down is the brand green, up is
 * the accent red.
 */

const RANGES = [
  { label: "30 days", days: 30 },
  { label: "60 days", days: 60 },
  { label: "90 days", days: 90 },
  { label: "All", days: 0 },
];

/** The chart's geometry, in viewBox units. */
const CHART = { width: 720, height: 240, pad: { top: 16, right: 16, bottom: 28, left: 44 } };

export function ProgressTab({
  weightHistory,
  measurementChanges,
}: {
  weightHistory: PatientSummaryRow["weightHistory"];
  measurementChanges: PatientSummaryRow["measurementChanges"];
}) {
  const [range, setRange] = useState(60);

  const sorted = [...weightHistory].sort((a, b) => a.date.localeCompare(b.date));
  const latest = sorted.length ? new Date(sorted[sorted.length - 1].date) : new Date();
  const visible =
    range === 0
      ? sorted
      : sorted.filter(
          (point) => (latest.getTime() - new Date(point.date).getTime()) / 86400000 <= range,
        );

  const weights = visible.map((point) => point.weight);
  const min = weights.length ? Math.min(...weights) : 0;
  const max = weights.length ? Math.max(...weights) : 0;
  const span = max - min || 1;

  const plotW = CHART.width - CHART.pad.left - CHART.pad.right;
  const plotH = CHART.height - CHART.pad.top - CHART.pad.bottom;
  const x = (i: number) =>
    CHART.pad.left + (visible.length > 1 ? (i / (visible.length - 1)) * plotW : plotW / 2);
  const y = (w: number) => CHART.pad.top + (1 - (w - min) / span) * plotH;
  const points = visible.map((point, i) => `${x(i)},${y(point.weight)}`).join(" ");

  return (
    <div className="space-y-5">
      <div className="rounded-2xl border border-line bg-white p-5">
        <div className="flex flex-wrap items-center justify-between gap-3">
          <h3 className="font-serif text-lg text-[#101827]">Weight trend</h3>
          <div className="flex gap-1">
            {RANGES.map((r) => (
              <button
                key={r.label}
                type="button"
                onClick={() => setRange(r.days)}
                className={`rounded-full px-3 py-1.5 text-xs font-semibold ${
                  range === r.days
                    ? "bg-[#0B5D52] text-white"
                    : "border border-line text-[#6B7280] hover:border-[#0B5D52]"
                }`}
              >
                {r.label}
              </button>
            ))}
          </div>
        </div>

        {visible.length > 0 ? (
          <svg
            viewBox={`0 0 ${CHART.width} ${CHART.height}`}
            className="mt-4 w-full"
            role="img"
            aria-label="Weight trend chart"
          >
            {[min, (min + max) / 2, max].map((w, index) => (
              <g key={index}>
                <line
                  x1={CHART.pad.left}
                  x2={CHART.width - CHART.pad.right}
                  y1={y(w)}
                  y2={y(w)}
                  stroke="#E4E2DC"
                  strokeWidth={1}
                />
                <text
                  x={CHART.pad.left - 8}
                  y={y(w) + 4}
                  textAnchor="end"
                  fontSize={11}
                  className="fill-[#6B7280]"
                >
                  {w}
                </text>
              </g>
            ))}
            <polyline
              points={points}
              fill="none"
              stroke="#0B5D52"
              strokeWidth={2.5}
              strokeLinecap="round"
              strokeLinejoin="round"
            />
            {visible.map((point, i) => (
              <circle key={`${point.date}-${i}`} cx={x(i)} cy={y(point.weight)} r={4} fill="#0B5D52" />
            ))}
            <text
              x={x(0)}
              y={CHART.height - 8}
              textAnchor="start"
              fontSize={11}
              className="fill-[#6B7280]"
            >
              {visible[0].date}
            </text>
            <text
              x={x(visible.length - 1)}
              y={CHART.height - 8}
              textAnchor="end"
              fontSize={11}
              className="fill-[#6B7280]"
            >
              {visible[visible.length - 1].date}
            </text>
          </svg>
        ) : (
          <p className="mt-4 text-center text-sm text-[#6B7280]">No weight history yet.</p>
        )}
      </div>

      <div className="overflow-hidden rounded-2xl border border-line bg-white">
        <div className="border-b border-line px-5 py-4">
          <h3 className="font-serif text-lg text-[#101827]">Body measurements</h3>
          <p className="text-xs text-[#6B7280]">First recorded vs latest, in inches.</p>
        </div>
        <div className="overflow-x-auto">
          <table className="w-full min-w-[520px] text-left text-sm">
            <thead className="bg-[#F3F4F1]">
              <tr>
                <th className="px-4 py-3 text-[12px] font-semibold uppercase tracking-[0.05em] text-[#6B7280]">
                  Area
                </th>
                <th className="px-4 py-3 text-[12px] font-semibold uppercase tracking-[0.05em] text-[#6B7280]">
                  First
                </th>
                <th className="px-4 py-3 text-[12px] font-semibold uppercase tracking-[0.05em] text-[#6B7280]">
                  Latest
                </th>
                <th className="px-4 py-3 text-right text-[12px] font-semibold uppercase tracking-[0.05em] text-[#6B7280]">
                  Change
                </th>
              </tr>
            </thead>
            <tbody className="divide-y divide-line">
              {measurementChanges.length > 0 ? (
                measurementChanges.map((row) => {
                  const change = row.latest - row.first;
                  return (
                    <tr key={row.area}>
                      <td className="px-4 py-3 font-medium text-[#101827]">{row.area}</td>
                      <td className="px-4 py-3 text-[#101827]">{row.first.toFixed(1)} in</td>
                      <td className="px-4 py-3 text-[#101827]">{row.latest.toFixed(1)} in</td>
                      <td
                        className={`px-4 py-3 text-right font-semibold ${
                          change < 0
                            ? "text-[#3D8361]"
                            : change > 0
                              ? "text-[#A8421F]"
                              : "text-[#6B7280]"
                        }`}
                      >
                        {change > 0 ? "+" : ""}
                        {change.toFixed(1)} in
                      </td>
                    </tr>
                  );
                })
              ) : (
                <tr>
                  <td colSpan={4} className="px-4 py-8 text-center text-[#6B7280]">
                    No measurements recorded yet.
                  </td>
                </tr>
              )}
            </tbody>
          </table>
        </div>
      </div>
    </div>
  );
}
