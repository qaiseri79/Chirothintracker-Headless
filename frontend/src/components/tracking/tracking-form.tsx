"use client";

import { useCallback, useLayoutEffect, useMemo, useRef, useState } from "react";
import { useRouter } from "next/navigation";
import { Button } from "@/components/ui/button";
import { StepField } from "@/components/intake/step-field";
import { CheckCircle2, ChevronLeft, ChevronRight } from "lucide-react";
import { inputFields, steps } from "@/lib/tracking/blueprint";
import {
  isFieldVisible,
  pruneHidden,
  seedState,
  type FieldValue,
  type FormState,
} from "@/lib/intake/state";
import { validateStep } from "@/lib/intake/submit";

type Status = "editing" | "submitting" | "done" | "failed";

/**
 * Progress-log form, used both to create a log and to edit an existing one.
 *
 * ## Who hosts it
 *
 * Two hosts, and the difference decides which props you may pass:
 *
 * - The **page** (`/log-progress`, `/log-progress/edit/[id]`) is a server
 *   component. A function prop cannot cross the server/client boundary: React
 *   rejects it with "Event handlers cannot be passed to Client Component props",
 *   which 500s the whole route. So the page passes only data, and completion
 *   falls back to `router.push("/dashboard")` below.
 * - The **dashboard dialogs** (`create-entry-dialog`, `edit-entry-dialog`)
 *   are client components, so they *may* pass `onDone` — that is what
 *   lets the dialog close itself and refresh the list behind it instead of
 *   navigating away.
 *
 * `onDone` is therefore optional, and the page route is what keeps it that way.
 * Never add a callback prop that a server page is expected to pass.
 */
