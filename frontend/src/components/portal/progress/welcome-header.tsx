"use client";

import { useState, useTransition } from "react";
import { useRouter } from "next/navigation";
import { Check, Plus, RefreshCw, Video } from "lucide-react";
import { Button } from "@/components/ui/button";
import { useAuth } from "@/lib/auth";
import { resolvePortalAccess } from "@/lib/portal";
import { useLogSync } from "@/lib/progress/use-log-sync";
import { CreateEntryDialog } from "./create-entry-dialog";

/** Greeting and the three dashboard actions from New Design/progress.html. */
export function WelcomeHeader({ programDay, goalPercent }: { programDay: number; goalPercent: string }) {
  const { user } = useAuth();
  const router = useRouter();
  const [logOpen, setLogOpen] = useState(false);
  const [refreshing, startRefresh] = useTransition();
  const { sync, syncing, progress, error } = useLogSync(() => startRefresh(() => router.refresh()));
  const access = resolvePortalAccess(user?.roles, user?.capabilities, user?.portalAccess);
  const canWrite = access?.audience === "patient" && !access.readOnly;
  const busy = syncing || refreshing;
  const percent = progress ? (progress.total ? Math.round(progress.processed / progress.total * 100) : 100) : 0;
  const complete = progress?.done && !error;
  const firstName = (user?.name ?? "").split(/\s+/)[0];
  const buttonClass = "h-auto gap-2 border-line px-4 py-2.5 font-semibold shadow-panel";
  const syncLabel = syncing
    ? progress ? `Syncing ${progress.processed} / ${progress.total} · ${percent}%` : "Starting sync…"
    : refreshing ? "Updating dashboard…" : error ? "Retry sync" : complete ? "Logs up to date" : "Sync logs";

  return (
    <>
      <div className="flex flex-wrap items-end justify-between gap-4">
        <div>
          <h1 className="font-serif text-2xl text-foreground">
            Welcome back{firstName ? `, ${firstName}` : ""} 👋
          </h1>
          <p className="mt-1 text-sm text-muted-foreground">
            Day {programDay} of your program · {goalPercent} of the way to goal.
          </p>
        </div>
        <div className="flex max-w-full flex-wrap items-center gap-2" style={{ fontFamily: "var(--font-inter), system-ui, sans-serif" }}>
          <Button type="button" variant="outline" onClick={sync} disabled={!canWrite || busy || logOpen}
            title={canWrite ? "Re-sync all logs from the start" : user?.portalAccess?.reason === "program_archived" ? "Archived patient accounts are read-only" : "Operations are unavailable while your access is inactive"}
            aria-live="polite" aria-busy={busy}
            className={`${buttonClass} relative min-w-[168px] overflow-hidden bg-surface text-foreground hover:bg-canvas disabled:opacity-50`}>
            <span aria-hidden="true" className="absolute inset-y-0 left-0 bg-primary-soft transition-[width] duration-250" style={{ width: `${error ? 0 : percent}%` }} />
            <span aria-hidden="true" className="absolute inset-x-0 bottom-0 h-0.5">
              <span className="block h-full bg-primary transition-[width] duration-250" style={{ width: `${error ? 0 : percent}%` }} />
            </span>
            <span className="relative flex items-center justify-center gap-2">
              {complete && !busy ? <Check className="size-4 text-primary" aria-hidden="true" /> : <RefreshCw className={`size-4 ${busy ? "animate-spin" : ""}`} aria-hidden="true" />}
              <span>{syncLabel}</span>
            </span>
          </Button>
          <span title="Meet Now is currently unavailable">
            <Button type="button" variant="outline" disabled aria-describedby="meeting-unavailable"
              className={`${buttonClass} bg-surface text-foreground`}>
              <Video className="size-4" aria-hidden="true" />Meet Now
            </Button>
          </span>
          <span id="meeting-unavailable" className="sr-only">Meeting feature currently unavailable.</span>
          <Button type="button" disabled={!canWrite || busy} onClick={() => setLogOpen(true)}
            aria-haspopup="dialog" className={`${buttonClass} border-transparent bg-primary text-white hover:bg-brand-dark`}>
            <Plus className="size-4" aria-hidden="true" />Log your progress
          </Button>
        </div>
      </div>
      {error ? <p role="alert" className="text-sm text-destructive">{error}</p> : null}
      <CreateEntryDialog open={logOpen && canWrite} onClose={() => setLogOpen(false)} />
    </>
  );
}
