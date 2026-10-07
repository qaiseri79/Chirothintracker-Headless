"use client";

import { useId, type FormEvent, type ReactNode } from "react";
import Link from "next/link";
import { cn } from "cn";
import {
  SubscribeButton,
  SUBSCRIBE_FIELD,
  SUBSCRIBE_FIELD_INVALID,
  SUBSCRIBE_METER_SEGMENT,
  SUBSCRIBE_PILL,
  SUBSCRIBE_PILL_ON,
} from "@/components/design-preview/subscribe-primitives";

/**
 * The mock-up's field primitives.
 *
 * The source styles a bare `<input class="f">` and reveals its error with the
 * sibling selector `.bad ~ .err` (line 49), so validity lives as a class on the
 * input while the message is a separate `<p>`. Here the two are one component, so
 * a caller cannot render the ring without the message or the message without the
 * ring.
 */

/**
 * A labelled field with an optional hint and an inline error.
 *
 * `render` receives the ids so `htmlFor` and `aria-describedby` stay wired
 * without the caller having to invent them. `aria-describedby` is not in the
 * mock-up — it uses the implicit label association and relies on the error being
 * adjacent — but an error that is only drawn next to a field is easy to miss
 * with a screen reader, so it is announced here too.
 */
export function SubscribeField({
  label,
  hint,
  error,
  required = false,
  children,
}: {
  label: string;
  hint?: string;
  error?: string;
  required?: boolean;
  children: (ids: { inputId: string; describedBy: string | undefined }) => ReactNode;
}) {
  const uid = useId();
  const inputId = `${uid}-input`;
  const hintId = `${uid}-hint`;
  const errorId = `${uid}-error`;

  const describedBy =
    [error ? errorId : null, hint ? hintId : null].filter(Boolean).join(" ") ||
    undefined;

  return (
    <div className="mb-[18px]">
      <label htmlFor={inputId} className="mb-1.5 block text-[13px] font-bold">
        {label}
        {required ? (
          <span aria-hidden className="text-flame">
            {" "}
            *
          </span>
        ) : null}
      </label>

      {children({ inputId, describedBy })}

      {/*
       * The hint renders unconditionally rather than only when valid: the
       * mock-up keeps "Use 12–128 characters with a mix of types." on screen while
       * the meter fills.
       */}
      {hint ? (
        <p id={hintId} className="mt-1.5 text-[12px] text-muted-foreground">
          {hint}
        </p>
      ) : null}

      {/*
       * `role="alert"` because it appears in response to a submit attempt. The
       * mock-up's `.err` is `display:none` until the input gains `.bad`, which is
       * an assertion and should interrupt rather than be discovered.
       */}
      {error ? (
        <p
          id={errorId}
          role="alert"
          className="mt-1.5 text-[12px] font-semibold text-flame"
        >
          {error}
        </p>
      ) : null}
    </div>
  );
}

/** The text input, with the mock-up's focus ring and its invalid treatment. */
export function SubscribeInput({
  invalid,
  className,
  ...props
}: React.ComponentProps<"input"> & { invalid?: boolean }) {
  return (
    <input
      {...props}
      aria-invalid={invalid || undefined}
      className={cn(
        SUBSCRIBE_FIELD,
        invalid && SUBSCRIBE_FIELD_INVALID,
        className,
      )}
    />
  );
}

/** The mock-up's four-segment password strength meter, with its scoring rules. */
export function PasswordMeter({ value }: { value: string }) {
  const score = scorePassword(value);
  const stops = ["#a8421f", "#d08a2e", "#5e9e6e", "#0b5d52"];
  const labels = ["Weak", "Fair", "Good", "Strong"];

  return (
    <div
      className="mt-2 flex gap-1"
      role="meter"
      aria-valuemin={0}
      aria-valuemax={4}
      aria-valuenow={score}
      aria-valuetext={value ? labels[Math.max(0, score - 1)] : "Empty"}
    >
      {[0, 1, 2, 3].map((index) => (
        <span
          key={index}
          aria-hidden
          className={SUBSCRIBE_METER_SEGMENT}
          style={{
            background:
              index < score ? stops[Math.max(0, score - 1)] : "var(--border)",
          }}
        />
      ))}
    </div>
  );
}

