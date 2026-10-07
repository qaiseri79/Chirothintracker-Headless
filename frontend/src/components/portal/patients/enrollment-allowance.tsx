import Link from "next/link";
import { Award, Check, Info, Lock } from "lucide-react";
import type { EnrollmentAllowance } from "@/lib/patients/types";
import { enrollmentCapacity } from "@/lib/patients/enrollment";
import { CARD } from "./patients-ui";

function UpgradeAction({ canManageBilling }: { canManageBilling: boolean }) {
  return canManageBilling ? (
    <Link href="/billing" className="shrink-0 rounded-lg bg-flame px-3.5 py-1.5 text-xs font-semibold text-white hover:opacity-90">
      Upgrade plan
    </Link>
  ) : (
    <span className="text-xs font-medium">Contact your primary doctor to change the plan.</span>
  );
}

/** Subscription usage panel from ChiroThin — enrolled_patient_count_design.html. */
export function PatientEnrollmentAllowance({
  allowance,
  enrolledCount,
  canManageBilling,
}: {
  allowance: EnrollmentAllowance | null;
  enrolledCount: number;
  canManageBilling: boolean;
}) {
  const limit = allowance?.limit;
  const used = allowance?.used ?? enrolledCount;
  const remaining = allowance?.remaining;
  const { full, low, percent: progress } = enrollmentCapacity(allowance);
  const hot = full || low;
  const unlimited = allowance !== null && limit === null;
  const NoticeIcon = hot || !allowance ? Info : Check;

  return (
    <section aria-label="Patient enrollment allowance" className={[CARD, "mb-5 overflow-hidden"].join(" ")}>
      <div className="flex flex-wrap items-center gap-x-10 gap-y-5 p-5 sm:p-6">
        <div className="flex min-w-0 flex-1 basis-[250px] items-center gap-4">
          <div className="flex size-12 shrink-0 items-center justify-center rounded-xl bg-primary text-white">
            <Award className="size-6" aria-hidden="true" />
          </div>
          <div className="min-w-0">
            <p className="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">Your subscription</p>
            <p className="font-serif text-xl leading-tight text-foreground">
              {allowance ? allowance.planName + " plan" : "Patient enrollment"}
            </p>
            <p className="text-xs text-muted-foreground">
              {allowance?.scheduledPlanName ? "Enrollment reserved for your scheduled " + allowance.scheduledPlanName + " downgrade" : unlimited ? "Includes unlimited active patients" : typeof limit === "number" ? "Includes up to " + limit + " active patients" : "Plan allowance is unavailable"}
            </p>
          </div>
        </div>
        <dl className="grid w-full grid-cols-3 divide-x divide-line sm:w-auto">
          <AllowanceStat label="Patients allowed" value={unlimited ? "Unlimited" : limit ?? "—"} />
          <AllowanceStat label="Currently enrolled" value={used} />
          <AllowanceStat label="Slots available" value={unlimited ? "Unlimited" : remaining ?? "—"} tone={hot ? "text-flame" : "text-primary"} />
        </dl>
      </div>
      {progress !== null ? (
        <div className="px-5 pb-5 sm:px-6">
          <div
            role="progressbar"
            aria-label="Patient allowance used"
            aria-valuemin={0}
            aria-valuemax={100}
            aria-valuenow={Math.round(progress)}
            aria-valuetext={used + " enrolled out of " + limit + " allowed"}
            className="flex h-2 overflow-hidden rounded-full bg-primary-soft"
          >
            <div className={hot ? "bg-flame" : "bg-primary"} style={{ width: progress + "%" }} />
          </div>
          <div className="mt-1.5 flex justify-between text-[11px] text-muted-foreground">
            <span>{used} enrolled</span>
            <span>{remaining} available</span>
          </div>
        </div>
      ) : null}
      <div className={["flex flex-wrap items-center gap-3 border-t px-5 py-3 text-sm sm:px-6", hot ? "border-flame/20 bg-flame-soft text-flame" : "border-line bg-canvas/60 text-foreground/80"].join(" ")}>
        <NoticeIcon className="size-4 shrink-0" aria-hidden="true" />
        <span className="min-w-0 flex-1">
          {!allowance ? "Your remaining allowance is unavailable. Enrollment eligibility will be checked when you submit."
            : unlimited ? "You can enroll unlimited patients on your current plan."
            : full ? <><b>No patient slots available.</b> Your plan&apos;s allowance of {limit} active patients has been reached.</>
            : low ? <><b>Only {remaining} {remaining === 1 ? "slot" : "slots"} left.</b> You can enroll {remaining} more {remaining === 1 ? "patient" : "patients"} before reaching your limit.</>
            : <>You can enroll <b>{remaining} more patients</b> on your current plan.</>}
        </span>
        {hot ? <UpgradeAction canManageBilling={canManageBilling} /> : null}
      </div>
    </section>
  );
}

function AllowanceStat({ label, value, tone = "text-foreground" }: { label: string; value: number | string; tone?: string }) {
  return (
    <div className="min-w-0 px-3 text-center first:pl-0 last:pr-0 sm:px-5">
      <dt className="text-[11px] font-medium uppercase tracking-wider text-muted-foreground">{label}</dt>
      <dd className={["mt-1 font-serif tabular-nums leading-none", value === "Unlimited" ? "text-lg sm:text-2xl" : "text-3xl", tone].join(" ")}>{value}</dd>
    </div>
  );
}

export function EnrollmentLimitCard({
  allowance,
  canManageBilling,
  onPatientList,
}: {
  allowance: EnrollmentAllowance;
  canManageBilling: boolean;
  onPatientList: () => void;
}) {
  return (
    <div className={[CARD, "mx-auto max-w-2xl p-8 text-center"].join(" ")}>
      <div className="mx-auto flex size-12 items-center justify-center rounded-full bg-flame-soft text-flame">
        <Lock className="size-6" aria-hidden="true" />
      </div>
      <h3 className="mt-4 font-serif text-xl text-foreground">Adding new patients is paused</h3>
      <p className="mx-auto mt-2 max-w-md text-sm text-muted-foreground">
        Your {allowance.scheduledPlanName ?? allowance.planName} {allowance.scheduledPlanName ? "scheduled downgrade" : "plan"} allows {allowance.limit} active patients and that allowance has been reached.
        {" "}Archive patients who have left the program to free a slot, or {canManageBilling ? "upgrade your plan." : "contact your primary doctor about upgrading the plan."}
      </p>
      <div className="mt-6 flex flex-wrap justify-center gap-3">
        {canManageBilling ? (
          <Link href="/billing" className="rounded-lg bg-primary px-5 py-2.5 text-sm font-semibold text-white hover:bg-brand-dark">Upgrade plan</Link>
        ) : null}
        <button type="button" onClick={onPatientList} className="rounded-lg border border-line px-5 py-2.5 text-sm font-medium text-foreground/80 hover:border-primary hover:text-primary">
          Go to patient list
        </button>
      </div>
    </div>
  );
}