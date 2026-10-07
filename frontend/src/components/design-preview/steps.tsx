"use client";

import { Check } from "lucide-react";
import { cn } from "cn";
import {
  SUBSCRIBE_STEPS,
  type SubscribePlan,
  formatMoney,
  planFeatures,
} from "@/lib/design-preview/subscribe-plans";
import {
  SUBSCRIBE_NOTE,
  SUBSCRIBE_PILL,
  SUBSCRIBE_PILL_ON,
} from "@/components/design-preview/subscribe-primitives";

/**
 * The step indicator and the order summary.
 *
 * Both are pure functions of the selected plan, so the mock-up's habit of
 * redrawing them on every step change (its `drawPlan`) becomes ordinary
 * re-render here.
 */

/** `.steps` / `.st` / `.bar` — the three-dot progress indicator. */
export function StepIndicator({ step }: { step: number }) {
  return (
    <ol
      className={cn(
        "my-8 flex flex-wrap items-center justify-center gap-2.5 text-[14px] font-semibold",
        /* The mock-up hides the indicator once the flow is complete (line 169). */
        step > SUBSCRIBE_STEPS.length && "hidden",
      )}
    >
      {SUBSCRIBE_STEPS.map((label, index) => {
        const position = index + 1;
        const done = step > position;
        return (
          <li key={label} className="flex items-center gap-2.5">
            <span
              className={cn(
                "grid size-7 place-items-center rounded-full border-[1.5px] text-[13px]",
                done
                  ? "border-brand bg-brand-soft text-brand"
                  : position === step
                    ? "border-brand bg-brand text-white"
                    : "border-border bg-surface text-muted-foreground",
              )}
            >
              {done ? <Check aria-hidden className="size-3.5" strokeWidth={3.2} /> : position}
            </span>
            <span
              className={cn(position === step ? "text-foreground" : "text-muted-foreground")}
            >
              {label}
            </span>
            {index < SUBSCRIBE_STEPS.length - 1 ? (
              <span aria-hidden className="h-0.5 w-9 bg-border" />
            ) : null}
          </li>
        );
      })}
    </ol>
  );
}

/**
 * The order summary sidebar.
 *
 * `.sum dl` is the mock-up's two-column definition row; `.tot` is the due-today
 * line. Recreated with a real `<dl>` rather than `<div>`s because the pairing is
 * genuinely tabular.
 */
export function OrderSummary({ plan }: { plan: SubscribePlan }) {
  const rows: [string, string][] = [
    ["Plan", plan.name],
    ["Billing", plan.per === "month" ? "Monthly" : "Yearly"],
    ["Activation fee", formatMoney((plan.activationFeeMinor ?? 0) / 100, plan.currency)],
  ];

  return (
    <>
      <h3 className="mb-2 font-serif text-[22px] leading-tight font-semibold">
        Order summary
      </h3>
      <dl className="mt-2">
        {rows.map(([term, value]) => (
          <div
            key={term}
            className="flex items-baseline justify-between gap-3 border-b border-border py-3 text-[15px]"
          >
            <dt className="text-muted-foreground">{term}</dt>
            <dd className="text-right font-bold">{value}</dd>
          </div>
        ))}
      </dl>
      <div className="flex items-baseline justify-between gap-3 pt-4 font-bold">
        <span>Due today</span>
        <span className="font-serif text-[30px] leading-none font-semibold">
          {formatMoney(plan.price + (plan.activationFeeMinor ?? 0) / 100, plan.currency)}
        </span>
      </div>
      <p className="mt-2.5 text-[12px] text-muted-foreground">
        Then {formatMoney(plan.price, plan.currency)} every {plan.per}. Cancel any time.
      </p>
    </>
  );
}

/** The plan selector chips (`.pills`) for the plan step. */
export function PlanPicker({
  plans,
  selectedId,
  onSelect,
}: {
  plans: SubscribePlan[];
  selectedId: number;
  onSelect: (id: number) => void;
}) {
  return (
    <div className="mb-6 flex flex-wrap gap-2" role="group" aria-label="Choose a plan">
      {plans.map((plan) => {
        const selected = plan.id === selectedId;
        return (
          <button
            key={plan.id}
            type="button"
            aria-pressed={selected}
            onClick={() => onSelect(plan.id)}
            className={cn(
              SUBSCRIBE_PILL,
              selected
                ? SUBSCRIBE_PILL_ON
                : "border-border",
            )}
          >
            {plan.name}
          </button>
        );
      })}
    </div>
  );
}

/** The selected plan's detail card contents. */
export function PlanDetail({ plan }: { plan: SubscribePlan }) {
  const features = planFeatures(plan);

  return (
    <>
      <span className="inline-block rounded-full bg-brand-soft px-3 py-1 text-[12px] font-bold tracking-[0.06em] text-brand uppercase">
        {plan.name}
      </span>

      <p className="mt-3.5 font-serif text-[56px] leading-none font-semibold">
        {formatMoney(plan.price, plan.currency)}
        <small className="font-sans text-[16px] font-medium text-muted-foreground">
          {" "}
          /{plan.per}
        </small>
      </p>

      <p className="mt-2 text-muted-foreground">{plan.sub}</p>

      <ul className="my-6 grid grid-cols-1 gap-3 min-[600px]:grid-cols-2">
        {features.map((feature) => (
          <li key={feature} className="flex items-start gap-2.5 text-[15px] font-medium">
            <Check
              aria-hidden
              strokeWidth={3.2}
              className="mt-0.5 size-5 shrink-0 rounded-full bg-brand-soft p-1 text-brand"
            />
            <span>{feature}</span>
          </li>
        ))}
      </ul>

      <p className={SUBSCRIBE_NOTE}>
        <b className="text-foreground">Good to know:</b> {plan.note} ChiroThinTracker
        is the official ChiroThin patient management and tracking software.
      </p>
    </>
  );
}