export function TrackingForm({
  editMessageId,
  initialValues,
  onDone,
  onSaved,
  submitUrl,
  embedded = false,
}: {
  editMessageId?: string;
  initialValues?: Record<string, FieldValue>;
  /** Called after a successful save instead of navigating to the dashboard. */
  onDone?: () => void;
  /** Client dialog can submit for a selected patient through a scoped endpoint. */
  submitUrl?: string;
  /** Called immediately after a confirmed save. */
  onSaved?: () => void;
  /**
   * Tightens the page chrome for a container that already provides its own
   * padding and scroll — the dashboard dialog. The standalone route leaves
   * this alone.
   */
  embedded?: boolean;
}) {
  const router = useRouter();
  const formRef = useRef<HTMLDivElement>(null);
  function scrollToTop() {
    if (!embedded) window.scrollTo({ top: 0, behavior: "smooth" });
  }
  const [stepIndex, setStepIndex] = useState(0);
  useLayoutEffect(() => {
    // Reset after the new step is laid out, before it is painted. Scrolling
    // before setStepIndex commits can leave the new fields halfway down.
    if (embedded) formRef.current?.closest('[data-tracking-scroll]')?.scrollTo({ top: 0, behavior: "auto" });
  }, [embedded, stepIndex]);
  const [state, setState] = useState<FormState>(() => {
    const seeded = seedState(inputFields);
    // Default the date field to today
    const today = new Date().toISOString().split("T")[0];
    if (!seeded.field_date) seeded.field_date = today;
    if (initialValues) {
      return { ...seeded, ...initialValues };
    }
    return seeded;
  });
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [status, setStatus] = useState<Status>("editing");
  const [failure, setFailure] = useState<string | null>(null);

  const step = steps[stepIndex];
  const isLast = stepIndex === steps.length - 1;

  const onChange = useCallback((name: string, value: FieldValue) => {
    setState((current) => {
      const next = { ...current, [name]: value };
      return pruneHidden(inputFields, next);
    });
    setErrors((current) => {
      if (!current[name]) return current;
      const rest: Record<string, string> = {};
      for (const [key, message] of Object.entries(current)) {
        if (key !== name) rest[key] = message;
      }
      return rest;
    });
    setFailure(null);
  }, []);

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
      scrollToTop();
      return;
    }
    await handleSubmit();
  }

  async function handleSubmit() {
    setStatus("submitting");
    setFailure(null);
    try {
      const url = submitUrl ?? (editMessageId
        ? `/api/tracking/update/${editMessageId}`
        : "/api/tracking/submit");
      const response = await fetch(url, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ fields: state }),
      });

      if (!response.ok) {
        const body = (await response.json().catch(() => null)) as {
          issues?: { field: string; message: string }[];
          error?: string;
          message?: string;
        } | null;
        if (body?.issues?.length) {
          const inline: Record<string, string> = {};
          const banner: string[] = [];
          for (const issue of body.issues) {
            const normalized = issue.field.split(".")[0];
            const ownedByStep = steps.some((entry) =>
              entry.fields.some((field) => field.name === normalized),
            );
            if (ownedByStep) inline[normalized] = issue.message;
            else banner.push(issue.message);
          }
          setErrors(inline);
          const firstErrored = Object.keys(inline)[0];
          if (firstErrored) {
            const owning = steps.findIndex((entry) =>
              entry.fields.some((field) => field.name === firstErrored),
            );
            if (owning !== -1 && owning !== stepIndex) {
              setStepIndex(owning);
              scrollToTop();
            }
          }
          if (banner.length) {
            setFailure(banner.length === 1 ? banner[0] : banner.join(" "));
          }
          setStatus("editing");
          return;
        }
        setStatus("failed");
        setFailure(body?.message || body?.error || "Something went wrong saving your log. Please try again.");
        return;
      }
      setStatus("done");
      onSaved?.();
    } catch {
      setStatus("failed");
      setFailure("We could not reach the server. Please check your connection and try again.");
    }
  }

  if (status === "done") {
    return (
      <SuccessPanel
        isEdit={!!editMessageId}
        onDone={onDone ?? (() => router.push("/dashboard"))}
      />
    );
  }

  return (
    <div ref={formRef} className={embedded ? "flex min-w-0 flex-col" : "flex min-h-full flex-col"}>
      {/* Top Stepper - replaces left sidebar stepper */}
      <header
        className={embedded
          ? "sticky top-0 z-20 border-b border-border bg-surface/95 px-4 py-4 pr-14 backdrop-blur sm:px-6 sm:pr-14"
          : "sticky top-0 z-20 border-b border-border bg-surface/95 px-5 py-4 backdrop-blur"}
      >
        <TopStepper current={stepIndex} steps={steps} compact={embedded} />
      </header>

      <main className={embedded ? "min-w-0 flex-1 px-4 py-5 sm:px-6 sm:py-6" : "flex-1 px-5 py-8 sm:px-10 sm:py-12"}>
        <div className="mx-auto max-w-2xl">
          <div className={embedded ? "mb-5 flex flex-col items-start gap-2 sm:flex-row sm:justify-between sm:gap-4" : "mb-8 flex items-center justify-between"}>
            <div>
              <h1 className="font-serif text-2xl text-foreground">
                {editMessageId ? "Edit Progress Log" : step.title}
              </h1>
              <p className="mt-1 text-sm text-muted-foreground">
                {editMessageId ? "Update your daily progress entry" : step.subtitle}
              </p>
            </div>
            <span className={embedded ? "shrink-0 whitespace-nowrap text-sm font-medium text-muted-foreground" : "text-sm font-medium text-muted-foreground"}>
              Step {stepIndex + 1} of {steps.length}
            </span>
          </div>

          <div className={embedded ? "rounded-xl border border-border bg-surface p-4 shadow-panel sm:p-6" : "rounded-xl border border-border bg-surface p-6 shadow-panel sm:p-8"}>
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
                />
              ))}
            </div>

            <div className={embedded ? "mt-6 flex items-center justify-between gap-2 border-t border-border pt-5" : "mt-8 flex items-center justify-between border-t border-border pt-6"}>
              <Button
                type="button"
                variant="ghost"
                className={stepIndex === 0 ? "invisible" : undefined}
                onClick={() => {
                  setErrors({});
                  setFailure(null);
                  setStepIndex((index) => Math.max(0, index - 1));
                  scrollToTop();
                }}
              >
                <ChevronLeft className="mr-2 size-4" />
                Back
              </Button>
              <Button
                type="button"
                className={embedded ? "px-4 font-semibold sm:px-6" : "px-6 font-semibold"}
                disabled={status === "submitting"}
                onClick={handleNext}
              >
                {isLast ? "Submit" : "Continue"}
                {!isLast && <ChevronRight className="ml-2 size-4" />}
              </Button>
            </div>
          </div>

          <p className="mt-6 text-center text-xs text-muted-foreground">
            Step {stepIndex + 1} of {steps.length}
          </p>
        </div>
      </main>
    </div>
  );
}

