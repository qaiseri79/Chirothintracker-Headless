"use client";

import { useState, type FormEvent } from "react";
import { Dialog, DialogContent, DialogDescription, DialogTitle } from "@/components/ui/dialog";
import { summaryRequest } from "@/lib/patients/summary-api";
import { PatientRequestError } from "@/lib/patients/api";
import type { PatientSummaryRow } from "@/lib/patients/summary";
import { useSummaryData } from "./summary-data-provider";

export function LogProgressDialog({ patientId, open, onClose }: { patientId: number; open: boolean; onClose: () => void }) {
  const { patients, readOnly, refresh } = useSummaryData();
  const [refreshError, setRefreshError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);
  const patient = patients.find((row) => row.id === patientId);

  function refreshPatient() {
    void refresh(patientId)
      .then(() => setRefreshError(null))
      .catch(() => setRefreshError("Progress saved. Unable to refresh the list."));
  }

  function saved() {
    onClose();
    setRefreshError(null);
    refreshPatient();
  }

  return (
    <>
      {refreshError && <p role="alert" className="mt-2 text-xs text-destructive">{refreshError} <button type="button" className="underline" onClick={refreshPatient}>Refresh</button></p>}
      <Dialog open={open && !readOnly && !!patient} onOpenChange={(next) => { if (!next && !saving) onClose(); }}>
        <DialogContent style={{ fontFamily: "var(--font-inter), system-ui, sans-serif" }} showCloseButton={false} overlayClassName="bg-[#101827]/40" className="font-sans max-h-[90vh] w-[calc(100vw-2rem)] max-w-md gap-0 overflow-y-auto rounded-[16px] border-0 bg-surface p-6 shadow-xl sm:rounded-[16px]">
          <DialogTitle className="font-serif text-lg font-normal">Log progress — {patient?.name}</DialogTitle>
          <DialogDescription className="sr-only">Record daily weight, water intake, and optional clinical readings for this patient.</DialogDescription>
          {patient && <ProgressLogForm patient={patient} onCancel={onClose} onSaved={saved} onSavingChange={setSaving} />}
        </DialogContent>
      </Dialog>
    </>
  );
}

const FIELDS = [
  { name: "field_date", label: "Date", type: "date", required: true },
  { name: "field_weight", label: "Today's weight", type: "number", required: true, unit: "lbs", step: "0.01" },
  { name: "field_water_intake", label: "Yesterday's water intake", type: "number", required: true, unit: "oz", step: "1" },
  { name: "field_blood_sugar", label: "Morning blood sugar", type: "number", unit: "mg/dL", step: "1" },
  { name: "field_blood_pressure", label: "Blood pressure", type: "text", placeholder: "120/80" },
] as const;

