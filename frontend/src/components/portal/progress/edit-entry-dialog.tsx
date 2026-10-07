"use client";

import { useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import { Loader2 } from "lucide-react";
import { Dialog } from "@/components/ui/dialog";
import { TrackingDialogContent } from "./tracking-dialog-content";
import { Button } from "@/components/ui/button";
import { TrackingForm } from "@/components/tracking/tracking-form";
import type { FieldValue } from "@/lib/intake/state";

/**
 * A finished fetch, tagged with the entry it belongs to.
 *
 * The tag is what makes staleness impossible: every render compares
 * `result.id` against the entry currently being edited, so a result left over
 * from the previous entry can never be shown. It also lets "still loading" be
 * *derived* rather than stored, so nothing has to synchronously reset state
 * when the dialog opens.
 */
type LoadResult =
  | { id: number; status: "ready"; entry: Record<string, FieldValue> }
  | { id: number; status: "error"; message: string };

/**
 * The "Edit" action from a log-history row, as a dialog.
 *
 * ## Why a dialog
 *
 * Editing used to `router.push("/log-progress/edit/[id]")`, which threw the
 * patient off the page they were reading: to check a change they had to press
 * Back, and to reach the next entry they had to come back and start again. The
 * dialog keeps the log history on screen behind the form, so "fix the typo,
 * check the next day" is two clicks rather than four.
 *
 * ## Why the entry is fetched client-side
 *
 * The edit *page* is a server component and loads the entry with `drupalFetch`
 * during render. A dialog has no render pass of its own — it is opened by a
 * click long after the dashboard finished rendering — so the data has to arrive
 * over the network. `/api/progress/entry/[messageId]` already exists for
 * exactly this and forwards the session cookie, so this reuses it rather than
 * adding a second read path.
 *
 * ## Ownership
 *
 * The dialog passes `onDone`, which the page route cannot: `log-history` is
 * already a client component, so the function prop never crosses a server
 * boundary. On save the dialog closes and calls `router.refresh()` so the
 * numbers behind it come back from the server — `refresh()` re-fetches the
 * server components and merges the result without discarding this state.
 *
 * The Drupal side still enforces ownership on both the read and the update, so
 * a hand-edited `messageId` gets the same 404/403 it would from the page.
 */
export function EditEntryDialog({
  messageId,
  onClose,
}: {
  /** The entry to edit, or `null` when the dialog is closed. */
  messageId: number | null;
  /** Called when the dialog should close — backdrop, Escape, or the X. */
  onClose: () => void;
}) {
  const router = useRouter();
  const [openedFor, setOpenedFor] = useState<number | null>(null);
  const [result, setResult] = useState<LoadResult | null>(null);

  // Discard the previous entry's data the moment the target changes. Without
  // this, closing and reopening the *same* entry renders the values it had
  // before the last save — the id still matches, so nothing else would catch
  // it, and the patient would be shown numbers they had just corrected.
  //
  // This is a render-time reset (React's documented alternative to resetting in
  // an effect), which is also why `loading` below can stay derived.
  if (openedFor !== messageId) {
    setOpenedFor(messageId);
    setResult(null);
  }

  useEffect(() => {
    if (messageId === null) return;

    const controller = new AbortController();

    (async () => {
      try {
        const response = await fetch(`/api/progress/entry/${messageId}`, {
          signal: controller.signal,
        });
        if (!response.ok) {
          const body = (await response.json().catch(() => null)) as {
            message?: string;
          } | null;
          setResult({
            id: messageId,
            status: "error",
            message:
              body?.message ??
              (response.status === 404
                ? "That entry no longer exists."
                : "We could not open this entry. Please try again."),
          });
          return;
        }
        const entry = (await response.json()) as Record<string, FieldValue>;
        setResult({ id: messageId, status: "ready", entry });
      } catch (error) {
        // An abort is the dialog closing mid-fetch, not a failure.
        if (error instanceof DOMException && error.name === "AbortError") return;
        setResult({
          id: messageId,
          status: "error",
          message: "We could not reach the server. Please check your connection.",
        });
      }
    })();

    return () => controller.abort();
  }, [messageId]);

  function handleSaved() {
    onClose();
    router.refresh();
  }

  // A result is only ever rendered when it is the one that was asked for, so a
  // reopen on a different entry never shows the previous entry's values.
  const current = messageId !== null && result?.id === messageId ? result : null;
  const loading = messageId !== null && current === null;

  return (
    <Dialog
      open={messageId !== null}
      onOpenChange={(next) => {
        if (!next) onClose();
      }}
    >
      <TrackingDialogContent title="Edit Progress Log" description="Update this day's progress entry.">
        {loading ? (
          <div
            role="status"
            aria-live="polite"
            className="flex min-h-64 flex-col items-center justify-center gap-3 p-8"
          >
            <Loader2 className="size-5 animate-spin text-muted-foreground" aria-hidden="true" />
            <p className="text-sm text-muted-foreground">Loading entry&hellip;</p>
          </div>
        ) : null}

        {current?.status === "error" ? (
          <div className="flex min-h-64 flex-col items-center justify-center gap-4 p-8 text-center">
            <p role="alert" className="max-w-sm text-sm text-destructive">
              {current.message}
            </p>
            <Button variant="outline" onClick={onClose}>
              Close
            </Button>
          </div>
        ) : null}

        {current?.status === "ready" ? (
          <TrackingForm
            editMessageId={String(messageId)}
            initialValues={current.entry}
            onDone={handleSaved}
            embedded
          />
        ) : null}
      </TrackingDialogContent>
    </Dialog>
  );
}