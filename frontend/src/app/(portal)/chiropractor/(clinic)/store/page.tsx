"use client";

import { useState } from "react";
import { Button } from "@/components/ui/button";
import { commerceApi } from "@/lib/commerce/client";
import { Store, CreditCard, Truck } from "lucide-react";

function Panel({ title, children }: { title: string; children: React.ReactNode }) { return <section className="rounded-xl border border-border bg-surface p-6 shadow-sm"><h2 className="mb-4 text-xl font-bold tracking-tight text-foreground">{title}</h2>{children}</section>; }

export default function StoreSettingsPage() {
  const [apiLoginId, setApiLoginId] = useState("");
  const [transactionKey, setTransactionKey] = useState("");
  const [publicClientKey, setPublicClientKey] = useState("");
  const [fulfillmentType, setFulfillmentType] = useState<"shipping" | "pickup" | "both">("both");

  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);

  async function savePayment(e: React.FormEvent) {
    e.preventDefault();
    setBusy(true); setMessage(null); setError(null);
    try {
      await commerceApi.savePaymentSettings({ apiLoginId, transactionKey, publicClientKey });
      setMessage("Payment gateway settings securely saved.");
    } catch (err) {
      setError(err instanceof Error ? err.message : "Unable to save payment settings.");
    } finally {
      setBusy(false);
    }
  }

  async function saveFulfillment(e: React.FormEvent) {
    e.preventDefault();
    setBusy(true); setMessage(null); setError(null);
    try {
      await commerceApi.saveFulfillmentSettings(fulfillmentType);
      setMessage("Fulfillment preferences updated.");
    } catch (err) {
      setError(err instanceof Error ? err.message : "Unable to save fulfillment settings.");
    } finally {
      setBusy(false);
    }
  }

  return (
    <div className="max-w-4xl space-y-6 pb-20">
      <div>
        <h1 className="text-2xl font-bold tracking-tight text-brand-dark flex items-center gap-2"><Store className="size-6" /> Store Settings</h1>
        <p className="mt-2 text-muted-foreground">Manage your clinic's isolated payment gateway and shipping preferences.</p>
      </div>

      {message && <div className="rounded-lg bg-green-50 p-4 text-green-800 text-sm">{message}</div>}
      {error && <div className="rounded-lg bg-red-50 p-4 text-red-800 text-sm">{error}</div>}

      <Panel title="Authorize.Net Gateway Credentials">
        <p className="text-sm text-muted-foreground mb-4">Enter your Authorize.Net API credentials. These keys securely bind patient checkouts directly to your merchant account.</p>
        <form onSubmit={savePayment} className="space-y-4">
          <div>
            <label className="block text-sm font-medium mb-1">API Login ID</label>
            <input type="text" value={apiLoginId} onChange={e => setApiLoginId(e.target.value)} required disabled={busy} className="w-full rounded-lg border border-border px-3 py-2" />
          </div>
          <div>
            <label className="block text-sm font-medium mb-1">Transaction Key</label>
            <input type="password" value={transactionKey} onChange={e => setTransactionKey(e.target.value)} required disabled={busy} className="w-full rounded-lg border border-border px-3 py-2" />
          </div>
          <div>
            <label className="block text-sm font-medium mb-1">Public Client Key</label>
            <input type="text" value={publicClientKey} onChange={e => setPublicClientKey(e.target.value)} disabled={busy} className="w-full rounded-lg border border-border px-3 py-2" />
            <p className="text-xs text-muted-foreground mt-1">Required for secure Accept.js tokenization.</p>
          </div>
          <Button type="submit" disabled={busy} className="mt-4"><CreditCard className="size-4 mr-2" /> Save Gateway Credentials</Button>
        </form>
      </Panel>

      <Panel title="Fulfillment Options">
        <p className="text-sm text-muted-foreground mb-4">Select how patients can receive their orders from your store.</p>
        <form onSubmit={saveFulfillment} className="space-y-4">
          <div>
            <label className="block text-sm font-medium mb-1">Fulfillment Type</label>
            <select value={fulfillmentType} onChange={e => setFulfillmentType(e.target.value as any)} disabled={busy} className="w-full rounded-lg border border-border px-3 py-2 bg-white">
              <option value="shipping">Shipping Only</option>
              <option value="pickup">Pickup at Clinic Only</option>
              <option value="both">Both (Shipping & Pickup)</option>
            </select>
          </div>
          <Button type="submit" disabled={busy} className="mt-4"><Truck className="size-4 mr-2" /> Save Fulfillment</Button>
        </form>
      </Panel>
    </div>
  );
}
