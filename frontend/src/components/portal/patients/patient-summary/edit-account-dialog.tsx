"use client";

import { useState } from "react";
import { Dialog, DialogContent, DialogDescription, DialogTitle } from "@/components/ui/dialog";
import { BTN_CANCEL, BTN_SUBMIT, FORM_ERROR, LABEL } from "../../content-forms/content-form-primitives";
import type { PatientSummaryRow } from "@/lib/patients/summary";
import { useSummaryData } from "./summary-data-provider";

/** `MM/DD/YYYY` (how the roster renders dates) to the date input's `YYYY-MM-DD`. */
function toIso(display: string): string {
  return display ? `${display.slice(6)}-${display.slice(0, 2)}-${display.slice(3, 5)}` : "";
}

/**
 * The design's `editAcct(p)` modal (`New Design/chirothin-patient-summary-v9.html`)
 * — "Edit account": the patient's identity and program fields, saved through the
 * summary's `account` action. The form sends the whole account, and the backend
 * preserves whatever a blank box left alone except the clinic location, where
 * "- None -" is an explicit clear.
 */
export function EditAccountDialog({ patient, locations, statuses, onClose, showToast }: {
  patient: PatientSummaryRow;
  locations: Array<{ id: number; name: string }>;
  statuses: Array<{ id: number; name: string }>;
  onClose: () => void;
  showToast?: (message: string) => void;
}) {
  const { write } = useSummaryData();
  const [firstName, setFirstName] = useState(() => patient.name.split(" ")[0] ?? "");
  const [lastName, setLastName] = useState(() => patient.name.split(" ").slice(1).join(" "));
  const [email, setEmail] = useState(patient.email);
  const [startWeight, setStartWeight] = useState(patient.startWeight != null ? String(patient.startWeight) : "");
  const [goalWeight, setGoalWeight] = useState(patient.goalWeight != null ? String(patient.goalWeight) : "");
  const [clinicLocation, setClinicLocation] = useState(() => locations.find((location) => location.name === patient.clinic)?.id ?? "");
  const [programStart, setProgramStart] = useState(() => toIso(patient.startDate));
  const [laserStatus, setLaserStatus] = useState(() => statuses.find((status) => status.name === patient.program)?.id ?? "");
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function save() {
    setSaving(true);
    setError(null);
    try {
      await write(patient.id, "", {
        action: "account",
        firstName,
        lastName,
        email,
        startWeight,
        goalWeight,
        clinicLocation,
        programStart,
        laserStatus,
      });
      showToast?.("Account updated");
      onClose();
    } catch (failure) {
      setError(failure instanceof Error ? failure.message : "Unable to save. Please try again.");
      setSaving(false);
    }
  }

  return (
    <Dialog open onOpenChange={(next) => { if (!next && !saving) onClose(); }}>
      <DialogContent showCloseButton={false} overlayClassName="bg-[#101827]/40" className="font-sans max-h-[90vh] w-[calc(100vw-2rem)] max-w-xl gap-0 overflow-y-auto rounded-[16px] border-0 bg-surface p-6 shadow-xl sm:rounded-[16px]">
        <DialogTitle className="font-serif text-lg font-normal">Edit account — {patient.name}</DialogTitle>
        <DialogDescription className="sr-only">Update the patient&apos;s name, email and program details.</DialogDescription>
        <div className="mt-4 grid grid-cols-2 gap-3">
          <p className="col-span-2 border-b border-line pb-1 text-[12px] font-semibold uppercase tracking-[0.05em] text-[#6B7280]">Patient</p>
          <div>
            <label htmlFor={`account-first-${patient.id}`} className={LABEL}>First name</label>
            <input id={`account-first-${patient.id}`} value={firstName} onChange={(event) => setFirstName(event.target.value)} disabled={saving} className="w-full rounded-xl border border-line bg-surface px-3.5 py-2.5 text-sm text-foreground transition-[border-color,box-shadow] placeholder:text-muted-foreground/70 focus:border-primary focus:ring-4 focus:ring-primary-soft focus:outline-none" />
          </div>
          <div>
            <label htmlFor={`account-last-${patient.id}`} className={LABEL}>Last name</label>
            <input id={`account-last-${patient.id}`} value={lastName} onChange={(event) => setLastName(event.target.value)} disabled={saving} className="w-full rounded-xl border border-line bg-surface px-3.5 py-2.5 text-sm text-foreground transition-[border-color,box-shadow] placeholder:text-muted-foreground/70 focus:border-primary focus:ring-4 focus:ring-primary-soft focus:outline-none" />
          </div>
          <div className="col-span-2">
            <label htmlFor={`account-email-${patient.id}`} className={LABEL}>Email</label>
            <input id={`account-email-${patient.id}`} type="email" value={email} onChange={(event) => setEmail(event.target.value)} disabled={saving} className="w-full rounded-xl border border-line bg-surface px-3.5 py-2.5 text-sm text-foreground transition-[border-color,box-shadow] placeholder:text-muted-foreground/70 focus:border-primary focus:ring-4 focus:ring-primary-soft focus:outline-none" />
          </div>
          <p className="col-span-2 mt-1 border-b border-line pb-1 text-[12px] font-semibold uppercase tracking-[0.05em] text-[#6B7280]">Program</p>
          <div>
            <label htmlFor={`account-start-weight-${patient.id}`} className={LABEL}>Start weight</label>
            <div className="flex">
              <input id={`account-start-weight-${patient.id}`} type="number" step="0.01" min="0" value={startWeight} onChange={(event) => setStartWeight(event.target.value)} disabled={saving} className="w-full rounded-r-none rounded-xl border border-line bg-surface px-3.5 py-2.5 text-sm text-foreground transition-[border-color,box-shadow] placeholder:text-muted-foreground/70 focus:border-primary focus:ring-4 focus:ring-primary-soft focus:outline-none" />
              <span className="flex items-center rounded-r-xl border border-l-0 border-line bg-canvas px-3 text-sm text-[#6B7280]">lbs</span>
            </div>
          </div>
          <div>
            <label htmlFor={`account-goal-weight-${patient.id}`} className={LABEL}>Goal weight</label>
            <div className="flex">
              <input id={`account-goal-weight-${patient.id}`} type="number" step="0.01" min="0" value={goalWeight} onChange={(event) => setGoalWeight(event.target.value)} disabled={saving} className="w-full rounded-r-none rounded-xl border border-line bg-surface px-3.5 py-2.5 text-sm text-foreground transition-[border-color,box-shadow] placeholder:text-muted-foreground/70 focus:border-primary focus:ring-4 focus:ring-primary-soft focus:outline-none" />
              <span className="flex items-center rounded-r-xl border border-l-0 border-line bg-canvas px-3 text-sm text-[#6B7280]">lbs</span>
            </div>
          </div>
          <div>
            <label htmlFor={`account-clinic-${patient.id}`} className={LABEL}>Clinic location</label>
            <select id={`account-clinic-${patient.id}`} value={clinicLocation} onChange={(event) => setClinicLocation(event.target.value)} disabled={saving} className="w-full rounded-xl border border-line bg-surface px-3.5 py-2.5 text-sm text-foreground focus:border-primary focus:ring-4 focus:ring-primary-soft focus:outline-none">
              <option value="" key="none">- None -</option>
              {locations.map((location) => <option value={location.id} key={location.id}>{location.name}</option>)}
            </select>
          </div>
          <div>
            <label htmlFor={`account-start-date-${patient.id}`} className={LABEL}>Program start date</label>
            <input id={`account-start-date-${patient.id}`} type="date" value={programStart} onChange={(event) => setProgramStart(event.target.value)} disabled={saving} className="w-full rounded-xl border border-line bg-surface px-3.5 py-2.5 text-sm text-foreground focus:border-primary focus:ring-4 focus:ring-primary-soft focus:outline-none" />
          </div>
          <div className="col-span-2">
            <label htmlFor={`account-status-${patient.id}`} className={LABEL}>Patient status <span className="text-flame">*</span></label>
            <select id={`account-status-${patient.id}`} value={laserStatus} onChange={(event) => setLaserStatus(event.target.value)} disabled={saving} className="w-full rounded-xl border border-line bg-surface px-3.5 py-2.5 text-sm text-foreground focus:border-primary focus:ring-4 focus:ring-primary-soft focus:outline-none">
              {statuses.map((status) => <option value={status.id} key={status.id}>{status.name}</option>)}
            </select>
          </div>
        </div>
        {error ? <p role="alert" className={FORM_ERROR}>{error}</p> : null}
        <div className="mt-6 flex items-center justify-end gap-3 border-t border-line pt-5">
          <button type="button" onClick={onClose} disabled={saving} className={BTN_CANCEL}>Cancel</button>
          <button type="button" onClick={() => void save()} disabled={saving} className={BTN_SUBMIT}>{saving ? "Saving…" : "Save changes"}</button>
        </div>
      </DialogContent>
    </Dialog>
  );
}