/**
 * One point each for length ≥8, mixed case, a digit, and a symbol at ≥10
 * characters — the mock-up's rule (line 193). Pure so the meter, the hint word
 * and any future submit-time check cannot disagree.
 */
export function scorePassword(value: string): number {
  let score = 0;
  if (value.length >= 12) score += 1;
  if (/[A-Z]/.test(value) && /[a-z]/.test(value)) score += 1;
  if (/\d/.test(value)) score += 1;
  if (/[^A-Za-z0-9]/.test(value) && value.length >= 10) score += 1;
  return score;
}

/** The strength word under the meter, matching the mock-up's `#pwt`. */
export function passwordStrengthLabel(value: string): string {
  if (!value) return "Use 12–128 characters with a mix of types.";
  const labels = ["Weak", "Fair", "Good", "Strong"];
  return labels[Math.max(0, scorePassword(value) - 1)];
}

/**
 * The two account forms, switched by the new/returning toggle.
 *
 * Both are always mounted and toggled with `hidden`, never unmounted, so values
 * typed into one survive a toggle to the other — which is what the mock-up does
 * when it only flips `style.display` (line 182).
 *
 * Validation state lives in the parent so one submit handler covers both; the
 * only state kept here is the password, which the meter needs on every keystroke
 * regardless of which form is showing.
 */
