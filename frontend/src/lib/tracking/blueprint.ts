import blueprintJson from "./tracking-blueprint.json";
import {
  INPUT_WIDGETS,
  type Blueprint,
  type BlueprintField,
  type BlueprintStep,
  type Widget,
} from "./types";

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

export const inputFields: BlueprintField[] = allFields.filter((f) =>
  isInputWidget(f.widget),
);

export function initialValue(field: BlueprintField): string {
  return field.default ?? "";
}