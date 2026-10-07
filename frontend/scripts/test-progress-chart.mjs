import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import Module, { createRequire } from "node:module";
import { fileURLToPath } from "node:url";
import test from "node:test";

const require = createRequire(import.meta.url);
const ts = require("typescript");
const filename = fileURLToPath(new URL("../src/lib/progress/chart.ts", import.meta.url));
const compiled = new Module(filename);
compiled._compile(ts.transpileModule(readFileSync(filename, "utf8"), {
  compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2020 },
}).outputText, filename);
const { chartDay, chartDate, chartBounds, presetWindow, clampWindow, zoomWindow, nearestDateIndex } = compiled.exports;

test("calendar dates are strict, UTC-based, and accept both API date formats", () => {
  assert.equal(chartDay("03/08/2026"), chartDay("2026-03-08"));
  assert.equal(chartDay("03/09/2026") - chartDay("03/08/2026"), 1);
  assert.equal(chartDay("02/29/2024") + 1, chartDay("03/01/2024"));
  for (const invalid of ["02/29/2025", "04/31/2026", "00/10/2026", "bad", null]) assert.equal(chartDay(invalid), null);
  assert.equal(chartDate(chartDay("2026-01-01")), "Jan 1, 2026");
});

test("the latest 30/60 days include their endpoints across month and year boundaries", () => {
  const full = { from: chartDay("2025-01-01"), to: chartDay("2026-01-10") };
  assert.deepEqual(presetWindow("30", full, null), { from: chartDay("2025-12-12"), to: full.to });
  assert.deepEqual(presetWindow("60", full, null), { from: chartDay("2025-11-12"), to: full.to });
});

test("short and single-day histories stay within available records", () => {
  assert.equal(chartBounds([]), null);
  const full = chartBounds([100, 103, 101]);
  assert.deepEqual(full, { from: 100, to: 103 });
  assert.deepEqual(presetWindow("30", full, null), full);
  assert.deepEqual(chartBounds([100]), { from: 100, to: 100 });
  assert.deepEqual(zoomWindow({ from: 100, to: 100 }, { from: 100, to: 100 }, 2), { from: 100, to: 100 });
});

test("current program uses the saved start date rather than a log's computed day", () => {
  const full = { from: 100, to: 200 };
  assert.deepEqual(presetWindow("program", full, 170), { from: 170, to: 200 });
  assert.deepEqual(presetWindow("program", full, 210), { from: 210, to: 210 });
  assert.deepEqual(presetWindow("all", full, 170), full);
});

test("panning preserves window length and stops at history boundaries", () => {
  const full = { from: 100, to: 200 };
  assert.deepEqual(clampWindow({ from: 80, to: 109 }, full), { from: 100, to: 129 });
  assert.deepEqual(clampWindow({ from: 185, to: 214 }, full), { from: 171, to: 200 });
  assert.deepEqual(clampWindow({ from: 50, to: 250 }, full), full);
});

test("zoom has a minimum span and never extends beyond the history", () => {
  const full = { from: 100, to: 200 };
  const zoomed = zoomWindow({ from: 140, to: 160 }, full, 0.5);
  assert.deepEqual(zoomed, { from: 145, to: 155 });
  assert.deepEqual(zoomWindow({ from: 100, to: 180 }, full, 2), full);
  assert.equal(zoomWindow({ from: 140, to: 141 }, full, 0.5).to - zoomWindow({ from: 140, to: 141 }, full, 0.5).from, 1);
});

test("crosshair finds the closest date, including boundaries, gaps and dense points", () => {
  assert.equal(nearestDateIndex([], 100), -1);
  assert.equal(nearestDateIndex([100, 110, 120], 95), 0);
  assert.equal(nearestDateIndex([100, 110, 120], 125), 2);
  assert.equal(nearestDateIndex([100, 110, 120], 104.5), 0);
  assert.equal(nearestDateIndex([100, 110, 120], 105.5), 1);
  const dense = Array.from({ length: 500 }, (_, i) => 100 + i);
  assert.equal(nearestDateIndex(dense, 200.45), 100);
  assert.equal(nearestDateIndex(dense, 200.55), 101);
});