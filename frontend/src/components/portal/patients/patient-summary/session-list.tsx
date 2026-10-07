"use client";

import { useState } from "react";
import { Plus } from "lucide-react";
import { useSummaryData } from "./summary-data-provider";
import type { PatientSummaryRow } from "@/lib/patients/summary";

/**
 * The Sessions tab's session list, from the design's Sessions card.
 *
 * The patient's session packages as a pill selector — each pill shows what is
 * left — over a summary card for the selected package (a progress ring, the
 * remaining/completed figures, and the add-session button) and a history table
 * of the sessions already logged.
 *
 * Each session type carries its own brand colour, from the design: Red Light
 * in the accent red, Lipo/Contour in the brand green, UltraSlim in violet. The
 * ring's stroke and the "Add session" button both take the active package's
 * colour, so the card reads as belonging to that program.
 *
 * `onAddSession` receives the active package's name; the panel turns it into
 * the add-session form. The "Add session" button is disabled at zero remaining,
 * which is the one state where the action cannot be taken.
 */

/** The brand colour each session type uses, from the design. */
const SESSION_COLORS: Record<string, string> = {
  "Red Light Therapy Profile": "#A8421F",
  "Lipo/Contour/Ideal Light": "#0B5D52",
  UltraSlim: "#6D28D9",
};

export function SessionList({
  sessions,
  onAddSession,
}: {
  sessions: PatientSummaryRow["sessions"];
  onAddSession: (sessionName: string) => void;
}) {
  const { readOnly } = useSummaryData();
  const [activeType, setActiveType] = useState(sessions[0]?.name ?? "");
  const activeSession = sessions.find((s) => s.name === activeType) ?? sessions[0];

  if (!activeSession) {
    return <p className="text-sm text-[#6B7280]">No session packages.</p>;
  }

  const activeColor = SESSION_COLORS[activeSession.name] || "#6B7280";

  return (
    <div>
      <div className="mb-5 flex flex-wrap gap-2">
        {sessions.map((s) => (
          <button
            key={s.name}
            onClick={() => setActiveType(s.name)}
            className={`inline-flex items-center gap-2 rounded-full border px-4 py-2 text-sm font-semibold ${
              activeType === s.name
                ? "border-[#101827] bg-[#101827] text-white"
                : "border-line bg-white hover:border-[#0B5D52]"
            }`}
          >
            <i
              className="h-2.5 w-2.5 shrink-0 rounded-full"
              style={{ backgroundColor: SESSION_COLORS[s.name] || "#6B7280" }}
            />
            {s.name}{" "}
            <span className={activeType === s.name ? "text-white/80" : "text-[#6B7280]"}>
              {s.count} left
            </span>
          </button>
        ))}
      </div>

      <div className="flex flex-wrap items-center gap-5 rounded-2xl border border-line bg-white p-5">
        <ProgressRing
          count={activeSession.count}
          total={activeSession.total}
          color={activeColor}
        />

        <div className="min-w-0 flex-1">
          <h3 className="font-serif text-xl text-[#101827]">{activeSession.name}</h3>
          <p className="mt-0.5 text-sm text-[#6B7280]">
            {activeSession.count} of {activeSession.total} remaining ·{" "}
            {activeSession.completed} completed
          </p>
        </div>

        <button
          onClick={() => onAddSession(activeSession.name)}
          disabled={readOnly || activeSession.count < 1}
          style={{ backgroundColor: activeColor }}
          className="inline-flex items-center gap-2 rounded-full px-5 py-2.5 text-sm font-semibold text-white transition-opacity hover:opacity-90 disabled:opacity-40"
        >
          <Plus className="size-4" /> Add session
        </button>
      </div>

      <div className="mt-5 overflow-hidden rounded-2xl border border-line bg-white">
        <div className="overflow-x-auto">
          <table className="w-full min-w-[640px] text-left text-sm">
            <thead className="bg-[#F3F4F1]">
              <tr>
                <th className="px-4 py-3 font-semibold tracking-[0.05em] uppercase text-[#6B7280] text-[12px]">
                  Date
                </th>
                <th className="px-4 py-3 font-semibold tracking-[0.05em] uppercase text-[#6B7280] text-[12px]">
                  Weight
                </th>
                <th className="px-4 py-3 font-semibold tracking-[0.05em] uppercase text-[#6B7280] text-[12px]">
                  Treatment
                </th>
                <th className="px-4 py-3 font-semibold tracking-[0.05em] uppercase text-[#6B7280] text-[12px]">
                  Notes
                </th>
              </tr>
            </thead>
            <tbody className="divide-y divide-line">
              {activeSession.history.length > 0 ? (
                activeSession.history.map((row, i) => (
                  <tr key={i} className="align-top">
                    <td className="whitespace-nowrap px-4 py-3 font-medium text-[#101827]">
                      {row.date}
                    </td>
                    <td className="px-4 py-3 text-[#101827]">{row.weight ? `${row.weight} lbs` : "—"}</td>
                    <td className="px-4 py-3">
                      <div className="flex flex-wrap gap-1.5">
                        {row.areas.map((area) => (
                          <span
                            key={area}
                            className="rounded-md border border-line px-2 py-0.5 text-xs font-medium text-[#0B5D52]"
                          >
                            {area}
                          </span>
                        ))}
                      </div>
                    </td>
                    <td className="px-4 py-3 text-[#101827]/80">{row.notes || "—"}</td>
                  </tr>
                ))
              ) : (
                <tr>
                  <td colSpan={4} className="px-4 py-8 text-center text-[#6B7280]">
                    No sessions recorded yet.
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

/**
 * The summary card's progress ring.
 *
 * Two concentric circles: a full grey track, and an arc whose dash length is
 * the remaining fraction, rotated to start at twelve o'clock. The count sits
 * in the middle. `strokeDasharray` is what makes the arc proportional, so the
 * ring fills as sessions are used rather than showing a static donut.
 */
function ProgressRing({
  count,
  total,
  color,
  size = 64,
}: {
  count: number;
  total: number;
  color: string;
  size?: number;
}) {
  const strokeWidth = 6;
  const radius = (size - strokeWidth) / 2;
  const circumference = 2 * Math.PI * radius;
  const fraction = total > 0 ? count / total : 0;
  const dasharray = `${(circumference * fraction).toFixed(1)} ${circumference.toFixed(1)}`;

  return (
    <div className="relative shrink-0" style={{ width: size, height: size }}>
      <svg
        width={size}
        height={size}
        viewBox={`0 0 ${size} ${size}`}
        className="-rotate-90"
      >
        <circle
          cx={size / 2}
          cy={size / 2}
          r={radius}
          fill="none"
          stroke="#E4E2DC"
          strokeWidth={strokeWidth}
        />
        <circle
          cx={size / 2}
          cy={size / 2}
          r={radius}
          fill="none"
          stroke={color}
          strokeWidth={strokeWidth}
          strokeLinecap="round"
          strokeDasharray={dasharray}
          className="transition-all duration-500 ease-in-out"
        />
      </svg>
      <span className="absolute inset-0 flex items-center justify-center font-serif text-xl text-[#101827]">
        {count}
      </span>
    </div>
  );
}
