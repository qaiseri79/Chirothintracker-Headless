import { PatientAuthError, PatientRequestError } from "./api";

export async function summaryRequest<T>(id: number, suffix = "", options: RequestInit = {}): Promise<T> {
  const response = await fetch(`/api/patients/${id}/summary${suffix}`, { cache: "no-store", ...options });
  const body = await response.json().catch(() => null);
  if (response.status === 401) throw new PatientAuthError("Your session has expired. Please sign in again.", {}, 401);
  if (!response.ok) {
    const fields: Record<string, string> = body?.errors && typeof body.errors === "object" ? body.errors : {};
    for (const issue of body?.issues ?? []) {
      if (typeof issue.field === "string" && typeof issue.message === "string") fields[issue.field] = issue.message;
    }
    throw new PatientRequestError(Object.values(fields)[0] || body?.error || "Unable to update patient details.", fields, response.status);
  }
  return body as T;
}
