"use client";

import { useRouter } from "next/navigation";
import { Dialog } from "@/components/ui/dialog";
import { TrackingDialogContent } from "./tracking-dialog-content";
import { TrackingForm } from "@/components/tracking/tracking-form";

/** The same full progress form as /log-progress, hosted on the dashboard. */
export function CreateEntryDialog({ open, onClose }: { open: boolean; onClose: () => void }) {
  const router = useRouter();
  return (
    <Dialog open={open} onOpenChange={(next) => { if (!next) onClose(); }}>
      <TrackingDialogContent title="Log your progress" description="Record your daily weight, measurements, and progress.">
        {open ? <TrackingForm embedded onSaved={() => router.refresh()} onDone={onClose} /> : null}
      </TrackingDialogContent>
    </Dialog>
  );
}