/** The compact v9 doctor form; persistence/calculations use the shared writer. */
function ProgressLogForm({ patient, onCancel, onSaved, onSavingChange }: {
  patient: PatientSummaryRow;
  onCancel: () => void;
  onSaved: () => void;
  onSavingChange: (saving: boolean) => void;
}) {
  const today = new Date().toISOString().slice(0, 10);
  const [values, setValues] = useState<Record<string, string>>({ field_date: today });
  const [error, setError] = useState<string | null>(null);
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});
  const [confirmation, setConfirmation] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);
  const last = patient.logsList[0];
  const weight = Number(values.field_weight);
  const delta = last && values.field_weight ? weight - last.weight : null;

  function change(name: string, value: string) {
    setValues((current) => ({ ...current, [name]: value }));
    setError(null); setFieldErrors({}); setConfirmation(null);
  }

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (saving) return;
    const fail = (field: string, message: string) => { setError(message); setFieldErrors({ [field]: message }); };
    const date = values.field_date;
    if (!date) return fail("field_date", "Pick a date.");
    if (date > today) return fail("field_date", "The date can't be in the future.");
    if (!values.field_weight || !Number.isFinite(weight) || weight <= 0) return fail("field_weight", "Enter today's weight.");
    const water = Number(values.field_water_intake);
    if (!values.field_water_intake || !Number.isFinite(water) || water < 0) return fail("field_water_intake", "Enter yesterday's water intake (0 or more).");
    const sugar = values.field_blood_sugar;
    if (sugar && (!Number.isFinite(Number(sugar)) || Number(sugar) <= 0)) return fail("field_blood_sugar", "Blood sugar must be a positive number.");
    const pressure = values.field_blood_pressure?.trim() ?? "";
    if (pressure && !/^\d{2,3}\/\d{2,3}$/.test(pressure)) return fail("field_blood_pressure", "Blood pressure should look like 120/80.");
    const displayDate = `${date.slice(5, 7)}/${date.slice(8)}/${date.slice(0, 4)}`;
    if (patient.logsList.some((log) => log.date === displayDate)) return fail("field_date", `A log already exists for ${displayDate}.`);
    const fields = { field_date: date, field_weight: weight, field_water_intake: water, ...(sugar ? { field_blood_sugar: Number(sugar) } : {}), ...(pressure ? { field_blood_pressure: pressure } : {}) };
    const signature = JSON.stringify(fields);
    if (last && Math.abs(weight - last.weight) > 15 && confirmation !== signature) {
      setConfirmation(signature);
      return fail("field_weight", `That is ${Math.abs(weight - last.weight).toFixed(0)} lbs from the last log. Press Save again to confirm.`);
    }
    setSaving(true); onSavingChange(true); setError(null); setFieldErrors({});
    try {
      await summaryRequest(patient.id, "/progress", { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ fields }) });
      onSaved();
    } catch (failure) {
      setError(failure instanceof Error ? failure.message : "Unable to save progress. Please try again.");
      if (failure instanceof PatientRequestError) setFieldErrors(failure.fields);
    } finally { setSaving(false); onSavingChange(false); }
  }

  return (
    <form noValidate onSubmit={submit} className="mt-4 text-sm">
      <fieldset disabled={saving} className="space-y-3">
        {FIELDS.map((field) => {
          const id = `progress-${patient.id}-${field.name}`;
          const unit = "unit" in field ? field.unit : undefined;
          return <div key={field.name}>
            <label htmlFor={id} className="block text-xs font-semibold uppercase tracking-[0.05em] text-muted-foreground">{field.label}{"required" in field && <span className="text-red-700"> *</span>}</label>
            <div className={`mt-1 flex ${field.type === "date" ? "sm:w-48" : ""}`}>
              <input id={id} name={field.name} type={field.type} value={values[field.name] ?? ""} onChange={(event) => change(field.name, event.target.value)} required={"required" in field} max={field.type === "date" ? today : undefined} min={field.type === "number" ? "0" : undefined} step={"step" in field ? field.step : undefined} placeholder={"placeholder" in field ? field.placeholder : undefined} aria-invalid={!!fieldErrors[field.name]} className={`min-w-0 w-full rounded-[8px] border border-line bg-white px-3 py-2 text-sm focus:border-primary focus:outline-none ${unit ? "rounded-r-none" : ""}`} />
              {unit && <span className="flex items-center rounded-r-[8px] border border-l-0 border-line bg-canvas px-3 text-sm text-muted-foreground">{unit}</span>}
            </div>
            {field.name === "field_weight" && last && <p className="mt-2 text-xs text-muted-foreground">Last log: {last.weight.toFixed(2)} lbs{delta !== null && Number.isFinite(delta) ? ` · ${delta < 0 ? "▼" : delta > 0 ? "▲" : "•"} ${Math.abs(delta).toFixed(2)} lbs` : ""}</p>}
          </div>;
        })}
        {error && <p role="alert" className="rounded-[8px] bg-red-50 px-3 py-2 text-sm text-red-700">{error}</p>}
      </fieldset>
      <div className="mt-5 flex justify-end gap-2">
        <button type="button" onClick={onCancel} disabled={saving} className="rounded-full border border-line px-4 py-2 text-sm disabled:opacity-50">Cancel</button>
        <button type="submit" disabled={saving} className="rounded-full bg-primary px-5 py-2 text-sm font-semibold text-white hover:bg-brand-dark disabled:opacity-50">{saving ? "Saving…" : "Save log"}</button>
      </div>
    </form>
  );
}
