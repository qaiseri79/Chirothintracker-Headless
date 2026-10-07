"use client";

import { createContext, useCallback, useContext, useEffect, useRef, useState } from "react";
import { useAuth } from "@/lib/auth";
import { resolvePortalAccess } from "@/lib/portal";

interface IntakeCountContextValue {
  intakeCount: number | null;
  setIntakeCount: (count: number) => void;
}

const IntakeCountContext = createContext<IntakeCountContextValue | null>(null);

export function IntakeCountProvider({ children }: { children: React.ReactNode }) {
  const { user, status } = useAuth();
  const email = user?.email ?? null;
  const isChiropractor = status === "authenticated"
    && resolvePortalAccess(user?.roles, user?.capabilities, user?.portalAccess)?.audience === "chiropractor";
  const [snapshot, setSnapshot] = useState<{ email: string; count: number } | null>(null);
  const revision = useRef(0);

  const setIntakeCount = useCallback((count: number) => {
    if (!email || !isChiropractor) return;
    revision.current++;
    setSnapshot({ email, count: Math.max(0, count) });
  }, [email, isChiropractor]);

  useEffect(() => {
    if (!email || !isChiropractor) return;
    const controller = new AbortController();
    async function refresh() {
      const startedAtRevision = revision.current;
      try {
        const response = await fetch("/api/patients/intake-count", {
          cache: "no-store",
          signal: controller.signal,
        });
        if (!response.ok) return;
        const data = await response.json();
        // A pending count request must not undo a newer intake snapshot.
        if (!controller.signal.aborted && startedAtRevision === revision.current
          && Number.isInteger(data.intakeNewCount) && data.intakeNewCount >= 0) {
          setSnapshot({ email: email!, count: data.intakeNewCount });
        }
      } catch {
        // Keep the last known count when a refresh is unavailable.
      }
    }
    void refresh();
    const onFocus = () => { void refresh(); };
    window.addEventListener("focus", onFocus);
    return () => {
      controller.abort();
      window.removeEventListener("focus", onFocus);
    };
  }, [email, isChiropractor]);

  const intakeCount = isChiropractor && snapshot?.email === email ? snapshot.count : null;
  return (
    <IntakeCountContext.Provider value={{ intakeCount, setIntakeCount }}>
      {children}
    </IntakeCountContext.Provider>
  );
}

export function useIntakeCount(): IntakeCountContextValue {
  const value = useContext(IntakeCountContext);
  if (!value) throw new Error("useIntakeCount must be used within IntakeCountProvider.");
  return value;
}
