import type { BlueprintField } from "./types";

export type FieldValue = string | string[] | boolean | undefined;

/** Keyed by {@link BlueprintField.name}. */
export type FormState = Record<string, FieldValue>;

export function isBlank(value: FieldValue): boolean {
  if (value === undefined || value === false) return true;
  if (Array.isArray(value)) return value.length === 0;
  return typeof value === "string" && value.trim() === "";
}

function asComparable(value: FieldValue): string | string[] | null {
  if (value === undefined || value === null) return null;
  if (Array.isArray(value)) return value;
  if (typeof value === "boolean") return value ? "1" : "0";
  return value;
}

/** Seeds every input with its blueprint default so `#states` sees the same value Drupal would. */
export function seedState(fields: BlueprintField[]): FormState {
  const state: FormState = {};
  for (const field of fields) {
    if (field.widget === "checkbox-grid" || field.widget === "repeatable") {
      state[field.name] = [];
      continue;
    }
    if (field.widget === "consent" || field.widget === "checkbox") {
      state[field.name] = false;
      continue;
    }
    state[field.name] = field.default ?? "";
  }
  return state;
}

function conditionHolds(
  state: FormState,
  _field: BlueprintField,
  condition: NonNullable<BlueprintField["visibleWhen"]>[number],
): boolean {
  const value = asComparable(state[condition.field]);

  switch (condition.op) {
    case "notEmpty":
      return !isBlank(state[condition.field]);

    case "eq":
      if (value === null) return false;
      if (Array.isArray(value)) return value.includes(condition.value ?? "");
      return value === (condition.value ?? "");

    case "neq": {
      // An unanswered control never satisfies `neq`; Drupal's #states does not
      // fire on an empty element either.
      if (isBlank(state[condition.field])) return false;
      if (Array.isArray(value)) return !value.includes(condition.value ?? "");
      return value !== (condition.value ?? "");
    }

    default:
      return true;
  }
}

/** Mirrors the `#states` rules exported from custom_module.module. */
export function isFieldVisible(field: BlueprintField, state: FormState): boolean {
  if (!field.visibleWhen?.length) return true;
  return field.visibleWhen.every((condition) =>
    conditionHolds(state, field, condition),
  );
}

export function visibleFields(
  fields: BlueprintField[],
  state: FormState,
): BlueprintField[] {
  return fields.filter((field) => isFieldVisible(field, state));
}

/** Prunes values whose field is hidden, so stale answers never get submitted. */
export function pruneHidden(
  fields: BlueprintField[],
  state: FormState,
): FormState {
  const next: FormState = {};
  for (const field of fields) {
    next[field.name] = isFieldVisible(field, state)
      ? state[field.name]
      : emptyFor(field);
  }
  return next;
}

export function emptyFor(field: BlueprintField): FieldValue {
  if (field.widget === "checkbox-grid" || field.widget === "repeatable") {
    return [];
  }
  if (field.widget === "consent" || field.widget === "checkbox") return false;
  return "";
}
