"use client";

import { useCallback, useMemo, useRef, useState } from "react";
import Link from "next/link";
import { Button } from "@/components/ui/button";
import { StepField } from "@/components/intake/step-field";
import { CheckCircle2 } from "lucide-react";
import { inputFields, steps } from "@/lib/intake/blueprint";
import {
  isFieldVisible,
  pruneHidden,
  seedState,
  type FieldValue,
  type FormState,
} from "@/lib/intake/state";
import { validateField, validateStep } from "@/lib/intake/submit";
import type { BlueprintField } from "@/lib/intake/types";

type Status = "editing" | "submitting" | "done" | "failed";

export interface IntakeFormProps {
  token: string;
  brand: string;
  /** Static blocks with legal text already resolved server-side. */
  legal: Record<string, string>;
}

function withLegalText(fields: BlueprintField[], legal: Record<string, string>) {
  return fields.map((field) =>
    field.widget === "static" && legal[field.name] !== undefined
      ? { ...field, text: legal[field.name] }
      : field,
  );
}

export function IntakeForm({ token, brand, legal }: IntakeFormProps) {
  const [stepIndex, setStepIndex] = useState(0);
  const [state, setState] = useState<FormState>(() => seedState(inputFields));
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [status, setStatus] = useState<Status>("editing");
  const [failure, setFailure] = useState<string | null>(null);

  const stepsWithLegal = useMemo(
    () => steps.map((step) => ({ ...step, fields: withLegalText(step.fields, legal) })),
    [legal],
  );

  const step = stepsWithLegal[stepIndex];
  const isLast = stepIndex === stepsWithLegal.length - 1;

  const fieldsByName = useMemo(
    () => new Map(inputFields.map((field) => [field.name, field])),
    [],
  );

  // Mirrors of state that the change and blur handlers need to read *synchronously*.
  //
  // `onChange` has to validate against the value that will be on screen after the
  // edit, but the next state is only known inside the `setState` updater — and
  // React may run an updater more than once, so computing the message in there
  // would be wasteful at best. These refs let the handlers resolve the next state
  // once, outside React, and validate against exactly that.
  const stateRef = useRef(state);
  const touchedRef = useRef<Set<string>>(new Set());

  // Writes the field's current verdict into `errors`, or clears its entry.
  const applyIssue = useCallback((name: string, issue: { message: string } | null) => {
    setErrors((current) => {
      const next = { ...current };
      if (issue) next[name] = issue.message;
      else delete next[name];
      return next;
    });
  }, []);

  const onBlur = useCallback(
    (name: string) => {
      // A field is only checked once the patient has left it. Flagging a typo as
      // they are still mid-word — "H" in a height field — is noise, not help.
      touchedRef.current.add(name);
      const field = fieldsByName.get(name);
      if (!field) return;
      applyIssue(name, validateField(field, stateRef.current));
    },
    [applyIssue, fieldsByName],
  );

  const onChange = useCallback(
    (name: string, value: FieldValue) => {
      const nextState = pruneHidden(inputFields, {
        ...stateRef.current,
        [name]: value,
      });
      stateRef.current = nextState;
      setState(nextState);

      if (touchedRef.current.has(name)) {
        // Past the first blur, keep checking as they type: the message clears the
        // moment it is fixed and comes back if it is not. Before that, clear any
        // error from a previous visit to this step so stale red text disappears.
        const field = fieldsByName.get(name);
        applyIssue(name, field ? validateField(field, nextState) : null);
      } else {
        setErrors((current) => {
          if (!current[name]) return current;
          const next = { ...current };
          delete next[name];
          return next;
        });
      }
      setFailure(null);
    },
    [applyIssue, fieldsByName],
  );

  const visibleOnStep = useMemo(
    () => step.fields.filter((field) => isFieldVisible(field, state)),
    [step, state],
  );

  async function handleNext() {
    const issues = validateStep(step.fields, state);
    if (issues.length) {
      setErrors(Object.fromEntries(issues.map((issue) => [issue.field, issue.message])));
      return;
    }
    setErrors({});
    if (!isLast) {
      setStepIndex((index) => index + 1);
      window.scrollTo({ top: 0, behavior: "smooth" });
      return;
    }
    await handleSubmit();
  }

  async function handleSubmit() {
    setStatus("submitting");
    setFailure(null);
    try {
      const response = await fetch(`/api/intake/${encodeURIComponent(token)}`, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ fields: state }),
      });

      if (response.status === 410 || response.status === 404) {
        setStatus("failed");
        setFailure("This intake link is no longer valid. Please ask your clinic for a new one.");
        return;
      }
      if (response.status === 429) {
        // Its own terminal state, not the generic failure banner: the answer is
        // not "try again", it is "contact your clinic", and the server's wording
        // says that. Left as `failed` so the Submit button stays disabled —
        // retrying into a 429 would burn nothing but would also achieve nothing.
        const body = (await response.json().catch(() => null)) as {
          message?: string;
        } | null;
        setStatus("failed");
        setFailure(
          body?.message ??
            "You have reached the limit of 3 intake submissions from this device in the past hour. Please contact your clinic if you need to submit again.",
        );
        return;
      }
      if (!response.ok) {
        const body = (await response.json().catch(() => null)) as {
          issues?: { field: string; message: string }[];
        } | null;
        if (body?.issues?.length) {
          // Drupal reports paths like "field_bp_diagnosis_year.0.value";
          // reduce them to the input that owns the value. Issues that still
          // don't match a step input become a top-of-form banner so nothing a
          // patient submits is ever silently ignored.
          const inline: Record<string, string> = {};
          const banner: string[] = [];
          for (const issue of body.issues) {
            const normalized = issue.field.split(".")[0];
            const ownedByStep = stepsWithLegal.some((entry) =>
              entry.fields.some((field) => field.name === normalized),
            );
            if (ownedByStep) inline[normalized] = issue.message;
            else banner.push(issue.message);
          }
          setErrors(inline);
          const firstErrored = Object.keys(inline)[0];
          if (firstErrored) {
            const owning = stepsWithLegal.findIndex((entry) =>
              entry.fields.some((field) => field.name === firstErrored),
            );
            if (owning !== -1 && owning !== stepIndex) {
              setStepIndex(owning);
              window.scrollTo({ top: 0, behavior: "smooth" });
            }
          }
          if (banner.length) {
            setFailure(
              banner.length === 1
                ? banner[0]
                : banner.join(" "),
            );
          }
          setStatus("editing");
          return;
        }
        setStatus("failed");
        setFailure("Something went wrong submitting your intake. Please try again.");
        return;
      }
      setStatus("done");
    } catch {
      setStatus("failed");
      setFailure("We could not reach the server. Please check your connection and try again.");
    }
  }

  if (status === "done") {
    return <SuccessPanel brand={brand} />;
  }

  return (
    <div className="flex min-h-full flex-col md:flex-row">
      <aside className="hidden w-72 shrink-0 border-r border-border bg-surface px-8 py-10 md:block">
        <div className="mb-10 flex items-center gap-2">
          <svg
            className="size-6 text-primary"
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            strokeWidth="2"
            aria-hidden="true"
          >
            <path d="M22 12h-4l-3 9L9 3l-3 9H2" />
          </svg>
          <div>
            <p className="font-serif text-lg leading-none text-foreground">ChiroThin</p>
            <p className="text-[10px] tracking-wide text-muted-foreground">Patient Intake</p>
          </div>
        </div>
        <StepperList current={stepIndex} steps={stepsWithLegal} />
      </aside>

      <header className="sticky top-0 z-20 border-b border-border bg-surface/95 px-5 py-4 backdrop-blur md:hidden">
        <div className="mb-2 flex items-center justify-between">
          <span className="font-serif text-base text-foreground">{step.title}</span>
          <span className="text-xs font-medium text-muted-foreground">
            {stepIndex + 1} / {stepsWithLegal.length}
          </span>
        </div>
        <div className="h-1.5 w-full overflow-hidden rounded-full bg-border">
          <div
            className="h-full rounded-full bg-primary transition-all duration-300"
            style={{
              width: `${((stepIndex + 1) / stepsWithLegal.length) * 100}%`,
            }}
          />
        </div>
      </header>

      <main className="flex-1 px-5 py-8 sm:px-10 sm:py-12">
        <div className="mx-auto max-w-2xl">
          <div className="mb-8 hidden items-center justify-between md:flex">
            <div>
              <h1 className="font-serif text-2xl text-foreground">{step.title}</h1>
              <p className="mt-1 text-sm text-muted-foreground">{step.subtitle}</p>
            </div>
            <span className="text-sm font-medium text-muted-foreground">
              Step {stepIndex + 1} of {stepsWithLegal.length}
            </span>
          </div>

          <div className="rounded-xl border border-border bg-surface p-6 shadow-panel sm:p-8">
            {failure && status !== "submitting" ? (
              <p role="alert" className="mb-6 rounded-lg border border-destructive/30 bg-destructive/10 p-4 text-sm text-destructive">
                {failure}
              </p>
            ) : null}

            <div className="grid grid-cols-1 gap-5 sm:grid-cols-2">
              {visibleOnStep.map((field) => (
                <StepField
                  key={field.name}
                  field={field}
                  state={state}
                  error={errors[field.name]}
                  onChange={onChange}
                  onBlur={onBlur}
                />
              ))}
            </div>

            <div className="mt-8 flex items-center justify-between border-t border-border pt-6">
              <Button
                type="button"
                variant="ghost"
                className={stepIndex === 0 ? "invisible" : undefined}
                onClick={() => {
                  setErrors({});
                  setFailure(null);
                  setStepIndex((index) => Math.max(0, index - 1));
                  window.scrollTo({ top: 0, behavior: "smooth" });
                }}
              >
                Back
              </Button>
              <Button
                type="button"
                className="px-6 font-semibold"
                disabled={status === "submitting"}
                onClick={handleNext}
              >
                {isLast ? "Submit" : "Continue"}
              </Button>
            </div>
          </div>

          <p className="mt-6 text-center text-xs text-muted-foreground">
            {brand} · Step {stepIndex + 1} of {stepsWithLegal.length}
          </p>
        </div>
      </main>
    </div>
  );
}

