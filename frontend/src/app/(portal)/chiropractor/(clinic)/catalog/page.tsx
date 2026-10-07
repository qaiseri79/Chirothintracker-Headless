"use client";

import { useEffect, useState } from "react";
import { Button } from "@/components/ui/button";
import { commerceApi } from "@/lib/commerce/client";
import type { CatalogItem, CustomProduct } from "@/lib/commerce/types";
import { ShoppingBag, Edit, Plus, Trash2 } from "lucide-react";

function Panel({ title, children }: { title: string; children: React.ReactNode }) { return <section className="rounded-xl border border-border bg-surface p-6 shadow-sm"><h2 className="mb-4 text-xl font-bold tracking-tight text-foreground">{title}</h2>{children}</section>; }

export default function CatalogPage() {
  const [catalog, setCatalog] = useState<CatalogItem[]>([]);
  const [customProducts, setCustomProducts] = useState<CustomProduct[]>([]);
  const [busy, setBusy] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const [newTitle, setNewTitle] = useState("");
  const [newSku, setNewSku] = useState("");
  const [newPrice, setNewPrice] = useState("");

  async function loadData() {
    setBusy(true); setError(null);
    try {
      const [catRes, custRes] = await Promise.all([
        commerceApi.getCatalog(),
        commerceApi.getCustomProducts()
      ]);
      setCatalog(catRes.catalog);
      setCustomProducts(custRes.products);
    } catch (err) {
      setError(err instanceof Error ? err.message : "Failed to load catalog data.");
    } finally {
      setBusy(false);
    }
  }

  useEffect(() => {
    void loadData();
  }, []);

  async function updateOverride(variationId: number, priceStr: string, isEnabled: boolean) {
    const priceMinor = priceStr ? Math.round(parseFloat(priceStr) * 100) : null;
    try {
      await commerceApi.saveCatalogOverride(variationId, priceMinor, isEnabled);
      await loadData();
    } catch (err) {
      alert(err instanceof Error ? err.message : "Failed to update override.");
    }
  }

  async function addCustomProduct(e: React.FormEvent) {
    e.preventDefault();
    const priceMinor = newPrice ? Math.round(parseFloat(newPrice) * 100) : 0;
    try {
      await commerceApi.createCustomProduct(newTitle, newSku, priceMinor);
      setNewTitle(""); setNewSku(""); setNewPrice("");
      await loadData();
    } catch (err) {
      alert(err instanceof Error ? err.message : "Failed to create product.");
    }
  }

  async function deleteCustomProduct(id: number) {
    if (!confirm("Are you sure you want to delete this custom product?")) return;
    try {
      await commerceApi.deleteCustomProduct(id);
      await loadData();
    } catch (err) {
      alert(err instanceof Error ? err.message : "Failed to delete product.");
    }
  }

  return (
    <div className="max-w-5xl space-y-6 pb-20">
      <div>
        <h1 className="text-2xl font-bold tracking-tight text-brand-dark flex items-center gap-2"><ShoppingBag className="size-6" /> Store Catalog</h1>
        <p className="mt-2 text-muted-foreground">Manage your custom products and price overrides for shared platform products.</p>
      </div>

      {error && <div className="rounded-lg bg-red-50 p-4 text-red-800 text-sm">{error}</div>}

      <Panel title="Shared Platform Products">
        <p className="text-sm text-muted-foreground mb-4">Set your own custom prices for standard platform products. Leave the override price blank to use the default platform price.</p>
        <div className="overflow-x-auto">
          <table className="w-full text-sm text-left">
            <thead className="bg-surface text-muted-foreground border-b border-border">
              <tr>
                <th className="px-4 py-3 font-medium">Product Title</th>
                <th className="px-4 py-3 font-medium">SKU</th>
                <th className="px-4 py-3 font-medium">Base Price</th>
                <th className="px-4 py-3 font-medium">Your Price</th>
                <th className="px-4 py-3 font-medium text-right">Action</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-border">
              {busy && catalog.length === 0 ? <tr><td colSpan={5} className="px-4 py-4 text-center">Loading...</td></tr> : null}
              {catalog.map(item => (
                <tr key={item.id} className="group">
                  <td className="px-4 py-3 font-medium">{item.title}</td>
                  <td className="px-4 py-3 text-muted-foreground">{item.sku}</td>
                  <td className="px-4 py-3">${(item.basePriceMinor / 100).toFixed(2)}</td>
                  <td className="px-4 py-3">
                    <input
                      type="number"
                      step="0.01"
                      placeholder="Default"
                      className="w-24 rounded border border-border px-2 py-1 text-sm"
                      defaultValue={item.override?.priceMinor != null ? (item.override.priceMinor / 100).toFixed(2) : ""}
                      onBlur={(e) => updateOverride(item.id, e.target.value, item.override?.status ?? true)}
                    />
                  </td>
                  <td className="px-4 py-3 text-right">
                    <Button variant="ghost" size="sm" onClick={() => updateOverride(item.id, item.override?.priceMinor != null ? (item.override.priceMinor / 100).toFixed(2) : "", !(item.override?.status ?? true))}>
                      {item.override?.status === false ? "Enable" : "Disable"}
                    </Button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </Panel>

      <Panel title="Your Custom Products">
        <p className="text-sm text-muted-foreground mb-4">Add your own clinic-specific products that only your patients can see.</p>

        <form onSubmit={addCustomProduct} className="flex flex-wrap items-end gap-3 mb-6 bg-surface p-4 rounded-xl border border-border">
          <div className="flex-1 min-w-[200px]">
            <label className="block text-xs font-medium mb-1">Product Title</label>
            <input type="text" value={newTitle} onChange={e => setNewTitle(e.target.value)} required disabled={busy} className="w-full rounded-lg border border-border px-3 py-2 text-sm" />
          </div>
          <div className="w-32">
            <label className="block text-xs font-medium mb-1">SKU</label>
            <input type="text" value={newSku} onChange={e => setNewSku(e.target.value)} required disabled={busy} className="w-full rounded-lg border border-border px-3 py-2 text-sm" />
          </div>
          <div className="w-32">
            <label className="block text-xs font-medium mb-1">Price ($)</label>
            <input type="number" step="0.01" value={newPrice} onChange={e => setNewPrice(e.target.value)} required disabled={busy} className="w-full rounded-lg border border-border px-3 py-2 text-sm" />
          </div>
          <Button type="submit" disabled={busy}><Plus className="size-4 mr-1" /> Add</Button>
        </form>

        <div className="overflow-x-auto">
          <table className="w-full text-sm text-left">
            <thead className="bg-surface text-muted-foreground border-b border-border">
              <tr>
                <th className="px-4 py-3 font-medium">Product Title</th>
                <th className="px-4 py-3 font-medium">SKU</th>
                <th className="px-4 py-3 font-medium">Price</th>
                <th className="px-4 py-3 font-medium text-right">Actions</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-border">
              {customProducts.length === 0 ? <tr><td colSpan={4} className="px-4 py-4 text-center text-muted-foreground">No custom products found.</td></tr> : null}
              {customProducts.map(item => (
                <tr key={item.id} className="group">
                  <td className="px-4 py-3 font-medium">{item.title}</td>
                  <td className="px-4 py-3 text-muted-foreground">{item.sku}</td>
                  <td className="px-4 py-3">${(item.priceMinor / 100).toFixed(2)}</td>
                  <td className="px-4 py-3 text-right">
                    <Button variant="ghost" size="sm" onClick={() => deleteCustomProduct(item.id)} className="text-red-600 hover:text-red-700 hover:bg-red-50">
                      <Trash2 className="size-4" />
                    </Button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </Panel>
    </div>
  );
}
