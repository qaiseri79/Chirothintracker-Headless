import "server-only";
import { drupalFetch } from "@/lib/drupal/client";
import { PatientsUnavailableError } from "./data";
import type { PatientSummarySnapshot } from "./summary";

export async function getPatientSummary(): Promise<PatientSummarySnapshot> {
  try {
    const response = await drupalFetch("/api/headless/patients/summary");
    if (!response.ok) throw new PatientsUnavailableError("Unable to load the patient summary.", response.status);
    const data = await response.json() as PatientSummarySnapshot;
    if (!Array.isArray(data.patients) || !Array.isArray(data.phases) || !Array.isArray(data.locations) || !Array.isArray(data.statuses)) throw new Error("Invalid summary response");
    return data;
  } catch (error) {
    if (error instanceof PatientsUnavailableError) throw error;
    throw new PatientsUnavailableError("Unable to load the patient summary.", 0, { cause: error });
  }
}
