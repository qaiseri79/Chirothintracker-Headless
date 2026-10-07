"use client";

import { useEffect, useRef, useState } from "react";
import { createPortal } from "react-dom";
import { ChevronDown, ChevronLeft, ChevronRight, Flag, Moon, MoveHorizontal, Plus, Utensils } from "lucide-react";
import type { PatientSummaryRow } from "@/lib/patients/summary";

const FLAG_COLORS: Record<string, string> = {
  "Period Cycle": "#E11D48",
  Constipation: "#D97706",
  "Cheated on program": "#B91C1C",
  "Missed Drop-protein Day": "#0284C7",
};

function adherenceColor(score: number) {
  return score >= 9 ? "#3D8361" : score >= 6 ? "#F59E0B" : "#DC2626";
}

type Popover = { id: number; type: "food" | "measurements"; top: number; left: number };

/** Compact log cards and controls from New Design/chirothin-patient-summary-v9.html. */
export function DailyLogs({ logsList, logsTotal, loading = false, error, hasMore = false, onLoadMore, onRetry, onLogProgress, canLogProgress = true }: {
  logsList: PatientSummaryRow["logsList"];
  logsTotal?: number;
  loading?: boolean;
  error?: string;
  hasMore?: boolean;
  onLoadMore?: () => void;
  onRetry?: () => void;
  onLogProgress?: () => void;
  canLogProgress?: boolean;
}) {
  const scrollRef = useRef<HTMLDivElement>(null);
  const popupRef = useRef<HTMLDivElement>(null);
  const [popover, setPopover] = useState<Popover | null>(null);
  const activeLog = logsList.find((log) => log.id === popover?.id);

  useEffect(() => {
    if (!popover) return;
    const closeOnScroll = (event: Event) => {
      if (event.target instanceof Node && popupRef.current?.contains(event.target)) return;
      setPopover(null);
    };
    const close = () => setPopover(null);
    const escape = (event: KeyboardEvent) => { if (event.key === "Escape") close(); };
    document.addEventListener("scroll", closeOnScroll, true);
    window.addEventListener("resize", close);
    document.addEventListener("keydown", escape);
    return () => {
      document.removeEventListener("scroll", closeOnScroll, true);
      window.removeEventListener("resize", close);
      document.removeEventListener("keydown", escape);
    };
  }, [popover]);

  function openDetails(type: Popover["type"], id: number, button: HTMLButtonElement) {
    if (popover?.id === id && popover.type === type) { setPopover(null); return; }
    const rect = button.getBoundingClientRect();
    setPopover({ id, type,
      top: Math.max(8, Math.min(rect.bottom + 4, window.innerHeight - Math.min(360, window.innerHeight * 0.7) - 8)),
      left: Math.max(8, Math.min(rect.left, window.innerWidth - 336)),
    });
  }

  function scroll(direction: number) {
    const strip = scrollRef.current;
    if (!strip) return;
    strip.scrollBy({ left: direction * 480, behavior: "smooth" });
    if (direction > 0 && strip.scrollLeft + strip.clientWidth >= strip.scrollWidth - 40 && hasMore && !loading && !error) onLoadMore?.();
  }

  return (
    <section className="mt-4 min-w-0 font-sans" aria-label="Daily logs" style={{ fontFamily: "var(--font-inter), system-ui, sans-serif" }}>
      <div className="mb-2 flex flex-wrap items-center gap-x-4 gap-y-1">
        <button type="button" onClick={onLogProgress} disabled={!canLogProgress || !onLogProgress} className="inline-flex items-center gap-1.5 rounded-[8px] border border-dashed border-primary/50 px-3 py-1.5 text-xs font-semibold text-primary transition hover:bg-primary-soft disabled:opacity-50">
          <Plus aria-hidden="true" className="h-3.5 w-3.5" />Log progress
        </button>
        <h3 className="text-sm font-semibold">Daily logs</h3>
        <p className="text-xs text-muted-foreground">Showing {logsList.length} of {logsTotal ?? logsList.length} logs</p>
        <div className="flex flex-wrap gap-x-3 gap-y-1">
          {Object.entries(FLAG_COLORS).map(([label, color]) => (
            <span key={label} className="flex items-center gap-1 text-xs text-muted-foreground">
              <i aria-hidden="true" className="h-2.5 w-2.5 rounded-sm" style={{ backgroundColor: color }} />{label}
            </span>
          ))}
        </div>
        <div className="ml-auto flex gap-1">
          <button type="button" onClick={() => scroll(-1)} className="rounded-[6px] border border-line bg-surface p-1.5 hover:border-primary" aria-label="Scroll logs left"><ChevronLeft className="h-4 w-4" /></button>
          <button type="button" onClick={() => scroll(1)} className="rounded-[6px] border border-line bg-surface p-1.5 hover:border-primary" aria-label="Scroll logs right"><ChevronRight className="h-4 w-4" /></button>
        </div>
      </div>
      {error && <p role="alert" className="mb-2 text-xs text-destructive">{error}{onRetry && <> <button type="button" className="underline" onClick={onRetry}>Retry</button></>}</p>}
      <div ref={scrollRef} className="flex gap-3 overflow-x-auto pb-2" aria-busy={loading}
        onScroll={(event) => {
          const strip = event.currentTarget;
          if (hasMore && !loading && !error && strip.scrollLeft > 0 && strip.scrollLeft + strip.clientWidth >= strip.scrollWidth - 260) onLoadMore?.();
        }}>
        {logsList.map((log) => {
          const flagColor = log.flag ? FLAG_COLORS[log.flag] ?? "#6B7280" : undefined;
          const delta = log.weightDelta;
          const sleepLogged = Boolean(log.sleep) && log.sleep !== "0";
          return (
            <article key={log.id} className="w-60 shrink-0 rounded-[12px] border border-line border-l-4 bg-surface p-3.5" style={{ borderLeftColor: flagColor }}>
              <div className="flex items-start justify-between">
                <div><p className="text-sm font-semibold">{log.date}{log.doctorEntered && <span className="ml-1.5 rounded bg-primary-soft px-1.5 py-0.5 text-[11px] font-semibold text-brand-dark">Doctor</span>}</p><p className="text-xs text-muted-foreground">Log Day #{log.dayNumber}</p></div>
                <div className="text-center" title="Adherence grade">
                  <span className="inline-flex h-8 w-8 items-center justify-center rounded-full text-sm font-bold text-white" style={{ backgroundColor: log.adherence === null ? "var(--canvas)" : adherenceColor(log.adherence), color: log.adherence === null ? "var(--muted-foreground)" : undefined }}>{log.adherence ?? "—"}</span>
                  <p className="text-[11px] uppercase tracking-wide text-muted-foreground">Adherence</p>
                </div>
              </div>
              <div className="mt-3">
                <p className="text-xs font-semibold uppercase tracking-[0.05em] text-muted-foreground">Weight</p>
                <p className="font-serif text-2xl leading-tight">{log.weight.toFixed(2)} <span className="text-sm text-muted-foreground">lbs</span></p>
                <p className={`text-xs font-medium ${delta === null || delta === 0 ? "text-muted-foreground" : delta < 0 ? "text-positive" : "text-red-600"}`}>
                  {delta === null ? "First entry in view" : `${delta < 0 ? "▼" : delta > 0 ? "▲" : "•"} ${Math.abs(delta).toFixed(2)} lbs vs previous log`}
                </p>
              </div>
              <div className="mt-3 min-h-[24px]">
                {log.flag ? <span className="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-semibold text-white" style={{ backgroundColor: flagColor }}>{log.flag}</span> : <span className="text-xs text-muted-foreground">No flags</span>}
              </div>
              <div className="mt-3 space-y-0.5 text-xs text-muted-foreground">
                <p>Water: <span className="font-semibold text-foreground">{log.water.toFixed(0)} oz</span></p>
                {log.bloodSugar !== null && log.bloodSugar !== undefined && <p>Blood sugar: <span className="font-semibold text-foreground">{log.bloodSugar} mg/dL</span></p>}
                {log.bloodPressure && <p>BP: <span className="font-semibold text-foreground">{log.bloodPressure}</span></p>}
                <p className="flex items-center gap-1"><Moon className="h-3 w-3 text-sky-600" />Sleep: {sleepLogged ? <span className="font-semibold text-foreground">{log.sleep} hrs</span> : "not logged"}</p>
              </div>
              <div className="mt-2 flex flex-wrap gap-1.5">
                {log.hasFood ? <button type="button" onClick={(event) => openDetails("food", log.id, event.currentTarget)} className="inline-flex items-center gap-1 rounded-[6px] bg-flame-soft px-2 py-1 text-xs font-medium text-[#A8421F] hover:ring-1 hover:ring-current" aria-haspopup="dialog" aria-expanded={popover?.id === log.id && popover.type === "food"}>
                  <Utensils className="h-3 w-3" />Food<ChevronDown className="h-3 w-3" />
                </button> : <span className="inline-flex items-center gap-1 rounded-[6px] bg-canvas px-2 py-1 text-xs font-medium text-muted-foreground/70"><Utensils className="h-3 w-3" />No food log</span>}
                {log.hasMeasurements ? <button type="button" onClick={(event) => openDetails("measurements", log.id, event.currentTarget)} className="inline-flex items-center gap-1 rounded-[6px] bg-violet-100 px-2 py-1 text-xs font-medium text-violet-700 hover:ring-1 hover:ring-current" aria-haspopup="dialog" aria-expanded={popover?.id === log.id && popover.type === "measurements"}>
                  <MoveHorizontal className="h-3 w-3" />Measurements<ChevronDown className="h-3 w-3" />
                </button> : <span className="inline-flex items-center gap-1 rounded-[6px] bg-canvas px-2 py-1 text-xs font-medium text-muted-foreground/70"><MoveHorizontal className="h-3 w-3" />No measurements</span>}
                {!log.doctorEntered && log.adherence !== null && <span className={`inline-flex items-center gap-1 rounded-[6px] px-2 py-1 text-xs font-medium ${log.isOnPlan ? "bg-positive-soft text-positive" : "bg-red-100 text-red-700"}`}><Flag className="h-3 w-3" />{log.isOnPlan ? "On plan" : "Cheated"}</span>}
              </div>
            </article>
          );
        })}
        {!logsList.length && <p role="status" className="py-4 text-xs text-muted-foreground">{loading ? "Loading logs…" : error ? "" : "No daily logs yet."}</p>}
        {logsList.length > 0 && (hasMore ? <button type="button" onClick={onLoadMore} disabled={loading || !onLoadMore} className="flex w-48 shrink-0 items-center justify-center rounded-[12px] border border-dashed border-line text-xs font-medium text-muted-foreground hover:border-primary hover:text-primary disabled:opacity-60" aria-busy={loading}>
          {loading ? <span role="status" className="animate-pulse">Loading older logs…</span> : "Scroll or click for older logs →"}
        </button> : <div className="flex w-40 shrink-0 items-center justify-center rounded-[12px] border border-dashed border-line text-xs text-muted-foreground">Start of program</div>)}
      </div>
      <p className="mt-1 text-xs text-muted-foreground">Newest first. Left border color = flagged reason. Adherence: green 9-10, amber 6-8, red 5 or less.</p>
      {popover && activeLog && createPortal(<>
        <div className="fixed inset-0 z-40" onClick={() => setPopover(null)} aria-hidden="true" />
        <div ref={popupRef} role="dialog" aria-label={popover.type === "food" ? "Food log details" : "Measurement details"} className="fixed z-50 font-sans max-h-[min(70vh,360px)] w-80 max-w-[calc(100vw-16px)] overflow-y-auto rounded-[12px] border border-line bg-surface p-4 shadow-xl" style={{ top: popover.top, left: popover.left, fontFamily: "var(--font-inter), system-ui, sans-serif" }}>
          <p className="text-xs font-semibold uppercase tracking-[0.05em] text-muted-foreground">{popover.type === "food" ? "Food" : "Measurements"} · {activeLog.date} · Log Day #{activeLog.dayNumber}</p>
          {popover.type === "food" ? activeLog.foodDetails.map((meal) => (
            <div key={meal.category}>
              <p className="mt-3 text-sm font-semibold">{meal.category}</p>
              {meal.category === "Other" ? meal.items.map((item, index) => <p key={index} className="whitespace-pre-wrap break-words text-xs text-muted-foreground">{item}</p>) : <DetailRows rows={meal.items.flatMap((item) => item.split(/ \| (?=(?:Protein|Veg|Free Veg|Fruit|Bread): )/)).map((item) => {
                const field = /^(Protein|Veg|Free Veg|Fruit|Bread):\s*([\s\S]*)$/.exec(item);
                return field ? { label: field[1], value: field[2] } : { value: item };
              })} />}
            </div>
          )) : <div className="mt-2"><DetailRows rows={activeLog.measurementDetails.map((measurement) => ({ label: measurement.area, value: measurement.value }))} /></div>}
        </div>
      </>, document.body)}
    </section>
  );
}

/** The artifact uses the same labeled rows for food and body measurements. */
function DetailRows({ rows }: { rows: Array<{ label?: string; value: string }> }) {
  return <dl>{rows.map((row, index) => (
    <div key={index} className="flex gap-2 py-0.5">
      {row.label && <dt className="w-24 shrink-0 text-xs font-semibold text-[#A8421F]">{row.label}</dt>}
      <dd className="min-w-0 whitespace-pre-wrap break-words text-xs">{row.value}</dd>
    </div>
  ))}</dl>;
}
