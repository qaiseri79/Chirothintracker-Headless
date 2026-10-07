#!/usr/bin/env node
/**
 * Syncs docs/headless/intake-blueprint.json into the frontend so Turbopack can
 * import it (Next.js refuses modules from outside the project root).
 *
 * The source file is the only authority. This script copies it, verifies the
 * basic shape the UI depends on, and exits non-zero if anything is off — so a
 * stale or broken contract fails the build/dev loop loudly, mirroring the
 * "three coverage checks" in tools/export_intake_blueprint.php.
 */
import { copyFileSync, existsSync, mkdirSync, readFileSync, renameSync } from "node:fs";
import { dirname, resolve } from "node:path";
import { fileURLToPath } from "node:url";

const here = dirname(fileURLToPath(import.meta.url));
const source = resolve(here, "../../docs/headless/intake-blueprint.json");
const target = resolve(here, "../src/lib/intake/intake-blueprint.json");

function fail(message) {
  console.error(`[sync-blueprint] ${message}`);
  process.exit(1);
}

if (!existsSync(source)) {
  fail(`source blueprint missing: ${source}`);
}

let data;
try {
  data = readFileSync(source, "utf8");
} catch (error) {
  fail(`cannot read ${source}: ${error.message}`);
}

let parsed;
try {
  parsed = JSON.parse(data);
} catch (error) {
  fail(`source blueprint is not valid JSON: ${error.message}`);
}

const required = {
  form: (v) => typeof v === "string" && v.length > 0,
  fieldCount: (v) => Number.isInteger(v) && v > 0,
  steps: (v) => Array.isArray(v) && v.length >= 6,
};
for (const [key, check] of Object.entries(required)) {
  if (!check(parsed[key])) fail(`source blueprint is missing a valid "${key}".`);
}

const names = new Set();
for (const step of parsed.steps) {
  if (!Array.isArray(step?.fields)) fail("a step has no field list.");
  for (const field of step.fields) {
    if (typeof field.name !== "string" || field.name === "") {
      fail("a field has no name.");
    }
    if (names.has(field.name)) fail(`duplicate field name: ${field.name}.`);
    names.add(field.name);
    if (typeof field.widget !== "string" || field.widget === "") {
      fail(`field ${field.name} has no widget.`);
    }
  }
}

mkdirSync(dirname(target), { recursive: true });

if (!existsSync(target) || readFileSync(target, "utf8") !== data) {
  const staged = `${target}.tmp`;
  copyFileSync(source, staged);
  renameSync(staged, target);
  console.log(`[sync-blueprint] synced ${names.size} fields -> ${target}`);
} else {
  console.log(`[sync-blueprint] blueprint is current (${names.size} fields).`);
}