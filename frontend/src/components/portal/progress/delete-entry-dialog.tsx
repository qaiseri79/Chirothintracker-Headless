"use client";

import * as React from "react";
import { useRouter } from "next/navigation";
import * as AlertDialogPrimitive from "@radix-ui/react-alert-dialog";
import { AlertTriangle, Loader2 } from "lucide-react";
import { Button } from "@/components/ui/button";

type Status = "idle" | "deleting" | "error";

/**
 * Confirmation dialog for deleting a single progress-log entry.
 *
 * ## Why AlertDialog rather than the Dialog used for editing
 *
 * Radix's AlertDialog calls `preventDefault()` on both `onPointerDownOutside`
 * and `onInteractOutside` (see `react-alert-dialog/dist/index.mjs`), so a click
 * on the backdrop cannot dismiss it, and it focuses Cancel on open. A stray
 * click is the likeliest way to destroy a day of logging by accident, so the
 * dismissal paths that make that easy are removed. Escape still closes it,
 * which is deliberate: an explicit key press is not an accident, and trapping
 * someone in a dialog they are stuck in is worse.
 *
 * ## Why the delete is not optimistic
 *
 * The list behind the dialog comes from the dashboard's server component, so
 * removing the row optimistically would need hand-written undo when the request
 * fails. Instead the row stays where it is, the button shows progress, and the
 * list refreshes only after the server has confirmed the delete — the same
 * ordering the edit dialog uses.
 *
 * `router.refresh()` re-runs the dashboard's server components, which re-query
 * the entries, so the row and the entry-count badge correct themselves without
 * any client-side copy of the list that could drift out of sync.
 *
 * ## Why the confirm button is not AlertDialog.Action
 *
 * `Action` closes the dialog on click, which would dismiss the prompt before
 * the request had answered and leave a failed delete looking like a success.
 * A plain button with an `onClick` keeps control of the close, and the dialog
 * is closed explicitly once the delete succeeds.
 */
export function DeleteEntryDialog({
  messageId,
  entryDate,
  onClose,
}: {
  /** The entry to delete, or `null` when the dialog is closed. */
  messageId: number | null;
  /** Shown in the prompt, so the patient confirms the right day. */
  entryDate: string;
  onClose: () => void;
}) {
  const router = useRouter();
  const [status, setStatus] = React.useState<Status>("idle");
  const [error, setError] = React.useState<string | null>(null);

  // Re-targeting for a different entry clears the previous attempt's failure,
  // so a later cancel-and-retry never shows a stale error.
  const [target, setTarget] = React.useState<number | null>(null);
  if (target !== messageId) {
    setTarget(messageId);
    setStatus("idle");
    setError(null);
  }

  const open = messageId !== null;
  const deleting = status === "deleting";

  async function handleConfirm() {
    if (messageId === null || deleting) return;
    setStatus("deleting");
    setError(null);

    try {
      const response = await fetch(`/api/progress/entry/${messageId}`, {
        method: "DELETE",
      });

      if (!response.ok) {
        const body = (await response.json().catch(() => null)) as {
          message?: string;
        } | null;
        setStatus("error");
        setError(
          body?.message ??
            (response.status === 404
              ? "That entry has already been deleted."
              : "We could not delete this entry. Please try again."),
        );
        return;
      }

      // Closed only after the server confirms, so a failure leaves both the row
      // and the reason on screen.
      onClose();
      router.refresh();
    } catch {
      setStatus("error");
      setError("We could not reach the server. Please check your connection.");
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
                Delete this progress log?
              </AlertDialogPrimitive.Title>
              <AlertDialogPrimitive.Description className="text-muted-foreground text-sm">
                This permanently removes your log for{" "}
                <span className="font-medium text-foreground">{entryDate}</span>{" "}
                and the measurements recorded in it. This cannot be undone.
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
              // Announces that the click was accepted while the request runs,
              // so the state change is not silent.
              aria-busy={deleting}
            >
              {deleting ? (
                <>
                  <Loader2 className="animate-spin" aria-hidden="true" />
                  Deleting&hellip;
                </>
              ) : (
                "Delete log"
              )}
            </Button>
          </div>
        </AlertDialogPrimitive.Content>
      </AlertDialogPrimitive.Portal>
    </AlertDialogPrimitive.Root>
  );
}