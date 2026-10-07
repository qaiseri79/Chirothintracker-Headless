/**
 * Types for tracking-weight form blueprint.
 * Mirrors intake types for consistency.
 */

export const WIDGETS = [
  "text",
  "email",
  "tel",
  "date",
  "number",
  "select",
  "textarea",
  "radio",
  "checkbox-grid",
  "repeatable",
  "static",
  "consent",
  "checkbox",
] as const;

export type Widget = (typeof WIDGETS)[number];

export const INPUT_WIDGETS: readonly Widget[] = [
  "text",
  "email",
  "tel",
  "date",
  "number",
  "select",
  "textarea",
  "radio",
  "checkbox-grid",
  "repeatable",
  "consent",
  "checkbox",
];

export interface FieldOption {
  value: string;
  label: string;
}

export type VisibleWhenOp = "eq" | "neq" | "notEmpty";

export interface VisibleWhen {
  field: string;
  op: VisibleWhenOp;
  value?: string;
}

export type SubmitTransform = "joinNewline";

export interface BlueprintField {
  name: string;
  drupalField: string;
  part: string | null;
  widget: Widget;
  label: string;
  required: boolean;
  half: boolean;
  options: FieldOption[];
  default: string | null;
  description: string;
  placeholder: string;
  drupalType: string;
  min?: number;
  max?: number;
  maxLength?: number;
  suffix?: string;
  text?: string;
  textSource?: string;
  unresolvedTokens?: string[];
  submitTransform?: SubmitTransform;
  visibleWhen?: VisibleWhen[];
}

export interface BlueprintStep {
  id: string;
  title: string;
  subtitle: string;
  fields: BlueprintField[];
}

export interface Blueprint {
  form: string;
  generated: string;
  source: string;
  fieldCount: number;
  steps: BlueprintStep[];
}