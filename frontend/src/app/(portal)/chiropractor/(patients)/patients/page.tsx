import { PatientsPage } from "@/components/portal/patients/patients-page";
import { getPatientsCached } from "@/lib/patients/data";
import type { PatientsSnapshot } from "@/lib/patients/types";

/**
 * `/chiropractor/patients` — the destination the sidebar's "Patients" item has
 * always pointed at.
 *
 * A server component that owns the fetch. The split is deliberate: the counts on
 * three of the four tabs come from the chiropractor's clinic, which means a server
 * fetch and therefore server state, and that belongs here rather than in a
 * component that has to be `"use client"`.
 *
 * Note the route sits in `(patients)`, not `(standard)`. The two groups resolve
 * to the same URLs today apart from the header title, and a page may only live
 * in one of them.
 */
export default async function ChiropractorPatientsPage() {
  // The fetch is inside the try; the JSX is not. React does not render
  // components when the element is constructed, so a try around the returned
  // markup would not catch a render error anyway — it would only look like it
  // did.
  let snapshot: PatientsSnapshot | undefined;
  let failure: string | undefined;

  try {
    snapshot = await getPatientsCached();
  } catch (error) {
    // An unreachable patients service is a failure, not an empty clinic, and it
    // is reported as one. Falling back to the sample rows here would put invented
    // patients in front of a chiropractor the moment the backend breaks.
    failure =
      error instanceof Error ? error.message : "The patients list could not be loaded.";
  }

  if (!snapshot) {
    return (
      <div className="rounded-2xl border border-line bg-surface p-6 shadow-panel">
        <h2 className="font-serif text-lg text-foreground">Patients are unavailable</h2>
        <p className="mt-2 max-w-prose text-muted-foreground text-sm">{failure}</p>
        <p className="mt-4 text-muted-foreground text-xs">
          Try again shortly. If this persists, the clinic data is not reaching the app.
        </p>
      </div>
    );
  }

  return <PatientsPage snapshot={snapshot} />;
}