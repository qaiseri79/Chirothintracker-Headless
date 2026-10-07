"use client";
import { useState } from "react";
import { ChevronLeft } from "lucide-react";
import { useSummaryData } from "./summary-data-provider";
import type { SessionField } from "@/lib/patients/summary";

const INPUT = "w-full rounded-lg border border-line bg-white px-3 py-2 text-sm focus:border-primary focus:outline-none";
export function SessionForm({ patientId, profileId, sessionType, remainingCount, onBack, onSave }: {
  patientId: number; profileId: number; sessionType: string; remainingCount: number; onBack: () => void; onSave: () => void;
}) {
  const { patients, write, pending, readOnly } = useSummaryData();
  const patient = patients.find((row) => row.id === patientId);
  const session = patient?.sessions.find((row) => row.id === profileId);
  const fields = patient?.sessionSchemas?.[session?.bundle ?? ""] ?? [];
  const [error, setError] = useState<string | null>(null);
  async function submit(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault(); setError(null);
    const form = new FormData(event.currentTarget);
    const values: Record<string, unknown> = {};
    for (const field of fields) {
      const raw = form.get(field.name);
      if (field.type === "boolean") values[field.name] = raw === "on";
      else if (field.type === "entity_reference") {
        const selected = form.getAll(field.name).filter((value) => value !== "").map(Number);
        if (selected.length) values[field.name] = field.multiple ? selected : selected[0];
      } else if (typeof raw === "string" && raw !== "") values[field.name] = ["integer", "decimal", "float"].includes(field.type) ? Number(raw) : raw;
    }
    const payload = new FormData(); payload.append("payload", JSON.stringify({ profileId, fields: values }));
    for (const photo of form.getAll("photos")) { if (photo instanceof File && photo.size) payload.append("photos[]", photo); }
    try { await write(patientId, "/sessions", payload, "sessions"); onSave(); }
    catch (cause) { setError(cause instanceof Error ? cause.message : "Unable to save session."); }
  }
  const measurementFields = fields.filter((field) => ["integer", "decimal", "float"].includes(field.type) && field.name !== "field_weight");
  const treatmentFields = fields.filter((field) => ["entity_reference", "boolean"].includes(field.type));
  const textFields = fields.filter((field) => ["string", "string_long"].includes(field.type) && field.name !== "field_blood_pressure");
  return <form onSubmit={submit}>
    <button type="button" onClick={onBack} className="mb-4 inline-flex items-center gap-1 text-sm font-semibold text-muted-foreground"><ChevronLeft className="size-4" />Back to {sessionType}</button>
    <h3 className="font-serif text-2xl">Add {sessionType} session</h3>
    <p className="text-sm text-muted-foreground">Uses 1 of {remainingCount} remaining sessions.</p>
    {error && <p role="alert" className="mt-4 text-sm text-destructive">{error}</p>}
    <fieldset disabled={readOnly || pending[patientId]} className="space-y-5">
      <section className="mt-5 rounded-2xl border border-line bg-white p-5"><h4 className="text-xs font-semibold uppercase text-muted-foreground">Vitals</h4><div className="mt-3 grid gap-3 sm:grid-cols-3">{fields.filter((field) => ["field_date", "field_weight", "field_blood_pressure"].includes(field.name)).map((field) => <SessionInput key={field.name} field={field} />)}</div></section>
      {treatmentFields.length > 0 && <section className="rounded-2xl border border-line bg-white p-5"><h4 className="text-xs font-semibold uppercase text-muted-foreground">Treatment</h4><div className="mt-3 space-y-4">{treatmentFields.map((field) => <SessionInput key={field.name} field={field} />)}</div></section>}
      <section className="rounded-2xl border border-line bg-white p-5"><h4 className="text-xs font-semibold uppercase text-muted-foreground">Notes and photos</h4><div className="mt-3 space-y-3">{textFields.map((field) => <SessionInput key={field.name} field={field} />)}<label className="block text-sm">Before and after photos<input type="file" name="photos" multiple accept="image/png,image/jpeg,image/gif" className="mt-2 block w-full" /><span className="mt-1 block text-xs text-muted-foreground">Up to 10 photos, 20 MB each and 100 MB total.</span></label></div></section>
      <section className="rounded-2xl border border-line bg-white p-5"><h4 className="text-xs font-semibold uppercase text-muted-foreground">Body measurements</h4><div className="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">{measurementFields.map((field) => <SessionInput key={field.name} field={field} />)}</div></section>
      <div className="flex items-center justify-between border-t border-line pt-4"><p className="text-sm text-muted-foreground">After saving: <b>{Math.max(0, remainingCount - 1)} left</b></p><button type="submit" disabled={remainingCount < 1 || !fields.length} className="rounded-full bg-primary px-6 py-2 text-sm font-semibold text-white">{pending[patientId] ? "Saving…" : "Save session"}</button></div>
    </fieldset>
  </form>;
}
function SessionInput({ field }: { field: SessionField }) {
  const id = `session-${field.name}`;
  if (field.type === "entity_reference") return <fieldset><legend className="text-sm font-medium">{field.label}</legend><div className="mt-2 flex flex-wrap gap-2">{field.options.map((option) => <label key={option.id} className="flex items-center gap-1.5 rounded-lg border border-line px-3 py-2 text-sm"><input type={field.multiple ? "checkbox" : "radio"} name={field.name} value={option.id} />{option.label}</label>)}</div></fieldset>;
  if (field.type === "boolean") return <label className="flex items-center gap-2 text-sm"><input type="checkbox" name={field.name} />{field.label}</label>;
  const numeric = ["decimal", "float", "integer"].includes(field.type);
  return <div><label htmlFor={id} className="mb-1 block text-sm font-medium">{field.label}</label>{field.type === "string_long" ? <textarea id={id} name={field.name} rows={3} className={INPUT} required={field.required} /> : <input id={id} name={field.name} type={field.type === "datetime" ? "date" : numeric ? "number" : "text"} step={numeric ? "any" : undefined} min={numeric ? 0 : undefined} defaultValue={field.name === "field_date" ? new Date().toLocaleDateString("en-CA") : undefined} className={INPUT} required={field.required} />}</div>;
}
