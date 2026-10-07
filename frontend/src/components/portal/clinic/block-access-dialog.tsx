"use client";

import * as React from "react";
import * as AlertDialogPrimitive from "@radix-ui/react-alert-dialog";
import { cn } from "cn";
import { Ban, CircleCheck } from "lucide-react";
import { BTN, BTN_ACCENT, BTN_OUTLINE } from "./clinic-primitives";

/**
 * Confirmation for blocking or unblocking one chiropractor — the design's `#cf`.
 *
 * ## AlertDialog, not Dialog
 *
 * Blocking revokes portal access immediately and is not something a stray backdrop
 * click should do, so this is an `AlertDialog` and the backdrop is inert. Focus lands
 * on Cancel rather than on the confirm button, which is the same reasoning
 * `DeleteIntakeDialog` uses for the same reason.
 *
 * ## The prompt says which way it goes
 *
 * The design writes the verb into the title ("Block Amber Von Haden?"), states the
 * consequence in the body, and relabels the button to match. Both are kept, because
 * the consequence is what a chiropractor deciding mid-row needs to read: blocking ends
 * access immediately and can be undone, unblocking restores it. The button is a plain
 * button rather than `AlertDialog.Action` so closing is left to the caller, after the
 * state change it owns has been applied.
 */
export function BlockAccessDialog({
  chiropractor,
  blocked,
  busy,
  onConfirm,
  onClose,
}: {
  /** The chiropractor the prompt names, or `null` when it is closed. */
  chiropractor: string | null;
  /** Their current state — `true` means the action offered is "unblock". */
  blocked: boolean;
  busy: boolean;
  onConfirm: () => void;
  onClose: () => void;
}) {
  const open = chiropractor !== null;
  const willBlock = !blocked;

  return (
    <AlertDialogPrimitive.Root
      open={open}
      onOpenChange={(next) => {
        if (!next) onClose();
      }}
    >
      <AlertDialogPrimitive.Portal>
        {/* `bg-ink/40`, the mock-up's own backdrop — softer than the shared
            Dialog's `bg-black/80`, and a deliberate part of this design. */}
        <AlertDialogPrimitive.Overlay className="fixed inset-0 z-50 bg-foreground/40" />
        <AlertDialogPrimitive.Content className="fixed top-1/2 left-1/2 z-50 w-full max-w-sm -translate-x-1/2 -translate-y-1/2 rounded-2xl border border-line bg-surface p-6 shadow-2xl">
          <span
            className="flex size-11 items-center justify-center rounded-full bg-flame-soft text-flame"
            aria-hidden="true"
          >
            {willBlock ? (
              <Ban className="size-5" />
            ) : (
              <CircleCheck className="size-5" />
            )}
          </span>

          <AlertDialogPrimitive.Title className="mt-4 font-serif text-xl font-semibold text-foreground">
            {willBlock ? "Block" : "Unblock"} {chiropractor}?
          </AlertDialogPrimitive.Title>
          <AlertDialogPrimitive.Description className="mt-1 text-sm text-muted-foreground">
            {willBlock
              ? "They will lose access to the portal right away. You can unblock them at any time."
              : "They will regain access to the portal."}
          </AlertDialogPrimitive.Description>

          <div className="mt-6 flex gap-3">
            <AlertDialogPrimitive.Cancel asChild>
              <button type="button" disabled={busy} className={cn(BTN, BTN_OUTLINE, "flex-1")}>
                Cancel
              </button>
            </AlertDialogPrimitive.Cancel>
            <button
              type="button"
              onClick={onConfirm}
              disabled={busy}
              // The design uses the accent for both directions rather than swapping
              // in the primary for unblock.
              className={cn(BTN, BTN_ACCENT, "flex-1")}
            >
              {busy ? "Saving…" : willBlock ? "Block chiropractor" : "Unblock"}
            </button>
          </div>
        </AlertDialogPrimitive.Content>
      </AlertDialogPrimitive.Portal>
    </AlertDialogPrimitive.Root>
  );
}