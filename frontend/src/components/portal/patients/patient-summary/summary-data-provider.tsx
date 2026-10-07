"use client";
import { createContext, useCallback, useContext, useEffect, useRef, useState } from "react";
import { useAuth } from "@/lib/auth";
import { resolvePortalAccess } from "@/lib/portal";
import { summaryRequest } from "@/lib/patients/summary-api";
import { SECTION_FIELDS, type PatientSummaryRow, type PatientSummarySnapshot, type SummarySection } from "@/lib/patients/summary";

type Patch = Partial<PatientSummaryRow> & { patient?: Partial<PatientSummaryRow>; hasMore?: boolean; nextOffset?: number };
type SectionState = { loading?: boolean; loaded?: boolean; error?: string; hasMore?: boolean; offset?: number };
interface SummaryContext {
  patients: PatientSummaryRow[];
  readOnly: boolean;
  sections: Record<string, SectionState>;
  pending: Record<number, boolean>;
  refresh: (id: number) => Promise<void>;
  load: (id: number, section: SummarySection, more?: boolean) => Promise<void>;
  write: (id: number, suffix: string, payload: unknown | FormData, section?: SummarySection, method?: string) => Promise<void>;
}
const Context = createContext<SummaryContext | null>(null);
export function SummaryDataProvider({ initial, children }: { initial: PatientSummarySnapshot; children: React.ReactNode }) {
  const { user } = useAuth();
  const readOnly = resolvePortalAccess(user?.roles, user?.capabilities, user?.portalAccess)?.readOnly ?? true;
  const [patients, setPatients] = useState(initial.patients);
  const [sections, setSections] = useState<Record<string, SectionState>>({});
  const [pending, setPending] = useState<Record<number, boolean>>({});
  const states = useRef<Record<string, SectionState>>({});
  const requests = useRef(new Map<string, AbortController>());
  const writes = useRef(new Set<number>());
  const mounted = useRef(true);
  const revisions = useRef<Record<string, number>>({});
  useEffect(() => { mounted.current = true; const activeRequests = requests.current; return () => { mounted.current = false; activeRequests.forEach((controller) => controller.abort()); }; }, []);
  const setSection = useCallback((key: string, value: SectionState) => {
    states.current[key] = value;
    if (mounted.current) setSections((current) => ({ ...current, [key]: value }));
  }, []);
  const load = useCallback(async (id: number, section: SummarySection, more = false) => {
    const key = `${id}:${section}`;
    const state = states.current[key] ?? {};
    if (requests.current.has(key) || (!more && state.loaded) || (more && !state.hasMore)) return;
    const offset = more ? state.offset ?? 0 : 0;
    const controller = new AbortController(); requests.current.set(key, controller);
    const revision = revisions.current[key] ?? 0;
    setSection(key, { ...state, loading: true, error: undefined });
    try {
      const result = await summaryRequest<Patch>(id, `?section=${section}&offset=${offset}`, { signal: controller.signal });
      if (!mounted.current || revision !== (revisions.current[key] ?? 0)) return;
      const field = SECTION_FIELDS[section];
      const incoming = result[field];
      setPatients((current) => current.map((row) => {
        if (row.id !== id) return row;
        const patch = { ...result };
        delete patch.hasMore; delete patch.nextOffset;
        if (more && Array.isArray(incoming) && Array.isArray(row[field])) {
          if (section === "sessions" && result.sessions) {
            patch.sessions = result.sessions.map((session) => ({ ...session, history: [...(row.sessions.find((existing) => existing.id === session.id)?.history ?? []), ...session.history] }));
          } else Object.assign(patch, { [field]: [...row[field], ...incoming] });
          if (section === "logs") delete patch.dailyLoss;
        }
        return { ...row, ...patch };
      }));
      setSection(key, { loaded: true, loading: false, hasMore: result.hasMore, offset: result.nextOffset ?? offset + (Array.isArray(incoming) ? incoming.length : 0) });
    } catch (error) {
      if (!controller.signal.aborted && revision === (revisions.current[key] ?? 0)) setSection(key, { ...state, loading: false, error: error instanceof Error ? error.message : "Unable to load patient details." });
    } finally { requests.current.delete(key); }
  }, [setSection]);
  const write = useCallback(async (id: number, suffix: string, payload: unknown | FormData, section?: SummarySection, method = "POST") => {
    if (readOnly) throw new Error("This account has read-only access.");
    if (writes.current.has(id)) throw new Error("Please wait for the current save to finish.");
    writes.current.add(id); setPending((current) => ({ ...current, [id]: true }));
    try {
      const multipart = payload instanceof FormData;
      const result = await summaryRequest<Patch>(id, suffix, {
        method, ...(multipart ? {} : { headers: { "Content-Type": "application/json" } }),
        ...(method === "DELETE" ? {} : { body: multipart ? payload : JSON.stringify(payload) }),
      });
      if (!mounted.current) return;
      if (section) { const key = `${id}:${section}`; revisions.current[key] = (revisions.current[key] ?? 0) + 1; }
      const patch = result.patient ?? result;
      if (result.patient) {
        const { sessions: _sessions, notesList: _notes, attachmentsList: _files, weightHistory: _history, measurementChanges: _changes, logsList: _logs, intake: _intake, ...row } = patch;
        void [_sessions, _notes, _files, _history, _changes, _logs, _intake];
        setPatients((current) => current.map((patient) => patient.id === id ? { ...patient, ...row } : patient));
      } else {
        setPatients((current) => current.map((patient) => patient.id === id ? { ...patient, ...patch } : patient));
      }
      if (section) setSection(`${id}:${section}`, { loaded: true, hasMore: result.hasMore, offset: result.nextOffset ?? (Array.isArray(result[SECTION_FIELDS[section]]) ? (result[SECTION_FIELDS[section]] as unknown[]).length : 0) });
    } finally { writes.current.delete(id); if (mounted.current) setPending((current) => ({ ...current, [id]: false })); }
  }, [readOnly, setSection]);
  async function refresh(id: number) {
    const result = await summaryRequest<Patch>(id, "?section=overview");
    const patch = { ...result.patient };
    for (const field of ["sessions", "notesList", "attachmentsList", "weightHistory", "measurementChanges", "logsList", "intake"] as const) delete patch[field];
    if (!mounted.current) return;
    setPatients((current) => current.map((row) => row.id === id ? { ...row, ...patch } : row));
    for (const section of Object.keys(SECTION_FIELDS) as SummarySection[]) {
      const key = `${id}:${section}`;
      if (states.current[key]?.loaded) { setSection(key, {}); void load(id, section); }
    }
  }
  return <Context.Provider value={{ patients, sections, readOnly, pending, load, write, refresh }}>{children}</Context.Provider>;
}
export function useSummaryData() {
  const context = useContext(Context);
  if (!context) throw new Error("Patient summary data provider is missing.");
  return context;
}
export function SummarySectionStatus({ id, section }: { id: number; section: SummarySection }) {
  const { sections, load } = useSummaryData();
  const state = sections[`${id}:${section}`];
  if (state?.loading) return <p role="status" className="py-3 text-sm text-muted-foreground">Loading {section}…</p>;
  if (state?.error) return <p role="alert" className="py-3 text-sm text-destructive">{state.error} <button type="button" className="underline" onClick={() => void load(id, section)}>Retry</button></p>;
  if (state?.hasMore) return <button type="button" className="my-3 text-sm font-semibold text-primary" onClick={() => void load(id, section, true)}>Load more {section}</button>;
  return null;
}