function StepperList({
  current,
  steps: list,
}: {
  current: number;
  steps: { title: string }[];
}) {
  return (
    <ol className="space-y-0">
      {list.map((entry, index) => {
        const done = index < current;
        const active = index === current;
        const last = index === list.length - 1;
        return (
          <li key={entry.title} className="relative flex items-start gap-3">
            {!last ? (
              <span
                className={`absolute left-[15px] top-8 h-full w-px ${done ? "bg-primary" : "bg-border"}`}
                aria-hidden="true"
              />
            ) : null}
            <span
              className={`relative z-10 flex size-8 shrink-0 items-center justify-center rounded-full text-xs font-semibold ${
                done
                  ? "bg-primary text-primary-foreground"
                  : active
                    ? "border-2 border-primary bg-surface text-primary"
                    : "border border-border bg-surface text-muted-foreground"
              }`}
              aria-current={active ? "step" : undefined}
            >
              {done ? "✓" : index + 1}
            </span>
            <p
              className={`pt-1.5 text-sm font-medium ${active || done ? "text-foreground" : "text-muted-foreground"}`}
            >
              {entry.title}
            </p>
          </li>
        );
      })}
    </ol>
  );
}

function SuccessPanel({ brand }: { brand: string }) {
  return (
    <main className="flex min-h-full items-center justify-center px-5 py-12">
      <div className="w-full max-w-md rounded-xl border border-border bg-surface p-8 text-center shadow-panel">
        <div className="mb-4 flex size-14 items-center justify-center rounded-full bg-positive-soft text-positive">
          <CheckCircle2 className="size-7" aria-hidden="true" />
        </div>
        <h1 className="font-serif text-2xl text-foreground">Intake received</h1>
        <p className="mt-2 text-sm text-muted-foreground">
          Thank you — your {brand} clinician will review your intake and reach out
          to confirm your program start date.
        </p>
        <div className="mt-6">
          <Button
            type="button"
            variant="link"
            className="text-sm font-medium"
            asChild
          >
            <Link href="/">Back to home</Link>
          </Button>
        </div>
      </div>
    </main>
  );
}
