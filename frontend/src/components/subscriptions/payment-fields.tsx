"use client";

import Script from "next/script";
import { useEffect, useImperativeHandle, useRef, useState, type Ref } from "react";
import { SubscribeField, SubscribeInput } from "@/components/design-preview/subscribe-fields";
import type { BillingConfiguration, OpaquePayment } from "@/lib/subscriptions/types";

type AcceptResponse = { opaqueData?: OpaquePayment; messages?: { resultCode: string; message?: { text: string }[] } };
type AcceptLibrary = { dispatchData: (data: { authData: { apiLoginID: string; clientKey: string }; cardData: { cardNumber: string; month: string; year: string; cardCode: string } }, callback: (data: AcceptResponse) => void) => void };
declare global { interface Window { Accept?: AcceptLibrary; isReady?: boolean } }
export interface PaymentFieldsHandle { tokenize: () => Promise<OpaquePayment> }

/** Card values exist only in these browser inputs and go directly to Authorize.Net. */
export function PaymentFields({ billing, ref }: { billing: Extract<BillingConfiguration, { available: true }>; ref: Ref<PaymentFieldsHandle> }) {
  const number = useRef<HTMLInputElement>(null);
  const month = useRef<HTMLInputElement>(null);
  const year = useRef<HTMLInputElement>(null);
  const code = useRef<HTMLInputElement>(null);
  const [ready, setReady] = useState(false);
  const [scriptLoaded, setScriptLoaded] = useState(false);
  const [failed, setFailed] = useState(false);
  const [requiresHttps, setRequiresHttps] = useState(false);
  // Accept.js loads AcceptCore.js separately. Its own handshake proves readiness;
  // the outer script's load event alone does not make dispatchData usable.
  useEffect(() => {
    if (!scriptLoaded) return;
    if (window.location.protocol !== "https:") return;
    let finished = false;
    const check = () => {
      if (!finished && window.isReady === true && typeof window.Accept?.dispatchData === "function") {
        finished = true; setReady(true);
      }
    };
    const handshake = () => { queueMicrotask(check); };
    document.body.addEventListener("handshake", handshake);
    const interval = window.setInterval(check, 50);
    const timeout = window.setTimeout(() => {
      if (!finished) { finished = true; setFailed(true); }
      window.clearInterval(interval);
    }, 15000);
    queueMicrotask(check);
    return () => { finished = true; window.clearInterval(interval); window.clearTimeout(timeout); document.body.removeEventListener("handshake", handshake); };
  }, [scriptLoaded]);
  useImperativeHandle(ref, () => ({
    tokenize: () => new Promise<OpaquePayment>((resolve, reject) => {
      if (window.location.protocol !== "https:") { reject(new Error("Open this page using HTTPS to complete payment.")); return; }
      if (!ready || !window.Accept || window.isReady !== true) { reject(new Error("The payment form is not ready. Please reload and try again.")); return; }
      let settled = false;
      const timer = window.setTimeout(() => { settled = true; reject(new Error("The payment provider did not respond. Please try again.")); }, 30000);
      try {
        window.Accept.dispatchData({
          authData: { apiLoginID: billing.apiLoginId, clientKey: billing.publicClientKey },
          cardData: { cardNumber: number.current?.value.replace(/\s/g, "") ?? "", month: month.current?.value ?? "", year: year.current?.value ?? "", cardCode: code.current?.value ?? "" },
        }, response => {
          if (settled) return;
          settled = true;
          window.clearTimeout(timer);
          if (response.messages?.resultCode !== "Ok" || !response.opaqueData?.dataValue) {
            reject(new Error(response.messages?.message?.map(message => message.text).join(" ") || "Check your card details and try again."));
            return;
          }
          for (const input of [number, month, year, code]) if (input.current) input.current.value = "";
          // Extract only the nonce; no card data or provider response is forwarded.
          resolve({ dataDescriptor: response.opaqueData.dataDescriptor, dataValue: response.opaqueData.dataValue });
        });
      } catch { window.clearTimeout(timer); reject(new Error("Unable to contact the payment provider. Please try again.")); }
    }),
  }), [ready, billing]);
  const src = billing.environment === "sandbox" ? "https://jstest.authorize.net/v1/Accept.js" : "https://js.authorize.net/v1/Accept.js";
  return <>
    <Script src={src} charSet="utf-8" onReady={() => { if (window.location.protocol !== "https:") setRequiresHttps(true); else setScriptLoaded(true); }} onError={() => setFailed(true)} />
    {requiresHttps ? <p role="alert" className="mb-4 text-sm text-flame">A secure HTTPS connection is required for payment. Open this page using HTTPS to continue.</p> : failed ? <p role="alert" className="mb-4 text-sm text-flame">The payment form could not load. Please reload to try again.</p> : !ready ? <p role="status" className="mb-4 text-sm text-muted-foreground">Loading secure payment form…</p> : null}
    <SubscribeField label="Card number" required>{({ inputId }) => <SubscribeInput id={inputId} ref={number} autoComplete="cc-number" inputMode="numeric" required maxLength={23} disabled={!ready} />}</SubscribeField>
    <div className="grid grid-cols-3 gap-3">
      <SubscribeField label="Month" required>{({ inputId }) => <SubscribeInput id={inputId} ref={month} autoComplete="cc-exp-month" inputMode="numeric" placeholder="MM" required maxLength={2} disabled={!ready} />}</SubscribeField>
      <SubscribeField label="Year" required>{({ inputId }) => <SubscribeInput id={inputId} ref={year} autoComplete="cc-exp-year" inputMode="numeric" placeholder="YY" required maxLength={2} disabled={!ready} />}</SubscribeField>
      <SubscribeField label="Security code" required>{({ inputId }) => <SubscribeInput id={inputId} ref={code} type="password" autoComplete="cc-csc" inputMode="numeric" required maxLength={4} disabled={!ready} />}</SubscribeField>
    </div>
    <p className="mb-5 text-xs text-muted-foreground">Card details are sent directly to Authorize.Net.</p>
  </>;
}
