import type { ClinicSnapshot } from "./types";

export class ClinicRequestError extends Error {
  constructor(message: string, readonly status: number, readonly fields: Record<string, string> = {}) {
    super(message);
    this.name = "ClinicRequestError";
  }
}
export async function clinicRequest(path = "", body?: unknown, method = "POST"): Promise<ClinicSnapshot> {
  const response = await fetch("/api/clinic" + (path ? "/" + path : ""), {
    method: body === undefined ? "GET" : method,
    credentials: "same-origin",
    cache: "no-store",
    ...(body === undefined ? {} : { headers: { "Content-Type": "application/json" }, body: JSON.stringify(body) }),
  });
  const data = await response.json().catch(() => null);
  if (!response.ok || !data?.clinic || !Array.isArray(data.chiropractors) || !Array.isArray(data.locations)) {
    throw new ClinicRequestError(data?.message || "Clinic data is unavailable. Please try again.", response.status, data?.fields || {});
  }
  return data as ClinicSnapshot;
}