export function AccountForm({
  mode,
  values,
  errors,
  onChange,
  onSubmit,
  onModeChange,
  busy = false,
  accepted = false,
  onAccept,
  termsError,
}: {
  busy?: boolean;
  accepted?: boolean;
  onAccept?: (accepted: boolean) => void;
  termsError?: string | null;
  mode: "new" | "login";
  values: Record<AccountField, string>;
  errors: Partial<Record<AccountField, string>>;
  onChange: (field: AccountField, value: string) => void;
  onSubmit: (event: FormEvent<HTMLFormElement>) => void;
  onModeChange: (mode: "new" | "login") => void;
}) {
  const password = values.password;
  const passwordHint = passwordStrengthLabel(password);

  return (
    <>
      <div className="mb-6 flex gap-2" role="group" aria-label="New or returning customer">
        {(["new", "login"] as const).map((candidate) => (
          <button
            key={candidate}
            type="button"
            aria-pressed={mode === candidate}
            disabled={busy}
            onClick={() => onModeChange(candidate)}
            className={cn(
              SUBSCRIBE_PILL,
              mode === candidate && SUBSCRIBE_PILL_ON,
            )}
          >
            {candidate === "new" ? "New customer" : "Returning customer"}
          </button>
        ))}
      </div>

      <form
        noValidate
        onSubmit={onSubmit}
        hidden={mode !== "new"}
        aria-hidden={mode !== "new"}
      >
        <div className="grid grid-cols-1 gap-4 min-[600px]:grid-cols-2">
          <SubscribeField label="Full name" required error={errors.name}>
            {({ inputId, describedBy }) => (
              <SubscribeInput
                id={inputId}
                aria-describedby={describedBy}
                autoComplete="name"
                value={values.name}
                invalid={Boolean(errors.name)}
                onChange={(event) => onChange("name", event.target.value)}
              />
            )}
          </SubscribeField>

          <SubscribeField label="Email address" required error={errors.email}>
            {({ inputId, describedBy }) => (
              <SubscribeInput
                id={inputId}
                type="email"
                aria-describedby={describedBy}
                autoComplete="email"
                value={values.email}
                invalid={Boolean(errors.email)}
                onChange={(event) => onChange("email", event.target.value)}
              />
            )}
          </SubscribeField>
        </div>

        <SubscribeField
          label="Clinic / business name"
          required
          error={errors.clinicName}
        >
          {({ inputId, describedBy }) => (
            <SubscribeInput
              id={inputId}
              aria-describedby={describedBy}
              autoComplete="organization"
              value={values.clinicName}
              maxLength={128}
              invalid={Boolean(errors.clinicName)}
              onChange={(event) => onChange("clinicName", event.target.value)}
            />
          )}
        </SubscribeField>

        <div className="grid grid-cols-1 gap-4 min-[600px]:grid-cols-2">
          <SubscribeField
            label="Password"
            required
            error={errors.password}
            hint={passwordHint}
          >
            {({ inputId, describedBy }) => (
              <>
                <SubscribeInput
                  id={inputId}
                  type="password"
                  aria-describedby={describedBy}
                  autoComplete="new-password"
                  value={password}
                  invalid={Boolean(errors.password)}
                  onChange={(event) => onChange("password", event.target.value)}
                />
                <PasswordMeter value={password} />
              </>
            )}
          </SubscribeField>

          <SubscribeField label="Confirm password" required error={errors.confirm}>
            {({ inputId, describedBy }) => (
              <SubscribeInput
                id={inputId}
                type="password"
                aria-describedby={describedBy}
                autoComplete="new-password"
                value={values.confirm}
                invalid={Boolean(errors.confirm)}
                onChange={(event) => onChange("confirm", event.target.value)}
              />
            )}
          </SubscribeField>
        </div>

        {/*
         * The mock-up's button sits inside each form (lines 104 and 110), not
         * beside them, so a click validates through the form's own submit handler
         * rather than through a delegated click listener.
         */}
        <label className="mb-4 flex items-start gap-2 text-sm text-muted-foreground">
          <input type="checkbox" checked={accepted} onChange={event => onAccept?.(event.target.checked)} className="mt-1 accent-[var(--brand)]" />
          <span>I agree to the <Link href="/terms-of-service" target="_blank" className="underline">Terms of Service</Link> and <Link href="/privacy-policy" target="_blank" className="underline">Privacy Policy</Link>.</span>
        </label>
        {termsError ? <p role="alert" className="mb-3 text-sm text-flame">{termsError}</p> : null}
        <SubscribeButton type="submit" disabled={busy} className="w-full">
          {busy ? "Creating account…" : "Create account and continue →"}
        </SubscribeButton>
        <p className="mt-3 text-center text-[12px] text-muted-foreground">
          Your email is never public. It&apos;s only used to contact you about your
          account.
        </p>
      </form>

      <form
        noValidate
        onSubmit={onSubmit}
        hidden={mode !== "login"}
        aria-hidden={mode !== "login"}
      >
        <SubscribeField label="Email or username" error={errors.loginUsername}>
          {({ inputId, describedBy }) => (
            <SubscribeInput
              id={inputId}
              aria-describedby={describedBy}
              autoComplete="username"
              value={values.loginUsername}
                invalid={Boolean(errors.loginUsername)}
              onChange={(event) => onChange("loginUsername", event.target.value)}
            />
          )}
        </SubscribeField>

        <SubscribeField label="Password" error={errors.loginPassword}>
          {({ inputId, describedBy }) => (
            <SubscribeInput
              id={inputId}
              type="password"
              aria-describedby={describedBy}
              autoComplete="current-password"
              value={values.loginPassword}
                invalid={Boolean(errors.loginPassword)}
              onChange={(event) => onChange("loginPassword", event.target.value)}
            />
          )}
        </SubscribeField>

        <SubscribeButton type="submit" disabled={busy} className="w-full">
          {busy ? "Logging in…" : "Log in and continue →"}
        </SubscribeButton>
        <p className="mt-3 text-center text-[12px] text-muted-foreground">
          <Link href="/forgot-password" className="font-semibold text-flame underline">
            Forgot password?
          </Link>
        </p>
      </form>
    </>
  );
}

/** The fields the account step can report an error against. */
export type AccountField =
  | "name"
  | "email"
  | "clinicName"
  | "password"
  | "confirm"
  | "loginUsername"
  | "loginPassword";