function TopStepper({
  current,
  steps: list,
  compact = false,
}: {
  current: number;
  steps: { title: string }[];
  compact?: boolean;
}) {
  return (
    <div className="flex flex-col gap-3">
      <div className="flex items-center justify-between gap-2">
        <div className="flex-1">
          <p className="text-xs font-medium text-muted-foreground uppercase tracking-wide">
            Progress
          </p>
        </div>
      </div>
      <ol className={compact ? "flex w-full items-start" : "mb-12 flex w-full items-start"}>
        {list.map((entry, index) => {
          const done = index < current;
          const active = index === current;
          const last = index === list.length - 1;
          return (
            <li key={entry.title} className={compact ? "relative min-w-0 flex-1" : "relative flex-1"} aria-label={`Step ${index + 1}: ${entry.title}`}>
              {/* 1. Connecting Line (positioned behind the circles) */}
              {!last && (
                <div
                  className={`absolute top-4 left-1/2 w-full h-[3px] -z-10 ${
                    done ? "bg-primary" : "bg-border"
                  }`}
                  aria-hidden="true"
                />
              )}

              {/* 2. Step Content (Circle + Text stacked) */}
              <div className="relative flex flex-col items-center justify-center">
                {/* Circle */}
                <div
                  className={`flex h-8 w-8 shrink-0 items-center justify-center rounded-full border-2 bg-surface z-10 ${
                    done
                      ? "border-primary bg-primary text-primary-foreground"
                      : active
                      ? "border-primary text-primary"
                      : "border-line text-muted-foreground"
                  }`}
                  aria-current={active ? "step" : undefined}
                >
                  {done ? "✓" : index + 1}
                </div>

                {/* Text Label */}
                <span className={compact ? "mx-1 mt-2 hidden max-w-full text-center text-[10px] leading-tight font-medium text-muted-foreground sm:block" : "mt-2 whitespace-nowrap text-center text-xs font-medium text-muted-foreground"}>
                  {entry.title}
                </span>
              </div>
            </li>
          );
        })}
      </ol>
      <div className="h-1.5 w-full overflow-hidden rounded-full bg-border">
        <div
          className="h-full rounded-full bg-primary transition-all duration-300"
          style={{
            width: `${((current + 1) / list.length) * 100}%`,
          }}
        />
      </div>
    </div>
  );
}

function SuccessPanel({ isEdit, onDone }: { isEdit: boolean; onDone?: () => void }) {
  return (
    <main className="flex min-h-full items-center justify-center px-5 py-12">
      <div className="w-full max-w-md rounded-xl border border-border bg-surface p-8 text-center shadow-panel">
        <div className="mb-4 flex size-14 items-center justify-center rounded-full bg-positive-soft text-positive">
          <CheckCircle2 className="size-7" aria-hidden="true" />
        </div>
        <h1 className="font-serif text-2xl text-foreground">
          {isEdit ? "Log updated" : "Log submitted"}
        </h1>
        <p className="mt-2 text-sm text-muted-foreground">
          {isEdit
            ? "Your daily progress has been updated."
            : "Your daily progress has been recorded. Keep up the great work!"}
        </p>
        {onDone && (
          <Button className="mt-6" onClick={onDone}>
            Back to My Progress
          </Button>
        )}
      </div>
    </main>
  );
}