"use client";

import { useEffect, useRef, useState } from "react";

type SyncProgress = { token: string; processed: number; total: number; done: boolean };

/** True batch progress, with unfinished jobs resumed by Drupal after a retry. */
export function useLogSync(onComplete: () => void) {
  const active = useRef<AbortController | null>(null);
  const [progress, setProgress] = useState<SyncProgress | null>(null);
  const [syncing, setSyncing] = useState(false);
  const [error, setError] = useState<string | null>(null);
  useEffect(() => () => active.current?.abort(), []);
  useEffect(() => {
    if (!progress?.done || syncing) return;
    // Completion feedback is temporary; progress itself comes only from Drupal.
    const timer = window.setTimeout(() => setProgress(null), 1800);
    return () => window.clearTimeout(timer);
  }, [progress, syncing]);

  async function sync() {
    if (active.current) return;
    const controller = new AbortController();
    active.current = controller;
    setSyncing(true);
    setError(null);
    setProgress(null);
    try {
      let next: { token: string | null; processed: number } = { token: null, processed: 0 };
      while (!controller.signal.aborted) {
        const response = await fetch("/api/progress/sync", {
          method: "POST", headers: { "Content-Type": "application/json" },
          body: JSON.stringify(next), signal: controller.signal,
        });
        const body = await response.json().catch(() => null) as (Partial<SyncProgress> & { message?: string }) | null;
        if (!response.ok) throw new Error(body?.message ?? "Unable to sync your logs. Please try again.");
        if (!body || typeof body.token !== "string" || typeof body.done !== "boolean"
          || !Number.isSafeInteger(body.processed) || !Number.isSafeInteger(body.total)
          || body.processed! < 0 || body.total! < body.processed!) throw new Error("Invalid sync response. Please try again.");
        if (controller.signal.aborted) return;
        const result = body as SyncProgress;
        setProgress(result);
        if (result.done) { onComplete(); return; }
        next = { token: result.token, processed: result.processed };
      }
    } catch (cause) {
      if (!controller.signal.aborted) setError(cause instanceof Error ? cause.message : "Unable to sync your logs. Please try again.");
    } finally {
      if (!controller.signal.aborted) setSyncing(false);
      active.current = null;
    }
  }
  return { sync, syncing, progress, error };
}
