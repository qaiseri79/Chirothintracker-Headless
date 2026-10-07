"use client";

import { useEffect, useRef, useState, type FormEvent } from "react";
import Link from "next/link";
import { Check } from "lucide-react";
import { useAuth } from "@/lib/auth";
import { OrderSummary, PlanDetail, PlanPicker, StepIndicator } from "@/components/design-preview/steps";
import { AccountForm, SubscribeField, SubscribeInput, type AccountField } from "@/components/design-preview/subscribe-fields";
import { SubscribeButton, SubscribeCard, SUBSCRIBE_GRID, SUBSCRIBE_H1, SUBSCRIBE_SUB } from "@/components/design-preview/subscribe-primitives";
import { PaymentFields, type PaymentFieldsHandle } from "./payment-fields";
import { subscriptionApi, SubscriptionApiError } from "@/lib/subscriptions/client";
import { displayPlan } from "@/lib/subscriptions/plans";
import type { BillingAddress, SubscriptionCatalog, SubscriptionCart, SubscriptionStatus } from "@/lib/subscriptions/types";

const EMPTY_ACCOUNT: Record<AccountField, string> = { name: "", email: "", clinicName: "", password: "", confirm: "", loginUsername: "", loginPassword: "" };
const EMPTY_BILLING: BillingAddress = { firstName: "", lastName: "", company: "", address: "", city: "", state: "", zip: "", country: "US" };
const REVIEW_STATES = ["charging", "payment_review", "paid_pending_schedule", "scheduling", "renewal_review"];
type Attempt = { cartId: string; idempotencyKey: string; planId: number };
const storageKey = (id: number) => `ctt-subscription-attempt-${id}`;
function storedAttempt(id: number): Attempt | null {
  try {
    const value = JSON.parse(sessionStorage.getItem(storageKey(id)) ?? "null") as Attempt | null;
    return value && Number.isInteger(value.planId) && /^[a-f0-9]{64}$/.test(value.cartId) && /^[a-zA-Z0-9_-]{16,64}$/.test(value.idempotencyKey) ? value : null;
  } catch { return null; }
}
function saveAttempt(id: number, value: Attempt | null) {
  try { if (value) sessionStorage.setItem(storageKey(id), JSON.stringify(value)); else sessionStorage.removeItem(storageKey(id)); } catch { /* In-memory protection still applies when browser storage is disabled. */ }
}

