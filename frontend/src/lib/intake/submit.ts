import type { BlueprintField } from "./types";
import { isBlank, isFieldVisible, type FormState } from "./state";
import {
  containsLetter,
  isPlainNumber,
  isValidNumber,
  numericKind,
  requiresLetter,
  shapeMessage,
} from "./validation";

/**
 * Body sent to Drupal's submit endpoint. Keys are Drupal field names.
 *
 * `clinic` is deliberately absent: the clinic is resolved server-side from the
 * invite token and nothing in this payload may influence it. `field_clinic`'s
 * EPP default (`[current-page:query:clinic_id]`) is the injection this whole
 * flow exists to close, so the mapper is an allowlist built from the blueprint
 * and never copies unknown keys out of the request.
 */
export interface IntakeSubmission {
  token: string;
  fields: Record<string, unknown>;
}

/**
 * Drupal `datetime` fields on this bundle are date-only (datetime_type: date),
 * stored as `Y-m-d`. The UI only ever collects a calendar day, so the value is
 * sent as-collected; appending a time would fail Drupal's validator.
 */
function toDateTime(value: string, drupalType: string): string {
  if (drupalType === "datetime" && /^\d{4}-\d{2}-\d{2}$/.test(value)) {
    return value;
  }
  return value;
}

/**
 * Reads a numeric answer, or `undefined` if it is not a plain number.
 *
 * Strict on purpose: `Number()` would take `"1e5"` as 100000 and `"0x10"` as 16,
 * so an exponent or a hex literal typed into a weight field would otherwise be
 * submitted as a real measurement. Rejecting here drops the field, and the API
 * route's own server-side check still reports it back to the patient.
 */
function toNumber(value: string): number | undefined {
  const trimmed = value.trim();
  if (trimmed === "") return undefined;
  if (!isPlainNumber(trimmed)) return undefined;
  const parsed = Number(trimmed);
  return Number.isFinite(parsed) ? parsed : undefined;
}

function allowedValues(field: BlueprintField): Set<string> {
  return new Set(field.options.map((option) => option.value));
}

function coerceScalar(field: BlueprintField, raw: FormState[string]): unknown {
  switch (field.widget) {
    case "number":
      return toNumber(String(raw ?? ""));

    case "date":
      return raw ? toDateTime(String(raw), field.drupalType) : "";

    case "select":
    case "radio": {
      const value = String(raw ?? "");
      return allowedValues(field).has(value) ? value : "";
    }

    case "consent":
    case "checkbox": {
      const checked = raw === true;
      // Mirror the live form: only fields that default to "0" store a 0.
      if (checked) return 1;
      return field.default === "0" ? 0 : undefined;
    }

    case "checkbox-grid": {
      const values = Array.isArray(raw) ? raw : [];
      const allowed = allowedValues(field);
      return values.filter((value): value is string =>
        allowed.has(String(value)),
      );
    }

    case "repeatable": {
      const values = Array.isArray(raw) ? raw : [];
      return values.map((value) => String(value).trim()).filter(Boolean);
    }

    default:
      return String(raw ?? "").trim();
  }
}

function isEmptyResult(field: BlueprintField, value: unknown): boolean {
  if (value === undefined || value === "") return true;
  if (Array.isArray(value)) return value.length === 0;
  return false;
}

function groupByDrupalField(fields: BlueprintField[]): Map<string, BlueprintField[]> {
  const groups = new Map<string, BlueprintField[]>();
  for (const field of fields) {
    const bucket = groups.get(field.drupalField);
    if (bucket) bucket.push(field);
    else groups.set(field.drupalField, [field]);
  }
  return groups;
}

/**
 * Turns form state into the Drupal field payload.
 *
 * Only visible fields contribute, and every value is coerced against the
 * blueprint, so a hand-crafted request body cannot introduce a field, an option
 * value, or a clinic that the design does not declare.
 */
