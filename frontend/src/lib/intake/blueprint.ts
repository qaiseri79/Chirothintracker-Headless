import blueprintJson from "./intake-blueprint.json";
import {
  INPUT_WIDGETS,
  type Blueprint,
  type BlueprintField,
  type BlueprintStep,
  type Widget,
} from "./types";

/**
 * The generated contract. Imported directly from docs/headless so the UI can
 * never drift from `tools/export_intake_blueprint.php` output — there is no
 * copy step to forget.
 */
export const blueprint = blueprintJson as unknown as Blueprint;

export const steps: BlueprintStep[] = blueprint.steps;

export const allFields: BlueprintField[] = steps.flatMap((s) => s.fields);

export function fieldByName(name: string): BlueprintField | undefined {
  return allFields.find((f) => f.name === name);
}

export function isInputWidget(widget: Widget): boolean {
  return INPUT_WIDGETS.includes(widget);
}

export const staticFields: BlueprintField[] = allFields.filter(
  (f) => f.widget === "static",
);

/** Fields whose value is submitted, in blueprint order. */
export const inputFields: BlueprintField[] = allFields.filter((f) =>
  isInputWidget(f.widget),
);

export function initialValue(field: BlueprintField): string {
  return field.default ?? "";
}
