"use client";

import * as React from "react";
import * as AlertDialogPrimitive from "@radix-ui/react-alert-dialog";
import { RefreshCw } from "lucide-react";
import { Button } from "@/components/ui/button";

/**
 * Confirmation for replacing the clinic's intake link.
 *
 * ## Why this needs asking and generating does not
 *
 * Generating is additive and idempotent: no link means one is made, and a link means
 * nothing happens. Replacing is neither. The current token is destroyed, the old URL
 * stops working *immediately*, and patients may have that URL printed, saved in a
 * message, or written on a form at a front desk. So the prompt names the consequence
 * rather than asking "are you sure?", the confirm button is a plain button rather than
 * `AlertDialog.Action` (so the dialog cannot close before the request has answered and
 * a failure looks like a success), and the dialog stays open while the request runs.
 *
 * AlertDialog rather than Dialog so a stray backdrop click cannot do it, and focus
 * lands on Cancel.
 *
 * Modelled on `DeleteIntakeDialog`, which is the same act on a different record.
 */
export function RegenerateIntakeLinkDialog({
  open,
  onConfirm,
  onClose,
}: {
  open: boolean;
  /** Runs the request. Rejections are handled by the caller's own error state. */
  onConfirm: () => Promise<void>;
  onClose: () => void;
}) {
  const [status, setStatus] = React.useState<"idle" | "working">("idle");

  async function handleConfirm() {
    if (status === "working") return;
    setStatus("working");
    try {
      await onConfirm();
    } finally {
      setStatus("idle");
    }
  }

  return (
    <AlertDialogPrimitive.Root
      open={open}
      onOpenChange={(next) => {
        // Held open until the request resolves, so a dismissal mid-flight cannot leave
        // a destroyed link with no explanation on screen.
        if (!next && status !== "working") onClose();
      }}
    >
      <AlertDialogPrimitive.Portal>
        <AlertDialogPrimitive.Overlay className="fixed inset-0 z-50 bg-black/80 data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0" />
        <AlertDialogPrimitive.Content className="fixed top-1/2 left-1/2 z-50 w-full max-w-md -translate-x-1/2 -translate-y-1/2 gap-4 rounded-lg border border-border bg-background p-6 shadow-lg duration-200 data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0 data-[state=closed]:zoom-out-95 data-[state=open]:zoom-in-95 sm:rounded-lg">
          <div className="flex gap-3">
            <span className="flex size-9 shrink-0 items-center justify-center rounded-full bg-destructive/10 text-destructive">
              <RefreshCw className="size-5" aria-hidden="true" />
            </span>
            <div className="space-y-1.5">
              <AlertDialogPrimitive.Title className="font-semibold text-lg leading-none tracking-tight text-foreground">
                Replace your intake link?
              </AlertDialogPrimitive.Title>
              <AlertDialogPrimitive.Description className="text-sm text-muted-foreground">
                The current link stops working immediately. Anyone who already has it
                — a patient holding a printed copy, a link in a message — will see an
                expired-link page and have to use the new one.
              </AlertDialogPrimitive.Description>
            </div>
          </div>

          <div className="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <AlertDialogPrimitive.Cancel asChild>
              <Button variant="outline" size="lg" disabled={status === "working"}>
                Cancel
              </Button>
            </AlertDialogPrimitive.Cancel>
            <Button
              variant="destructive"
              size="lg"
              onClick={handleConfirm}
              disabled={status === "working"}
              aria-busy={status === "working"}
            >
              {status === "working" ? (
                <>
                  <RefreshCw className="animate-spin" aria-hidden="true" />
                  Replacing&hellip;
                </>
              ) : (
                "Replace link"
              )}
            </Button>
          </div>
        </AlertDialogPrimitive.Content>
      </AlertDialogPrimitive.Portal>
    </AlertDialogPrimitive.Root>
  );
}