export function buildSubmission(
  fields: BlueprintField[],
  state: FormState,
  token: string,
): IntakeSubmission {
  const visible = fields.filter((field) => isFieldVisible(field, state));
  const payload: Record<string, unknown> = {};

  for (const [, group] of groupByDrupalField(visible)) {
    const lead = group[0];

    // Address: sub-components collapse into one nested value.
    if (group.some((field) => field.part !== null) && lead.drupalType === "address") {
      const address: Record<string, string> = {};
      for (const field of group) {
        if (!field.part) continue;
        const value = String(state[field.name] ?? "").trim();
        if (value) address[field.part] = value;
      }
      if (Object.keys(address).length) payload[lead.drupalField] = address;
      continue;
    }

    // Multi-part string field: join with newlines, as `submitTransform` says.
    if (group.some((field) => field.submitTransform === "joinNewline")) {
      const lines: string[] = [];
      for (const field of group) {
        const raw = state[field.name];
        if (Array.isArray(raw)) lines.push(...raw.map((v) => String(v).trim()));
        else if (raw !== undefined && raw !== false) {
          lines.push(String(raw).trim());
        }
      }
      const joined = lines.filter(Boolean).join("\n");
      if (joined) payload[lead.drupalField] = joined;
      continue;
    }

    const value = coerceScalar(lead, state[lead.name]);
    if (!isEmptyResult(lead, value)) payload[lead.drupalField] = value;
  }

  return { token, fields: payload };
}

export interface FieldIssue {
  field: string;
  message: string;
}

const EMAIL = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
const PHONE = /^[+]?[\d\s().\-/]{7,30}$/;
const DATE = /^\d{4}-\d{2}-\d{2}$/;

/**
 * Checks one field and returns its first problem, or `null`.
 *
 * First-problem-wins rather than one-issue-per-rule: the form shows a single
 * message per field, so reporting that a name is too long *and* has no letter in
 * it would either stack two red lines or silently drop one of them.
 *
 * Rules mirror the Drupal bundle constraints (required, integer/decimal bounds,
 * string max_length), plus the format checks Drupal enforces at save (email,
 * telephone, allowed list values, real calendar dates) and the strict typing
 * described in `validation.ts`.
 */
export function validateField(
  field: BlueprintField,
  state: FormState,
): FieldIssue | null {
  const fail = (message: string): FieldIssue => ({ field: field.name, message });

  const value = state[field.name];

  // Shape before content: a value of the wrong JSON type cannot be meaningfully
  // checked against required/format rules, and reading it as one is what let an
  // object reach Drupal as the string "[object Object]".
  const shape = shapeMessage(field, value);
  if (shape) return fail(shape);

  if (isBlank(value)) {
    return field.required ? fail(`${field.label} is required.`) : null;
  }

  const text = String(Array.isArray(value) ? value.join(" ") : value).trim();

  if (field.widget === "email" && !EMAIL.test(text)) {
    return fail("Enter a valid email address.");
  }
  if (field.drupalType === "telephone" && !PHONE.test(text)) {
    return fail("Enter a valid phone number (e.g. 614-555-0100).");
  }

  const kind = numericKind(field);
  if (kind) {
    if (!isValidNumber(text, kind)) {
      return kind === "integer"
        ? fail(`${field.label} must be a whole number.`)
        : fail(`${field.label} must be a number, with no letters or symbols.`);
    }
    const num = Number(text);
    if (field.min !== undefined && num < field.min) {
      return fail(`${field.label} must be at least ${field.min}.`);
    }
    if (field.max !== undefined && num > field.max) {
      return fail(`${field.label} must be ${field.max} or less.`);
    }
  } else if (requiresLetter(field) && !containsLetter(text)) {
    return fail(`${field.label} must include letters — numbers alone are not a valid answer.`);
  }

  if (
    field.maxLength !== undefined &&
    !Array.isArray(value) &&
    text.length > field.maxLength
  ) {
    return fail(`${field.label} must be ${field.maxLength} characters or fewer.`);
  }

  if (field.widget === "date" && (!DATE.test(text) || !Number.isFinite(Date.parse(text)))) {
    return fail("Enter a valid date.");
  }

  if (field.options.length && field.drupalType !== "boolean") {
    const allowed = new Set(field.options.map((option) => option.value));
    const selected = Array.isArray(value) ? value : [String(value)];
    if (selected.some((entry) => !allowed.has(entry))) {
      return fail("Select a valid option.");
    }
  }

  return null;
}

/**
 * Required/format checks for the fields that are currently on screen.
 *
 * The API route re-runs the same rules server-side, so this is UX only — it stops
 * the patient reaching Drupal to be told what could have been caught here, and it
 * is what the field-level blur check calls into.
 */
export function validateStep(
  fields: BlueprintField[],
  state: FormState,
): FieldIssue[] {
  const issues: FieldIssue[] = [];

  for (const field of fields) {
    if (!isFieldVisible(field, state)) continue;
    const issue = validateField(field, state);
    if (issue) issues.push(issue);
  }

  return issues;
}

export function validateAll(
  fields: BlueprintField[],
  state: FormState,
): FieldIssue[] {
  return validateStep(fields, state);
}
