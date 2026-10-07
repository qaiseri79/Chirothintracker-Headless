import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import Module, { createRequire } from "node:module";
import { fileURLToPath } from "node:url";
import test from "node:test";

const ts = createRequire(import.meta.url)("typescript");
const filename = fileURLToPath(new URL("../src/lib/progress/summary-cards.ts", import.meta.url));
const compiled = new Module(filename);
compiled._compile(ts.transpileModule(readFileSync(filename, "utf8"), {
  compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2020 },
}).outputText, filename);
const { measurementComparisons, progressCardDetails } = compiled.exports;
const log = (id, measurements = [], weight = 200) => ({ id, measurements, weight, date: "09/25/2026", loss: 10 });
const site = (area, value) => ({ area, value });
const summary = { goalWeight: 180, startWeight: 220, goalProgress: 0.5, netInchesLost: 17.3 };

test("partial measurements retain each area's latest value and its own baseline", () => {
  const entries = [log(3, [site("Hips", 38), site("Left calf", 14)]), log(2, [site("Hips", 39)]), log(1, [site("Hips", 40), site("Right thigh", 24)])];
  const original = JSON.stringify(entries);
  const areas = measurementComparisons(entries);
  assert.deepEqual(areas.find(s => s.area === "Hips"), { area: "Hips", first: 40, current: 38, lost: 2, readings: 3 });
  assert.deepEqual(areas.find(s => s.area === "Right thigh"), { area: "Right thigh", first: 24, current: 24, lost: null, readings: 1 });
  assert.equal(areas.find(s => s.area === "Left calf").lost, null);
  assert.equal(JSON.stringify(entries), original);
});

test("a single reading does not invent an inches-loss comparison", () => {
  const result = progressCardDetails(summary, [log(1, [site("Hips", 36)])]);
  assert.equal(result.sites[0].lost, null);
  assert.deepEqual(result.topSites, []);
  assert.equal(summary.netInchesLost, 17.3);
});

test("top reductions exclude gains and zero changes and rank the largest three", () => {
  const entries = [log(2, [site("Hips", 35), site("Left thigh", 20), site("Right thigh", 19), site("Calf", 13), site("Neck", 15), site("Chest", 42)]), log(1, [site("Hips", 40), site("Left thigh", 24), site("Right thigh", 22), site("Calf", 14), site("Neck", 15), site("Chest", 41)])];
  const result = progressCardDetails(summary, entries);
  assert.deepEqual(result.topSites.map(s => [s.area, s.lost]), [["Hips", 5], ["Left thigh", 4], ["Right thigh", 3]]);
  assert.equal(result.sites.find(s => s.area === "Chest").lost, -1);
});

test("the saved starting weight is used even after a gain", () => {
  const result = progressCardDetails({ ...summary, startWeight: 190 }, [log(1, [], 192)]);
  assert.equal(result.startWeight, 190);
  assert.equal(result.currentWeight, 192);
  assert.equal(result.remaining, 12);
});

test("remaining weight cannot become negative after reaching the goal", () => {
  assert.equal(progressCardDetails(summary, [log(1, [], 170)]).remaining, 0);
});

test("missing data stays unavailable rather than becoming zero", () => {
  const result = progressCardDetails({ ...summary, goalWeight: null, startWeight: null }, []);
  assert.equal(result.remaining, null);
  assert.equal(result.startWeight, null);
  assert.equal(result.currentWeight, null);
  assert.deepEqual(result.sites, []);
});

test("small previews use at most 24 actual logs and keep the latest reading", () => {
  const entries = Array.from({ length: 100 }, (_, i) => log(100 - i, [], 200 - i));
  const result = progressCardDetails(summary, entries);
  assert.equal(result.preview.length, 24);
  assert.equal(result.preview[0].id, 77);
  assert.equal(result.preview.at(-1).id, 100);
  assert.equal(result.preview.at(-1).weight, entries[0].weight);
});

test("visual progress is bounded without overwriting the saved percentage", () => {
  const stored = { ...summary, goalProgress: 1.2 };
  assert.equal(progressCardDetails(stored, []).visualPercent, 100);
  assert.equal(stored.goalProgress, 1.2);
});