import Link from "next/link";
import { PatientSummaryPage } from "@/components/portal/patients/patient-summary/patient-summary-page";
import { SummaryDataProvider } from "@/components/portal/patients/patient-summary/summary-data-provider";
import { getPatientSummary } from "@/lib/patients/summary-data";

export default async function ChiropractorDashboardPage() {
  let snapshot;
  try { snapshot = await getPatientSummary(); }
  catch { return <div role="alert" className="rounded-xl border border-line bg-surface p-6"><h1 className="font-serif text-2xl">Patient summary</h1><p className="mt-3">Unable to load patient data. Please try again.</p><Link href="/chiropractor" className="mt-4 inline-block text-primary underline">Retry</Link></div>; }
  return <SummaryDataProvider initial={snapshot}><PatientSummaryPage options={snapshot} /></SummaryDataProvider>;
}