export function SubscribeFlow({ catalog, initialPlanId }: { catalog: SubscriptionCatalog; initialPlanId?: number }) {
  const { user, status: authStatus, login, refresh, logout } = useAuth();
  const [selectedId, setSelectedId] = useState(catalog.plans.find(plan => plan.id === initialPlanId)?.id ?? catalog.plans[0].id);
  const [step, setStep] = useState<1 | 2 | 3>(1);
  const [cart, setCart] = useState<SubscriptionCart | null>(null);
  const [mode, setMode] = useState<"new" | "login">("new");
  const [account, setAccount] = useState(EMPTY_ACCOUNT);
  const [errors, setErrors] = useState<Partial<Record<AccountField, string>>>({});
  const [accepted, setAccepted] = useState(false);
  const [paymentAccepted, setPaymentAccepted] = useState(false);
  const [termsError, setTermsError] = useState<string | null>(null);
  const [billing, setBilling] = useState(EMPTY_BILLING);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [uncertain, setUncertain] = useState(false);
  const [snapshot, setSnapshot] = useState<{ uid: number; data?: SubscriptionStatus; error?: string }>({ uid: 0 });
  const guard = useRef(false);
  const attempt = useRef<Attempt | null>(null);
  const payment = useRef<PaymentFieldsHandle>(null);
  const uid = user?.id ?? 0;
  const current = snapshot.uid === uid ? snapshot.data : undefined;
  const checking = authStatus === "loading" || (authStatus === "authenticated" && (snapshot.uid !== uid || (!snapshot.data && !snapshot.error)));
  const planDto = cart?.plan ?? catalog.plans.find(plan => plan.id === selectedId) ?? catalog.plans[0];
  const plan = displayPlan(planDto);
  const billingConfig = current?.billing ?? catalog.billing;
  const hasPaidAccess = current?.capabilities.portalWrite === true;
  const pendingReview = current?.subscription && REVIEW_STATES.includes(current.subscription.status);

  useEffect(() => {
    if (authStatus !== "authenticated" || !uid) return;
    let cancelled = false;
    attempt.current = null;
    subscriptionApi.current().then(data => {
      if (cancelled) return;
      setSnapshot({ uid, data });
      attempt.current = storedAttempt(uid);
      if (data.capabilities.portalWrite) { saveAttempt(uid, null); attempt.current = null; }
      // Restore the same cart/key after a reload without storing payment tokens.
      else if (attempt.current) {
        const savedAttempt = attempt.current;
        setUncertain(true);
        subscriptionApi.cart(savedAttempt.cartId).then(saved => {
          if (!cancelled) { setCart(saved); setSelectedId(saved.plan.id); setStep(3); }
        }).catch(() => {
          const savedPlan = catalog.plans.find(plan => plan.id === savedAttempt.planId);
          if (!cancelled && savedPlan) {
            // An expired cart can still replay an existing durable attempt. The
            // backend checks that attempt before cart expiry and never reprices it.
            setCart({ id: savedAttempt.cartId, uid, plan: savedPlan, expiresAt: 0 });
            setSelectedId(savedPlan.id); setStep(3);
          }
        });
      }
    }).catch(err => {
      if (!cancelled) setSnapshot({ uid, error: err instanceof SubscriptionApiError && err.status === 403 ? "This account uses the existing portal. Please continue to your dashboard or sign out to register a new doctor account." : "We could not check your subscription. Please reload to try again." });
    });
    return () => { cancelled = true; };
  }, [uid, authStatus, catalog.plans]);

  function go(next: 1 | 2 | 3) { setStep(next); setError(null); window.scrollTo({ top: 0, behavior: "smooth" }); }
  function changeAccount(field: AccountField, value: string) { setAccount(previous => ({ ...previous, [field]: value })); setErrors(previous => ({ ...previous, [field]: undefined })); }

  async function continueWithPlan(returning = false) {
    if (guard.current || checking || uncertain) return;
    guard.current = true; setBusy(true); setError(null);
    try {
      const next = await subscriptionApi.createCart(selectedId);
      setCart(next);
      if (returning) setMode("login");
      go(authStatus === "authenticated" ? 3 : 2);
    } catch (err) { setError(err instanceof Error ? err.message : "Unable to create your subscription cart."); }
    finally { guard.current = false; setBusy(false); }
  }

  async function handleAccountSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (guard.current) return;
    const validation: Partial<Record<AccountField, string>> = {};
    if (mode === "new") {
      if (!account.name.trim()) validation.name = "Enter your full name.";
      if (!/^\S+@\S+\.\S+$/.test(account.email.trim())) validation.email = "Enter a valid email address.";
      if (!account.clinicName.trim()) validation.clinicName = "Enter your clinic name.";
      if (account.password.length < 12 || account.password.length > 128) validation.password = "Use a password between 12 and 128 characters.";
      if (account.password !== account.confirm) validation.confirm = "Passwords don't match.";
      setTermsError(accepted ? null : "Accept the terms to continue.");
    } else {
      if (!account.loginUsername.trim()) validation.loginUsername = "Enter your email or username.";
      if (!account.loginPassword) validation.loginPassword = "Enter your password.";
    }
    setErrors(validation); setError(null);
    if (Object.keys(validation).length || (mode === "new" && !accepted)) return;
    guard.current = true; setBusy(true);
    let created = false;
    try {
      if (mode === "new") {
        await subscriptionApi.register({ email: account.email.trim(), password: account.password, fullName: account.name.trim(), clinicName: account.clinicName.trim(), acceptTerms: accepted });
        created = true;
      }
      const signedIn = await login(mode === "new" ? account.email : account.loginUsername, mode === "new" ? account.password : account.loginPassword);
      const data = await subscriptionApi.current();
      setSnapshot({ uid: signedIn.id, data });
      setBilling(previous => ({ ...previous, firstName: account.name.trim().split(/\s+/)[0] ?? "", lastName: account.name.trim().split(/\s+/).slice(1).join(" "), company: account.clinicName.trim() }));
      setAccount(previous => ({ ...previous, password: "", confirm: "", loginPassword: "" }));
      go(3);
    } catch (err) {
      if (created) {
        setMode("login");
        setAccount(previous => ({ ...previous, loginUsername: previous.email, password: "", confirm: "" }));
        setError("Your account was created. Please log in to continue.");
      } else {
        setError(err instanceof Error ? err.message : "Unable to continue. Please try again.");
        if (err instanceof SubscriptionApiError) {
          const fields = err.fields;
          setErrors({ name: fields.fullName, clinicName: fields.clinicName, email: fields.email, password: fields.password });
          if (fields.acceptTerms) setTermsError(fields.acceptTerms);
        }
      }
    } finally { guard.current = false; setBusy(false); }
  }

  async function checkStatus() {
    if (guard.current || !uid) return;
    guard.current = true; setBusy(true); setError(null);
    try {
      const data = await subscriptionApi.current();
      setSnapshot({ uid, data });
      if (data.capabilities.portalWrite) { saveAttempt(uid, null); attempt.current = null; await refresh(); }
      else setError(data.subscription ? "Your payment is still being checked. Please contact support if it does not update." : "No completed payment is showing yet. You can retry the same checkout safely.");
    } catch (err) { setError(err instanceof Error ? err.message : "Unable to check payment status."); }
    finally { guard.current = false; setBusy(false); }
  }

  async function handlePaySubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (guard.current || !cart || !billingConfig.available || !uid || !current) return;
    if (!paymentAccepted) { setTermsError("Accept the recurring payment terms to continue."); return; }
    setTermsError(null); setError(null); guard.current = true; setBusy(true);
    let submitted = false;
    try {
      const opaqueData = await payment.current!.tokenize();
      if (!attempt.current) {
        attempt.current = { cartId: cart.id, idempotencyKey: crypto.randomUUID(), planId: cart.plan.id };
        saveAttempt(uid, attempt.current);
      }
      submitted = true;
      const data = await subscriptionApi.checkout({ cartId: attempt.current.cartId, idempotencyKey: attempt.current.idempotencyKey, opaqueData, billing });
      setSnapshot({ uid, data });
      if (data.capabilities.portalWrite) {
        saveAttempt(uid, null); attempt.current = null; setUncertain(false); await refresh();
      } else setUncertain(true);
    } catch (err) {
      const apiError = err instanceof SubscriptionApiError ? err : null;
      if (submitted && (!apiError || apiError.status === 0 || apiError.status >= 500 || ["payment_uncertain", "busy", "subscription_exists"].includes(apiError.code))) {
        setUncertain(true);
        setError("Your payment result could not be confirmed. Check payment status before continuing.");
      } else {
        setError(err instanceof Error ? err.message : "Unable to start your subscription.");
        if (submitted && apiError) {
          // Only definitive failures may start a fresh attempt or a different plan.
          saveAttempt(uid, null); attempt.current = null; setUncertain(false);
          if (["cart_changed", "cart_not_found"].includes(apiError.code)) { setCart(null); setStep(1); }
        }
      }
    } finally { guard.current = false; setBusy(false); }
  }

  if (checking) return <p role="status" className="py-16 text-center text-muted-foreground">Checking your account…</p>;
  if (authStatus === "authenticated" && snapshot.error) return <SubscribeCard className="my-12 text-center">
    <p role="alert">{snapshot.error}</p>
    <div className="mt-5 flex flex-wrap justify-center gap-4"><Link href="/dashboard" className="text-brand underline">Dashboard</Link><button type="button" className="text-brand underline" onClick={() => void logout()}>Sign out</button><button type="button" className="text-brand underline" onClick={() => window.location.reload()}>Retry</button></div>
  </SubscribeCard>;
  if (hasPaidAccess && current?.subscription) return <section className="mx-auto my-14 max-w-[560px] text-center"><SubscribeCard className="p-8 sm:p-11">
    <div className="mx-auto mb-5 grid size-[72px] place-items-center rounded-full bg-brand-soft text-brand"><Check className="size-8" aria-hidden /></div>
    <h1 className={SUBSCRIBE_H1}>Your subscription is active</h1>
    <p className="mt-4 text-muted-foreground">Your {current.subscription.plan.name} plan is ready. Access is paid through {new Date((current.subscription.paidThrough ?? 0) * 1000).toLocaleDateString()}.</p>
    {current.subscription.needsReview ? <p className="mt-4 text-sm text-flame">Your paid access is available. Automatic renewal needs review; contact support.</p> : null}
    <Link href="/chiropractor" className="mt-6 block rounded-full bg-brand px-6 py-3 font-semibold text-white">Go to your dashboard →</Link>
    <Link href="/billing" className="mt-4 inline-block text-brand underline">Manage subscription &amp; billing</Link>
  </SubscribeCard></section>;
  if (pendingReview) return <SubscribeCard className="mx-auto my-14 max-w-[560px] text-center"><h1 className={SUBSCRIBE_H1}>Payment is being checked</h1><p className="mt-4 text-muted-foreground">Please wait for confirmation before starting another subscription. Contact support if this does not update.</p><SubscribeButton disabled={busy} onClick={() => void checkStatus()} className="mt-6">Check payment status</SubscribeButton>{error ? <p role="alert" className="mt-4 text-flame">{error}</p> : null}</SubscribeCard>;

  return <>
    {user ? <div className="mt-6 flex flex-wrap justify-end gap-x-3 gap-y-1 text-xs text-muted-foreground"><span>Signed in as {user.email}</span>{current ? <Link href="/billing" className="text-brand underline">Subscription &amp; Billing</Link> : null}<button type="button" disabled={busy} className="text-brand underline" onClick={() => void logout()}>Sign out</button></div> : null}
    <StepIndicator step={step} />
    <h1 className={SUBSCRIBE_H1}>{step === 1 ? "Your ChiroThin Tracker plan" : step === 2 ? mode === "new" ? "Create your account" : "Welcome back" : "Checkout"}</h1>
    <p className={SUBSCRIBE_SUB}>{step === 1 ? "Choose the subscription that fits your clinic." : step === 2 ? "Continue with your doctor account to manage your clinic." : "Review your subscription and add a payment method."}</p>
    {error ? <p role="alert" className="mt-5 rounded-xl border border-flame/30 bg-surface p-4 text-sm text-flame">{error}</p> : null}
    {uncertain ? <div className="mt-4 rounded-xl border border-border bg-surface p-4"><p className="text-sm">A checkout attempt is awaiting confirmation. Keep this plan while you check its status.</p><SubscribeButton type="button" disabled={busy} variant="ghost" onClick={() => void checkStatus()} className="mt-2">Check payment status</SubscribeButton></div> : null}
    <div className={SUBSCRIBE_GRID}>
      <SubscribeCard>
        {step === 1 ? <>
          <PlanPicker plans={catalog.plans.map(displayPlan)} selectedId={selectedId} onSelect={id => { if (!busy && !uncertain) { setSelectedId(id); setCart(null); } }} />
          <PlanDetail plan={plan} />
        </> : step === 2 ? <>
          <AccountForm mode={mode} values={account} errors={errors} onChange={changeAccount} onSubmit={handleAccountSubmit} onModeChange={setMode} busy={busy} accepted={accepted} onAccept={value => { setAccepted(value); setTermsError(null); }} termsError={termsError} />
          <SubscribeButton type="button" variant="ghost" disabled={busy} onClick={() => go(1)} className="mt-5">← Back to plan</SubscribeButton>
        </> : <>
          <form onSubmit={handlePaySubmit}>
            <fieldset disabled={busy}>
              <h3 className="mb-4 font-serif text-[22px] font-semibold">Billing details</h3>
              <div className="grid gap-x-4 sm:grid-cols-2">
                {([ ["firstName", "First name", "given-name"], ["lastName", "Last name", "family-name"], ["company", "Clinic / business name", "organization"], ["address", "Billing address", "street-address"], ["city", "City", "address-level2"], ["state", "State / province", "address-level1"], ["zip", "ZIP / postal code", "postal-code"], ["country", "Country code", "country"] ] as const).map(([field, label, autoComplete]) => <SubscribeField key={field} label={label} required={["firstName", "lastName", "zip"].includes(field)}>{({ inputId }) => <SubscribeInput id={inputId} value={billing[field]} autoComplete={autoComplete} maxLength={field === "country" ? 2 : 100} required={["firstName", "lastName", "zip"].includes(field)} onChange={event => setBilling(previous => ({ ...previous, [field]: event.target.value }))} />}</SubscribeField>)}
              </div>
              <h3 className="mb-4 font-serif text-[22px] font-semibold">Payment method</h3>
              {billingConfig.available ? <PaymentFields billing={billingConfig} ref={payment} /> : <p role="status" className="mb-5 rounded-xl border border-border bg-background p-4 text-sm text-muted-foreground">Payments are temporarily unavailable. Your account is saved; you can return here to finish subscribing.</p>}
              <label className="mb-5 flex items-start gap-2.5 text-sm text-muted-foreground"><input type="checkbox" checked={paymentAccepted} onChange={event => { setPaymentAccepted(event.target.checked); setTermsError(null); }} className="mt-0.5 size-[18px] shrink-0 accent-[var(--brand)]" /><span>I agree to the <Link href="/terms-of-service" target="_blank" className="underline">Terms of Service</Link> and <Link href="/privacy-policy" target="_blank" className="underline">Privacy Policy</Link>, and authorize recurring charges until I cancel.</span></label>
              {termsError ? <p role="alert" className="mb-4 text-sm text-flame">{termsError}</p> : null}
              <SubscribeButton type="submit" disabled={busy || !billingConfig.available || !current || (uncertain && !cart)} className="w-full">{busy ? "Processing…" : uncertain ? "Retry same checkout" : "Start subscription"}</SubscribeButton>
            </fieldset>
          </form>
          <SubscribeButton type="button" variant="ghost" disabled={busy || uncertain} onClick={() => go(1)} className="mt-5">← Change plan</SubscribeButton>
        </>}
      </SubscribeCard>
      <aside className="lg:sticky lg:top-24"><SubscribeCard>
        {step === 1 ? <><h3 className="font-serif text-[22px] font-semibold">Ready to start?</h3><p className="my-4 text-sm text-muted-foreground">Create your account next, then checkout. Review everything before you pay.</p><SubscribeButton type="button" disabled={busy || uncertain} className="w-full" onClick={() => void continueWithPlan()}>{busy ? "Preparing…" : `Subscribe to ${plan.name} →`}</SubscribeButton>{authStatus === "anonymous" ? <p className="mt-4 text-center text-xs text-muted-foreground">Already have an account? <button type="button" className="font-bold text-brand underline" disabled={busy || uncertain} onClick={() => void continueWithPlan(true)}>Log in</button></p> : null}</> : <OrderSummary plan={plan} />}
      </SubscribeCard></aside>
    </div>
  </>;
}
