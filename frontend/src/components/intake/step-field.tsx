"use client";

import { Checkbox } from "@/components/ui/checkbox";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import {
  NativeSelect,
  NativeSelectOption,
} from "@/components/ui/native-select";
import { Textarea } from "@/components/ui/textarea";
import type { BlueprintField } from "@/lib/intake/types";
import type { FieldValue, FormState } from "@/lib/intake/state";
import { inputModeFor, numericKind } from "@/lib/intake/validation";

const INPUT_CLASS =
  "h-10 w-full rounded-lg border border-input bg-canvas px-3.5 text-sm shadow-none transition-colors focus-visible:border-primary focus-visible:ring-1 focus-visible:ring-primary";

const GRID_CLASS = "sm:col-span-2";

export interface StepFieldProps {
  field: BlueprintField;
  state: FormState;
  error?: string;
  onChange: (name: string, value: FieldValue) => void;
  /**
   * Fires when the patient leaves the control, so the form can check the field
   * then rather than only on Continue.
   */
  onBlur?: (name: string) => void;
}

function RequiredMark({ field }: { field: BlueprintField }) {
  if (!field.required) return null;
  return (
    <span className="text-flame" aria-hidden="true">
      *
    </span>
  );
}

function FieldShell({
  field,
  htmlFor,
  className,
  children,
  error,
}: {
  field: BlueprintField;
  htmlFor?: string;
  className?: string;
  children: React.ReactNode;
  error?: string;
}) {
  return (
    <div className={className ?? GRID_CLASS}>
      <Label
        htmlFor={htmlFor}
        className="mb-1.5 block text-sm font-medium text-foreground"
      >
        {field.label} <RequiredMark field={field} />
      </Label>
      {children}
      {field.description ? (
        <p className="mt-1.5 text-xs text-muted-foreground">
          {field.description}
        </p>
      ) : null}
      {error ? (
        <p role="alert" className="mt-1.5 text-xs font-medium text-destructive">
          {error}
        </p>
      ) : null}
    </div>
  );
}

function StaticBlock({ field }: { field: BlueprintField }) {
  return (
    <div className={GRID_CLASS}>
      <p className="mb-1.5 text-sm font-semibold text-foreground">{field.label}</p>
      <div className="max-h-36 overflow-y-auto rounded-lg border border-border bg-canvas p-4 text-sm leading-relaxed text-foreground/70">
        {field.text}
      </div>
    </div>
  );
}

function OptionPill({
  type,
  name,
  value,
  label,
  checked,
  onChange,
}: {
  type: "radio" | "checkbox";
  name: string;
  value: string;
  label: string;
  checked: boolean;
  onChange: (checked: boolean) => void;
}) {
  const inputId = `${name}::${value}`;
  return (
    <Label
      htmlFor={inputId}
      className="flex cursor-pointer items-center gap-2 rounded-lg border border-border bg-canvas px-4 py-2.5 text-sm transition-colors has-[:checked]:border-primary has-[:checked]:bg-brand-soft has-[:checked]:text-primary"
    >
      <input
        id={inputId}
        type={type}
        name={name}
        value={value}
        checked={checked}
        onChange={(event) => onChange(event.target.checked)}
        className="size-4"
      />
      {label}
    </Label>
  );
}

