"use client";

import { useEffect, useId, useMemo, useRef, useState } from "react";
import type { PointerEvent as ReactPointerEvent } from "react";
import { ChevronLeft, ChevronRight, Minus, Plus, RotateCcw, X } from "lucide-react";

import { Button } from "@/components/ui/button";
import { chartBounds, chartDate, chartDay, clampWindow, nearestDateIndex, presetWindow, zoomWindow } from "@/lib/progress/chart";
import type { ChartRange, ChartWindow } from "@/lib/progress/chart";
import type { ProgressEntry } from "@/lib/progress/types";

const HEIGHT = 300;
const PAD = { left: 46, right: 16, top: 18, bottom: 38 };
const RANGES: { value: ChartRange; label: string }[] = [
  { value: "30", label: "30 days" }, { value: "60", label: "60 days" },
  { value: "program", label: "Current program" }, { value: "all", label: "All history" },
];
const clamp = (value: number, min: number, max: number) => Math.max(min, Math.min(value, max));

type DatedEntry = { entry: ProgressEntry; day: number };
type ChartProps = { entries: ProgressEntry[]; goal: number | null; programStartDate?: string | null };

/** Range controls and navigation work locally on the snapshot already loaded. */
export function WeightChart({ entries, goal, programStartDate }: ChartProps) {
  const [preset, setPreset] = useState<ChartRange>("30");
  const [customWindow, setCustomWindow] = useState<ChartWindow | null>(null);
  const [selection, setSelection] = useState<{ id: number; pinned: boolean } | null>(null);
  const [drag, setDrag] = useState<ChartWindow | null>(null);
  const [width, setWidth] = useState(900);
  const wrapper = useRef<HTMLDivElement>(null);
  const overviewDrag = useRef<{ day: number; window: ChartWindow } | null>(null);
  const helpId = useId();
  const statusId = useId();

  // Keep the existing API/reversed submission sequence. Only the date lookup
  // used by pointer and keyboard navigation is ordered chronologically.
  const dated = useMemo(() => [...entries].reverse().flatMap((entry): DatedEntry[] => {
    const day = chartDay(entry.date);
    return day === null ? [] : [{ entry, day }];
  }), [entries]);
  const bounds = useMemo(() => chartBounds(dated.map((point) => point.day)), [dated]);
  const full = bounds ?? { from: 0, to: 0 };
  const programStart = chartDay(programStartDate);
  const window = customWindow ? clampWindow(customWindow, full) : presetWindow(preset, full, programStart);
  const visible = useMemo(() => dated.filter(({ day }) => day >= window.from && day <= window.to), [dated, window.from, window.to]);
  const lookup = useMemo(() => [...visible].sort((a, b) => a.day - b.day || a.entry.id - b.entry.id), [visible]);
  const lookupDays = useMemo(() => lookup.map((point) => point.day), [lookup]);
  const active = visible.find(({ entry }) => entry.id === selection?.id) ?? null;

  useEffect(() => {
    const element = wrapper.current;
    if (!element) return;
    const observer = new ResizeObserver(([entry]) => setWidth(Math.max(1, Math.round(entry.contentRect.width))));
    observer.observe(element);
    return () => observer.disconnect();
  }, []);

  useEffect(() => {
    if (!selection) return;
    const close = (event: KeyboardEvent) => { if (event.key === "Escape") setSelection(null); };
    globalThis.addEventListener("keydown", close);
    return () => globalThis.removeEventListener("keydown", close);
  }, [selection]);

  const plotWidth = Math.max(1, width - PAD.left - PAD.right);
  const plotBottom = HEIGHT - PAD.bottom;
  const x = (day: number) => PAD.left + (window.to === window.from ? 0.5 : (day - window.from) / (window.to - window.from)) * plotWidth;
  const fitted = visible.map(({ entry }) => entry.weight);
  if (goal !== null) fitted.push(goal);
  const yBounds = fitted.reduce((b, value) => ({ min: Math.min(b.min, value), max: Math.max(b.max, value) }), { min: fitted[0] ?? 0, max: fitted[0] ?? 0 });
  const yMin = Math.floor(yBounds.min - 4);
  const yMax = Math.ceil(yBounds.max + 4);
  const y = (value: number) => PAD.top + (1 - (value - yMin) / (yMax - yMin)) * (plotBottom - PAD.top);
  const minimumGap = lookup.reduce((gap, point, index) => index ? Math.min(gap, x(point.day) - x(lookup[index - 1].day)) : gap, Infinity);
  const showMarkers = minimumGap >= 12;
  const intervals = Math.max(1, Math.floor(plotWidth / 100));
  const tickDays = window.from === window.to ? [window.from] : Array.from({ length: intervals + 1 }, (_, i) => Math.round(window.from + i * (window.to - window.from) / intervals));
  const tooltipWidth = Math.min(224, Math.max(1, width - 16));
  const tooltipLeft = active ? clamp(x(active.day) + 12 + tooltipWidth > width - 8 ? x(active.day) - tooltipWidth - 12 : x(active.day) + 12, 8, Math.max(8, width - tooltipWidth - 8)) : 8;
  const overviewX = (day: number) => full.to === full.from ? width / 2 : clamp((day - full.from) / (full.to - full.from), 0, 1) * width;
  const allY = dated.reduce((b, { entry }) => ({ min: Math.min(b.min, entry.weight), max: Math.max(b.max, entry.weight) }), { min: dated[0]?.entry.weight ?? 0, max: dated[0]?.entry.weight ?? 0 });
  const overviewY = (value: number) => 8 + (1 - (value - allY.min) / (allY.max - allY.min || 1)) * 38;
  const canZoom = full.to > full.from;

  function chooseRange(range: ChartRange) {
    setPreset(range); setCustomWindow(null); setSelection(null); setDrag(null);
  }
  function moveWindow(next: ChartWindow) {
    setCustomWindow(clampWindow(next, full)); setSelection(null); setDrag(null);
  }
  function pointerDay(event: ReactPointerEvent<SVGSVGElement>, overview = false) {
    const rect = event.currentTarget.getBoundingClientRect();
    const px = (event.clientX - rect.left) * width / rect.width;
    return overview
      ? full.from + clamp(px / width, 0, 1) * (full.to - full.from)
      : window.from + clamp((px - PAD.left) / plotWidth, 0, 1) * (window.to - window.from);
  }
  function selectNearest(day: number, pinned: boolean) {
    const index = nearestDateIndex(lookupDays, day);
    if (index >= 0) setSelection({ id: lookup[index].entry.id, pinned });
  }

  return (
    <div className="space-y-3" style={{ fontFamily: "var(--font-inter), system-ui, sans-serif" }}>
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="flex flex-wrap gap-1.5" role="group" aria-label="Weight chart date range">
          {RANGES.map((range) => (
            <Button key={range.value} type="button" size="sm" variant={!customWindow && preset === range.value ? "default" : "outline"}
              aria-pressed={!customWindow && preset === range.value} disabled={!bounds || (range.value === "program" && programStart === null)}
              title={range.value === "program" && programStart === null ? "No program start date recorded" : undefined}
              onClick={() => chooseRange(range.value)}>{range.label}</Button>
          ))}
        </div>
        <div className="flex items-center gap-1" role="group" aria-label="Weight chart navigation">
          <Button type="button" variant="outline" size="icon" aria-label="Show earlier dates" disabled={!canZoom || window.from <= full.from}
            onClick={() => { const shift = Math.max(1, Math.round((window.to - window.from) / 4)); moveWindow({ from: window.from - shift, to: window.to - shift }); }}><ChevronLeft /></Button>
          <Button type="button" variant="outline" size="icon" aria-label="Zoom in" disabled={!canZoom || window.to - window.from <= 1}
            onClick={() => moveWindow(zoomWindow(window, full, 0.5))}><Plus /></Button>
          <Button type="button" variant="outline" size="icon" aria-label="Zoom out" disabled={!canZoom || (window.from <= full.from && window.to >= full.to)}
            onClick={() => moveWindow(zoomWindow(window, full, 2))}><Minus /></Button>
          <Button type="button" variant="outline" size="icon" aria-label="Show later dates" disabled={!canZoom || window.to >= full.to}
            onClick={() => { const shift = Math.max(1, Math.round((window.to - window.from) / 4)); moveWindow({ from: window.from + shift, to: window.to + shift }); }}><ChevronRight /></Button>
          <Button type="button" variant="outline" size="icon" aria-label="Reset chart view" disabled={!bounds} onClick={() => chooseRange("30")}><RotateCcw /></Button>
        </div>
      </div>

      <p className="text-xs text-muted-foreground" aria-live="polite">
        {bounds ? `${chartDate(window.from)} – ${chartDate(window.to)} · ${visible.length} ${visible.length === 1 ? "reading" : "readings"}` : "No weigh-ins logged yet."}
      </p>
      <div ref={wrapper} className="relative h-[300px] w-full">
        {visible.length ? (
          <svg viewBox={`0 0 ${width} ${HEIGHT}`} className="h-full w-full rounded-lg outline-none focus-visible:ring-2 focus-visible:ring-primary" role="img" tabIndex={0}
            aria-label="Daily weight against goal" aria-describedby={`${helpId} ${statusId}`} style={{ touchAction: "pan-y" }}
            onKeyDown={(event) => {
              if (!["ArrowLeft", "ArrowRight", "Home", "End", "Escape"].includes(event.key)) return;
              event.preventDefault();
              if (event.key === "Escape") { setSelection(null); return; }
              const index = lookup.findIndex(({ entry }) => entry.id === selection?.id);
              const next = event.key === "Home" ? 0 : event.key === "End" ? lookup.length - 1 : index < 0 ? 0 : clamp(index + (event.key === "ArrowRight" ? 1 : -1), 0, lookup.length - 1);
              setSelection({ id: lookup[next].entry.id, pinned: true });
            }}
            onPointerDown={(event) => {
              if (event.button !== 0) return;
              const day = pointerDay(event);
              if (event.pointerType === "touch") { selectNearest(day, true); return; }
              event.currentTarget.setPointerCapture(event.pointerId);
              setDrag({ from: day, to: day });
            }}
            onPointerMove={(event) => {
              if (drag) { setDrag({ ...drag, to: pointerDay(event) }); return; }
              if (event.pointerType !== "touch" && !selection?.pinned) selectNearest(pointerDay(event), false);
            }}
            onPointerUp={(event) => {
              if (!drag) return;
              const day = pointerDay(event);
              if (Math.abs(x(day) - x(drag.from)) >= 12) moveWindow({ from: Math.round(Math.min(drag.from, day)), to: Math.round(Math.max(drag.from, day)) });
              else { selectNearest(day, true); setDrag(null); }
              if (event.currentTarget.hasPointerCapture(event.pointerId)) event.currentTarget.releasePointerCapture(event.pointerId);
            }}
            onPointerCancel={() => setDrag(null)}
            onPointerLeave={() => { if (!drag && !selection?.pinned) setSelection(null); }}>
            {Array.from({ length: 5 }, (_, i) => {
              const value = yMin + i * (yMax - yMin) / 4;
              return <g key={i}><line x1={PAD.left} x2={width - PAD.right} y1={y(value)} y2={y(value)} className="stroke-border" /><text x={PAD.left - 8} y={y(value) + 4} textAnchor="end" fontSize={12} className="fill-muted-foreground">{Math.round(value)}</text></g>;
            })}
            {goal !== null ? <line x1={PAD.left} x2={width - PAD.right} y1={y(goal)} y2={y(goal)} className="stroke-chart-3" strokeWidth={1.5} strokeDasharray="5,4" /> : null}
            <polyline points={visible.map(({ entry, day }) => `${x(day)},${y(entry.weight)}`).join(" ")} fill="none" className="stroke-chart-1" strokeWidth={2} strokeLinejoin="round" strokeLinecap="round" />
            {showMarkers ? visible.map(({ entry, day }) => <circle key={entry.id} cx={x(day)} cy={y(entry.weight)} r={3.5} className="fill-chart-1" />) : null}
            {tickDays.map((day, index) => <text key={`${day}-${index}`} x={x(day)} y={HEIGHT - 12} fontSize={12} textAnchor={index === 0 && tickDays.length > 1 ? "start" : index === tickDays.length - 1 && tickDays.length > 1 ? "end" : "middle"} className="fill-muted-foreground">{chartDate(day, true)}</text>)}
            {active ? <g pointerEvents="none"><line x1={x(active.day)} x2={x(active.day)} y1={PAD.top} y2={plotBottom} className="stroke-muted-foreground" strokeDasharray="3,3" /><circle cx={x(active.day)} cy={y(active.entry.weight)} r={5} className="fill-flame stroke-surface" strokeWidth={2} /></g> : null}
            {drag ? <rect x={Math.min(x(drag.from), x(drag.to))} y={PAD.top} width={Math.abs(x(drag.to) - x(drag.from))} height={plotBottom - PAD.top} className="fill-primary/15 stroke-primary" pointerEvents="none" /> : null}
          </svg>
        ) : <div className="flex h-full items-center justify-center rounded-lg border border-dashed border-border"><p className="px-4 text-center text-sm text-muted-foreground">{entries.length ? "No weigh-ins in this date range." : "No weigh-ins logged yet."}</p></div>}
        {active ? (
          <div role="status" aria-live="off" className={`absolute z-10 max-h-[220px] overflow-y-auto break-words rounded-lg border border-border bg-surface p-3 text-xs shadow-panel ${selection?.pinned ? "" : "pointer-events-none"}`}
            style={{ left: tooltipLeft, top: 8, width: tooltipWidth }}>
            <div className="flex items-center justify-between gap-2"><p className="font-semibold text-foreground">{active.entry.programDayComputed > 0 ? `Day ${active.entry.programDayComputed}` : "Previous"}</p>
              {selection?.pinned ? <button type="button" className="rounded p-1 hover:bg-muted focus-visible:ring-2 focus-visible:ring-primary" aria-label="Close weight details" onClick={() => setSelection(null)}><X className="size-4" /></button> : null}</div>
            <p className="text-muted-foreground">{active.entry.date} · {active.entry.weight} lbs</p>
            {active.entry.loss > 0 ? <p className="text-muted-foreground">{active.entry.loss} lbs lost to date</p> : null}
            {active.entry.flags.length ? <p className="mt-1 text-flame">{active.entry.flags.join(", ")}</p> : null}
            {active.entry.note ? <p className="mt-1 whitespace-pre-wrap text-muted-foreground">{active.entry.note}</p> : null}
          </div>
        ) : null}
      </div>
      <p id={helpId} className="text-xs text-muted-foreground">Hover or tap for details. Use the zoom buttons, or drag across the chart on desktop to zoom. Arrow keys explore readings.</p>
      <p id={statusId} className="sr-only" aria-live={selection?.pinned ? "polite" : "off"}>{active ? `${active.entry.date}: ${active.entry.weight} pounds, ${active.entry.programDayComputed > 0 ? `day ${active.entry.programDayComputed}` : "previous"}. ${active.entry.flags.join(", ")}` : ""}</p>
      {bounds ? (
        <div className="space-y-2 rounded-lg border border-border bg-muted/30 p-3">
          <div className="flex items-center justify-between gap-2 text-xs text-muted-foreground"><span className="font-medium">Overview · all history</span><span>Drag to move the date window</span></div>
          <svg viewBox={`0 0 ${width} 56`} className="h-14 w-full cursor-ew-resize rounded bg-surface" role="img" aria-label="Overview of all recorded weights" style={{ touchAction: "none" }}
            onPointerDown={(event) => {
              if (!canZoom || event.button !== 0) return;
              event.currentTarget.setPointerCapture(event.pointerId);
              const day = pointerDay(event, true);
              let next = clampWindow(window, full);
              if (day < next.from || day > next.to) { const span = next.to - next.from; next = clampWindow({ from: Math.round(day - span / 2), to: Math.round(day + span / 2) }, full); moveWindow(next); }
              overviewDrag.current = { day, window: next };
            }}
            onPointerMove={(event) => {
              if (!overviewDrag.current) return;
              const shift = Math.round(pointerDay(event, true) - overviewDrag.current.day);
              moveWindow({ from: overviewDrag.current.window.from + shift, to: overviewDrag.current.window.to + shift });
            }}
            onPointerUp={(event) => { overviewDrag.current = null; if (event.currentTarget.hasPointerCapture(event.pointerId)) event.currentTarget.releasePointerCapture(event.pointerId); }}
            onPointerCancel={() => { overviewDrag.current = null; }}>
            <polyline points={dated.map(({ entry, day }) => `${overviewX(day)},${overviewY(entry.weight)}`).join(" ")} fill="none" className="stroke-chart-1" strokeWidth={1.5} />
            <rect x={0} y={0} width={overviewX(window.from)} height={56} className="fill-muted/70" />
            <rect x={overviewX(window.to)} y={0} width={width - overviewX(window.to)} height={56} className="fill-muted/70" />
            <rect x={overviewX(window.from)} y={1} width={Math.max(1, overviewX(window.to) - overviewX(window.from))} height={54} className="fill-primary/10 stroke-primary" strokeWidth={2} />
          </svg>
          <div className="flex justify-between text-[11px] text-muted-foreground"><span>{chartDate(full.from)}</span><span>{chartDate(full.to)}</span></div>
          <div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
            <label className="flex min-w-0 flex-col gap-1 text-xs text-muted-foreground">Start date: {chartDate(window.from)}
              <input type="range" aria-label="Chart start date" className="h-7 w-full cursor-pointer accent-primary" min={full.from} max={full.to} step={1} value={clamp(window.from, full.from, full.to)} disabled={!canZoom}
                onChange={(event) => moveWindow({ from: Math.min(Number(event.target.value), window.to), to: window.to })} /></label>
            <label className="flex min-w-0 flex-col gap-1 text-xs text-muted-foreground">End date: {chartDate(window.to)}
              <input type="range" aria-label="Chart end date" className="h-7 w-full cursor-pointer accent-primary" min={full.from} max={full.to} step={1} value={clamp(window.to, full.from, full.to)} disabled={!canZoom}
                onChange={(event) => moveWindow({ from: window.from, to: Math.max(window.from, Number(event.target.value)) })} /></label>
          </div>
        </div>
      ) : null}
    </div>
  );
}

export function WeightChartPanel(props: ChartProps) {
  return (
    <section className="rounded-xl border border-border bg-surface p-4 shadow-panel sm:p-6">
      <div className="mb-4 flex flex-wrap items-center justify-between gap-2">
        <h2 className="font-serif text-lg text-foreground">Weight vs. goal</h2>
        <div className="flex items-center gap-4 text-xs text-muted-foreground">
          <span className="flex items-center gap-1.5"><span className="size-2 rounded-full bg-primary" />Today&apos;s Weight</span>
          <span className="flex items-center gap-1.5"><span className="h-0.5 w-3 bg-flame" />Goal Weight</span>
        </div>
      </div>
      <WeightChart {...props} />
    </section>
  );
}