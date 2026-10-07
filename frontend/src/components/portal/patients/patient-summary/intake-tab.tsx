import type { PatientSummaryRow } from "@/lib/patients/summary";
import { IntakeSubmissionContent } from "../intake-view";
export function IntakeTab({ intake }: { intake: PatientSummaryRow["intake"]; name?: string; email?: string }) {
  if (!intake) return <p className="text-sm text-muted-foreground">No linked intake submission.</p>;
  return <IntakeSubmissionContent submission={intake} />;
}
