import type { BlueprintField } from "./types";

/**
 * Per-field type rules for the intake form.
 *
 * These exist because `Number()` is far more permissive than the fields are. It
 * accepts `"1e5"` as 100000, `"0x10"` as 16, `""` as 0 and `" 12 "` as 12, and it
 * happily returns a finite number for `"1.5"` in a field Drupal stores as an
 * integer — where the value is then either rejected server-side or silently
 * truncated. Typing letters was reaching the payload at all.
 *
 * The rules are derived from the blueprint rather than listed by field name, so a
 * field added to the form later is covered without editing this file.
 */

/**
 * Digits, optional sign, nothing else. No exponent, no radix prefix, no decimal
 * point, no thousands separators, no internal whitespace.
 */
const INTEGER = /^[+-]?\d+$/;

/**
 * At most one decimal point, with at least one digit on one side of it. So `150`,
 * `150.5` and `.5` pass; `150.`, `1.2.3`, `1,000` and `1e5` do not.
 */
const DECIMAL = /^[+-]?(?:\d+(?:\.\d+)?|\.\d+)$/;

/** Any Unicode letter, so `Nguyễn` and `Müller` both count as containing a letter. */
const LETTER = /\p{L}/u;

export type NumericKind = "integer" | "decimal";

/**
 * Whether a field holds a number, and whether it must be a whole one.
 *
 * Driven off the widget and the Drupal storage type together, because either can
 * imply it: the blueprint renders some numbers as `number` widgets, and a field
 * could carry an `integer` type without one.
 */
export function numericKind(field: BlueprintField): NumericKind | null {
  const numeric =
    field.widget === "number" ||
    field.drupalType === "integer" ||
    field.drupalType === "decimal" ||
    field.drupalType === "float";
  if (!numeric) return null;
  return field.drupalType === "integer" ? "integer" : "decimal";
}

/** Strict plain-decimal test, reused when coercing a value for submission. */
export function isPlainNumber(text: string): boolean {
  return DECIMAL.test(text);
}

export function isValidNumber(text: string, kind: NumericKind): boolean {
  return kind === "integer" ? INTEGER.test(text) : DECIMAL.test(text);
}

/**
 * Whether an answer made only of digits and punctuation should be rejected.
 *
 * Narrower than it looks, because plenty of legitimately numeric answers exist:
 *
 * - `part !== null` covers address components and the multi-part stress fields,
 *   where digits are the answer as often as not: postal code `43215`, a rural
 *   street address that is just a number, `3 kids`.
 * - `textarea` and `repeatable` are prose by nature. `Lost 40 lbs in 3 months`
 *   and `Metformin 500mg` are normal answers to those.
 *
 * What is left is single-line string inputs — first and last name, occupation,
 * emergency contact, the typed consent signature, and the medication names. An
 * answer with no letter anywhere in it is a typo in all of those, not a value.
 */
export function requiresLetter(field: BlueprintField): boolean {
  return field.widget === "text" && field.part === null;
}

export function containsLetter(text: string): boolean {
  return LETTER.test(text);
}

/**
 * Mobile keypad hint for a numeric field. A whole-number field should not offer
 * a decimal point, and an integer field's `type` is `text` so that what the
 * patient typed survives to be explained (see `step-field.tsx`).
 */
export function inputModeFor(kind: NumericKind): "numeric" | "decimal" {
  return kind === "integer" ? "numeric" : "decimal";
}

/**
 * Rejects a value whose JSON type does not match the control it arrived for.
 *
 * The submit endpoint takes `request.json()`, so every field arrives as whatever
 * a caller sent. Without this gate the scalar paths called `String(value)` on it,
 * which turns `{"a":1}` into the literal text `"[object Object]"` — and because
 * that text contains letters it passed the name checks and was written to the
 * patient's record. A consent widget posted as a string, or a repeatable posted
 * as a bare string, were accepted the same way.
 *
 * The browser form cannot produce any of these shapes, so this is about the API
 * contract: a body that does not match the form's own types is a bad request, and
 * is reported as one rather than quietly coerced into something plausible.
 *
 * Numbers are allowed only where a number is expected, and only when finite. JSON
 * has no integer/decimal distinction, so `150.5` and `2` are both fine there; the
 * integer-vs-decimal rule belongs to the text form and is checked by
 * `isValidNumber`.
 */
export function shapeMessage(field: BlueprintField, raw: unknown): string | null {
  // Absent values are the required check's business, not a type error.
  if (raw === undefined || raw === null) return null;

  switch (field.widget) {
    case "consent":
    case "checkbox":
      return typeof raw === "boolean"
        ? null
        : `${field.label} must be sent as true or false.`;

    case "checkbox-grid":
    case "repeatable":
      return Array.isArray(raw) && raw.every((entry) => typeof entry === "string")
        ? null
        : `${field.label} must be sent as a list of text answers.`;

    default:
      if (typeof raw === "string") return null;
      if (typeof raw === "number" && numericKind(field)) {
        return Number.isFinite(raw) ? null : `${field.label} must be a number.`;
      }
      return `${field.label} must be sent as text.`;
  }
}