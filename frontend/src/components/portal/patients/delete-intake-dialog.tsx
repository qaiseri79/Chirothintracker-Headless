"use client";

import * as React from "react";
import { useRouter } from "next/navigation";
import * as AlertDialogPrimitive from "@radix-ui/react-alert-dialog";
import { AlertTriangle, Loader2 } from "lucide-react";
import { Button } from "@/components/ui/button";
import { deleteIntakeSubmission } from "@/lib/patients/api";

type Status = "idle" | "deleting" | "error";

/**
 * Confirmation dialog for deleting one intake submission.
 *
 * Modelled directly on `DeleteEntryDialog`, for the same reasons, because this is
 * the same act on a different record: AlertDialog rather than Dialog so a stray
 * backdrop click cannot destroy a patient's own words, focus lands on Cancel, and
 * the confirm button is a plain button rather than `AlertDialog.Action` so it does
 * not close the prompt before the request has answered and leave a failed delete
 * looking like a success.
 *
 * ## What "delete" means here, said out loud in the prompt
 *
 * Drupal deletes the `contact_message` outright — row and field data — and there is
 * no archived state to recover from. So the prompt names the patient and states
 * that the answers go with it, rather than the vaguer "are you sure?" the design
 * uses for its demo archive. A chiropractor deciding whether to keep a screening
 * history needs to know what is on the other side of the button.
 *
 * ## Not optimistic
 *
 * The intake table behind the dialog comes from the page's server component, so
 * removing the row here would need hand-written undo when the request fails. The
 * row stays, the button shows progress, and the list refreshes only after the
 * server confirms.
 */
export function DeleteIntakeDialog({
  submissionId,
  patientName,
  onClose,
}: {
  /** The submission to delete, or `null` when the dialog is closed. */
  submissionId: number | null;
  /** Shown in the prompt, so the chiropractor deletes the right submission. */
  patientName: string;
  onClose: () => void;
}) {
  const router = useRouter();
  const [status, setStatus] = React.useState<Status>("idle");
  const [error, setError] = React.useState<string | null>(null);

  // Re-targeting clears the previous attempt's failure, so a cancel-and-retry
  // never shows a stale error next to a different patient.
  const [target, setTarget] = React.useState<number | null>(null);
  if (target !== submissionId) {
    setTarget(submissionId);
    setStatus("idle");
    setError(null);
  }

  const open = submissionId !== null;
  const deleting = status === "deleting";

  async function handleConfirm() {
    if (submissionId === null || deleting) return;
    setStatus("deleting");
    setError(null);

    try {
      await deleteIntakeSubmission(submissionId);

      // Closed only after the server confirms, so a failure leaves both the row and
      // the reason on screen.
      onClose();
      router.refresh();
    } catch (cause) {
      setStatus("error");
      setError(
        cause instanceof Error && cause.message
          ? cause.message
          : "We could not delete this submission. Please try again.",
      );
    }
  }

  return (
    <AlertDialogPrimitive.Root
      open={open}
      onOpenChange={(next) => {
        // A dismissal mid-request would leave the row in place with no
        // explanation, so the dialog is held open until the delete resolves.
        if (!next && !deleting) onClose();
      }}
    >
      <AlertDialogPrimitive.Portal>
        <AlertDialogPrimitive.Overlay className="fixed inset-0 z-50 bg-black/80 data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0" />
        <AlertDialogPrimitive.Content className="fixed left-[50%] top-[50%] z-50 grid w-full max-w-md translate-x-[-50%] translate-y-[-50%] gap-4 border border-border bg-background p-6 shadow-lg duration-200 data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0 data-[state=closed]:zoom-out-95 data-[state=open]:zoom-in-95 data-[state=closed]:slide-out-to-left-1/2 data-[state=closed]:slide-out-to-top-[48%] data-[state=open]:slide-in-from-left-1/2 data-[state=open]:slide-in-from-top-[48%] sm:rounded-lg">
          <div className="flex gap-3">
            <span className="flex size-9 shrink-0 items-center justify-center rounded-full bg-destructive/10 text-destructive">
              <AlertTriangle className="size-5" aria-hidden="true" />
            </span>
            <div className="space-y-1.5">
              <AlertDialogPrimitive.Title className="font-semibold text-lg leading-none tracking-tight text-foreground">
                Delete this intake submission?
              </AlertDialogPrimitive.Title>
              <AlertDialogPrimitive.Description className="text-muted-foreground text-sm">
                This permanently removes the intake form from{" "}
                <span className="font-medium text-foreground">{patientName}</span>{" "}
                — every answer they gave, including their health screening. It
                cannot be undone.
              </AlertDialogPrimitive.Description>
            </div>
          </div>

          {error ? (
            <p role="alert" className="text-destructive text-sm">
              {error}
            </p>
          ) : null}

          <div className="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <AlertDialogPrimitive.Cancel asChild>
              <Button variant="outline" size="lg" disabled={deleting}>
                Cancel
              </Button>
            </AlertDialogPrimitive.Cancel>
            <Button
              variant="destructive"
              size="lg"
              onClick={handleConfirm}
              disabled={deleting}
              // Announces that the click was accepted while the request runs, so the
              // state change is not silent.
              aria-busy={deleting}
            >
              {deleting ? (
                <>
                  <Loader2 className="animate-spin" aria-hidden="true" />
                  Deleting&hellip;
                </>
              ) : (
                "Delete submission"
              )}
            </Button>
          </div>
        </AlertDialogPrimitive.Content>
      </AlertDialogPrimitive.Portal>
    </AlertDialogPrimitive.Root>
  );
}