export function StepField({
  field,
  state,
  error,
  onChange,
  onBlur,
}: StepFieldProps) {
  const value = state[field.name];
  const width = field.half ? "sm:col-span-1" : GRID_CLASS;
  const id = field.name;
  const blur = () => onBlur?.(field.name);

  switch (field.widget) {
    case "static":
      return <StaticBlock field={field} />;

    case "textarea":
      return (
        <FieldShell field={field} htmlFor={id} className={GRID_CLASS} error={error}>
          <Textarea
            id={id}
            name={field.name}
            rows={4}
            value={String(value ?? "")}
            placeholder={field.placeholder || undefined}
            aria-invalid={error ? true : undefined}
            onChange={(event) => onChange(field.name, event.target.value)}
            onBlur={blur}
            className={INPUT_CLASS}
          />
        </FieldShell>
      );

    case "select":
      return (
        <FieldShell field={field} htmlFor={id} className={width} error={error}>
          <NativeSelect
            id={id}
            name={field.name}
            value={String(value ?? "")}
            aria-invalid={error ? true : undefined}
            onChange={(event) => onChange(field.name, event.target.value)}
            className="w-full"
          >
            <NativeSelectOption value="">
              {field.required ? "- Select -" : "- None -"}
            </NativeSelectOption>
            {field.options.map((option) => (
              <NativeSelectOption key={option.value} value={option.value}>
                {option.label}
              </NativeSelectOption>
            ))}
          </NativeSelect>
        </FieldShell>
      );

    case "number": {
      const kind = numericKind(field) ?? "decimal";
      return (
        <FieldShell field={field} htmlFor={id} className={width} error={error}>
          <div className="flex items-center gap-2">
            {/* `type="text"`, not `type="number"`.
                A number input discards any character it cannot parse, so typing
                "1a0" leaves the box looking empty and the patient has no idea why —
                and the error message that exists to explain it can never fire,
                because by then the value has already vanished. Keeping the raw
                text means the mistake stays visible and correctable.
                `inputMode` still gets the numeric keypad on a phone, and `min`/`max`
                are dropped from the DOM because they are inert on a text input;
                those bounds are checked in `validateField`. */}
            <Input
              id={id}
              name={field.name}
              type="text"
              inputMode={inputModeFor(kind)}
              value={String(value ?? "")}
              placeholder={field.placeholder || undefined}
              aria-invalid={error ? true : undefined}
              onChange={(event) => onChange(field.name, event.target.value)}
              onBlur={blur}
              className={INPUT_CLASS}
            />
            {field.suffix ? (
              <span className="whitespace-nowrap text-sm text-muted-foreground">
                {field.suffix}
              </span>
            ) : null}
          </div>
        </FieldShell>
      );
    }

    case "radio":
      return (
        <FieldShell field={field} className={GRID_CLASS} error={error}>
          <div
            role="radiogroup"
            aria-label={field.label}
            className="flex flex-wrap gap-2.5"
          >
            {field.options.map((option) => (
              <OptionPill
                key={option.value}
                type="radio"
                name={field.name}
                value={option.value}
                label={option.label}
                checked={String(value ?? "") === option.value}
                onChange={() => onChange(field.name, option.value)}
              />
            ))}
          </div>
        </FieldShell>
      );

    case "checkbox-grid": {
      const selected = Array.isArray(value) ? value.map(String) : [];
      return (
        <FieldShell field={field} className={GRID_CLASS} error={error}>
          <div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
            {field.options.map((option) => (
              <OptionPill
                key={option.value}
                type="checkbox"
                name={field.name}
                value={option.value}
                label={option.label}
                checked={selected.includes(option.value)}
                onChange={(checked) =>
                  onChange(
                    field.name,
                    checked
                      ? [...selected, option.value]
                      : selected.filter((entry) => entry !== option.value),
                  )
                }
              />
            ))}
          </div>
        </FieldShell>
      );
    }

    case "consent":
      return (
        <div className={GRID_CLASS}>
          <Label
            htmlFor={id}
            className="flex cursor-pointer items-start gap-3 rounded-lg border border-border bg-canvas p-4 text-sm text-foreground"
          >
            <Checkbox
              id={id}
              name={field.name}
              checked={value === true}
              onCheckedChange={(checked) => onChange(field.name, checked === true)}
              className="mt-0.5"
            />
            <span>
              {field.label} <RequiredMark field={field} />
            </span>
          </Label>
          {field.description ? (
            <p className="mt-1.5 text-xs text-muted-foreground">
              {field.description}
            </p>
          ) : null}
          {error ? (
            <p role="alert" className="mt-1.5 text-xs font-medium text-destructive">
              {error}
            </p>
          ) : null}
        </div>
      );

    case "checkbox":
      return (
        <div className={GRID_CLASS}>
          <Label
            htmlFor={id}
            className="flex cursor-pointer items-start gap-3 rounded-lg border border-border bg-canvas p-4 text-sm text-foreground"
          >
            <Checkbox
              id={id}
              name={field.name}
              checked={value === true}
              onCheckedChange={(checked) => onChange(field.name, checked === true)}
              className="mt-0.5"
            />
            <span>
              {field.label} <RequiredMark field={field} />
            </span>
          </Label>
          {error ? (
            <p role="alert" className="mt-1.5 text-xs font-medium text-destructive">
              {error}
            </p>
          ) : null}
        </div>
      );

    case "repeatable":
      return (
        <RepeatableField field={field} value={value} onChange={onChange} onBlur={onBlur} />
      );

    default:
      // text | email | tel | date
      return (
        <FieldShell field={field} htmlFor={id} className={width} error={error}>
          <Input
            id={id}
            name={field.name}
            type={field.widget}
            inputMode={field.drupalType === "telephone" ? "tel" : undefined}
            maxLength={field.maxLength}
            value={String(value ?? "")}
            placeholder={field.placeholder || undefined}
            aria-invalid={error ? true : undefined}
            onChange={(event) => onChange(field.name, event.target.value)}
            onBlur={blur}
            className={INPUT_CLASS}
          />
        </FieldShell>
      );
  }
}

function RepeatableField({
  field,
  value,
  onChange,
  onBlur,
}: {
  field: BlueprintField;
  value: FieldValue;
  onChange: (name: string, value: FieldValue) => void;
  onBlur?: (name: string) => void;
}) {
  const rows = Array.isArray(value) && value.length ? value : [""];

  return (
    <div
      className="rounded-lg border border-flame/30 bg-flame-soft/60 p-4"
      data-repeatable={field.name}
    >
      <p className="mb-3 text-sm font-medium text-foreground">{field.label}</p>
      <div className="space-y-2">
        {rows.map((row, index) => (
          <div key={index} className="flex items-center gap-2">
            <Input
              name={`${field.name}[${index}]`}
              type="text"
              value={row}
              placeholder={field.placeholder || undefined}
              onChange={(event) => {
                const next = [...rows];
                next[index] = event.target.value;
                onChange(field.name, next);
              }}
              onBlur={() => onBlur?.(field.name)}
              className="h-10 flex-1 rounded-lg border border-input bg-surface px-3.5 text-sm"
            />
            {rows.length > 1 ? (
              <button
                type="button"
                onClick={() =>
                  onChange(
                    field.name,
                    rows.filter((_, position) => position !== index),
                  )
                }
                className="shrink-0 rounded-lg border border-border bg-surface px-3 py-2 text-xs font-medium text-muted-foreground transition-colors hover:bg-canvas"
              >
                Remove
              </button>
            ) : null}
          </div>
        ))}
      </div>
      <button
        type="button"
        onClick={() => onChange(field.name, [...rows, ""])}
        className="mt-3 rounded-lg border border-dashed border-flame px-3.5 py-1.5 text-sm font-medium text-flame transition-colors hover:bg-surface"
      >
        + Add another
      </button>
    </div>
  );
}
