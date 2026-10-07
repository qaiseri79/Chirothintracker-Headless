import type { EnrollmentAllowance } from "./types";

/** Shared capacity state for the usage banner, enrollment form and tab icon. */
export function enrollmentCapacity(allowance: EnrollmentAllowance | null) {
  if (!allowance || allowance.limit === null) {
    return { full: false, low: false, percent: null };
  }
  const full = allowance.remaining === 0;
  return {
    full,
    low: !full && (allowance.remaining ?? 0) <= Math.max(2, Math.ceil(allowance.limit * 0.1)),
    percent: allowance.limit > 0 ? Math.min(100, allowance.used / allowance.limit * 100) : 100,
  };
}