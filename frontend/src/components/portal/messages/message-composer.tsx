/**
 * The reply bar: attach, type, send.
 *
 * Shared by the patient thread and the doctor thread, which the design treats as
 * the same control. The differences between them are all inputs:
 * `readOnly` disables it for an archived patient, and `onSend` is what differs
 * underneath — the doctor page sends to whichever patient is selected.
 *
 * Enter sends, Shift+Enter newlines, a pasted or dropped file is staged rather
 * than pasted as text. One attachment at a time, matching what
 * `MessagesService::sendMessage()` accepts today.
 */

import { forwardRef, useEffect, useImperativeHandle, useRef, useState } from "react";
import { Paperclip, Send } from "lucide-react";
import { FileBadge } from "@/components/portal/messages/attachments";
import {
  ATTACHMENT_ACCEPT,
  fmtSize,
  isImage,
  kindOf,
  rejectReason,
} from "@/lib/messages/attachments";

/**
 * Imperative handle on the composer's draft.
 *
 * Two things outside the composer need to reach inside it, and neither can do so
 * by owning state:
 *
 * - `MessageThread` owns the drag-and-drop target, which spans the whole thread
 *   including the header, so a dropped file has to reach the staged slot.
 * - The empty state's starter prompts seed the draft, which the thread forwards
 *   from its own props.
 *
 * Exposing these beats duplicating the composer's rules in the caller or reaching
 * across the tree with a DOM event.
 */
export interface MessageComposerHandle {
  /** Stage a file the user dropped onto the thread. */
  stageFile: (file: File) => void;
  /** Seed the draft and focus it, leaving the cursor after the seeded text. */
  seedReply: (text: string) => void;
}

interface MessageComposerProps {
  /** Disables the whole control — a read-only account, or a send in flight. */
  disabled: boolean;
  disabledPlaceholder?: string;
  sending?: boolean;
  /**
   * Called with the trimmed text and the staged file, if any. Resolving means
   * sent; rejecting surfaces the reason. The composer clears itself only on
   * resolve, so a failed send keeps what was typed.
   */
  onSend: (text: string, file?: File) => Promise<void>;
  onError?: (message: string | null) => void;
}

export const MessageComposer = forwardRef<MessageComposerHandle, MessageComposerProps>(
  function MessageComposer(
    {
      disabled,
      disabledPlaceholder = "Messaging is closed on your account.",
      sending,
      onSend,
      onError,
    }: MessageComposerProps,
    ref,
  ) {
  const [text, setText] = useState("");
  const [attachment, setAttachment] = useState<File | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [previewUrl, setPreviewUrl] = useState<string | null>(null);

  const replyRef = useRef<HTMLTextAreaElement>(null);
  const fileInputRef = useRef<HTMLInputElement>(null);

  const report = (message: string | null) => {
    setError(message);
    onError?.(message);
  };

  function stage(file: File | null) {
    if (!file || disabled) return;
    const reason = rejectReason(file);
    if (reason) {
      report(reason);
      return;
    }
    report(null);
    setAttachment(file);
  }

  useImperativeHandle(
    ref,
    () => ({
      stageFile: (file: File) => stage(file),
      seedReply: (seed: string) => {
        setText(seed);
        const box = replyRef.current;
        if (!box) return;
        box.focus();
        // The prompts are written to end mid-sentence ("...a supplement for "),
        // so the caret belongs after the seeded text, not at position 0.
        box.setSelectionRange(seed.length, seed.length);
      },
    }),
    [disabled],
  );

  // The object URL for a staged image is minted in an effect rather than in the
  // click handler so a re-render cannot mint a second one for the same file.
  useEffect(() => {
    if (!attachment || !isImage(attachment.name)) {
      setPreviewUrl(null);
      return;
    }
    const url = URL.createObjectURL(attachment);
    setPreviewUrl(url);
    return () => URL.revokeObjectURL(url);
  }, [attachment]);

  async function submit() {
    const body = text.trim();
    if ((!body && !attachment) || disabled || sending) return;

    const file = attachment;
    report(null);
    try {
      await onSend(body, file ?? undefined);
      // Only on success: a rejected send leaves the draft and the staged file
      // exactly as they were, so nothing has to be retyped.
      setText("");
      setAttachment(null);
    } catch (e) {
      report(e instanceof Error ? e.message : "Unable to send message. Please try again.");
    }
  }

  function handleKeyDown(event: React.KeyboardEvent<HTMLTextAreaElement>) {
    if (event.key === "Enter" && !event.shiftKey) {
      event.preventDefault();
      void submit();
    }
  }

  function handlePaste(event: React.ClipboardEvent<HTMLTextAreaElement>) {
    const files = Array.from(event.clipboardData?.files || []);
    if (files.length > 0) {
      event.preventDefault();
      stage(files[0]);
    }
  }

  return (
    <div className="border-t border-line bg-surface p-4">
      <div className="mx-auto max-w-3xl">
        <div className="rounded-xl border border-line bg-canvas focus-within:border-primary focus-within:ring-1 focus-within:ring-primary">
          {attachment && (
            <div className="flex items-center gap-2 border-b border-line px-3 py-2">
              {previewUrl ? (
                // eslint-disable-next-line @next/next/no-img-element -- a local
                // object URL for an unstaged file, not a routable asset
                <img
                  src={previewUrl}
                  alt=""
                  className="h-9 w-9 shrink-0 rounded-lg object-cover"
                />
              ) : (
                <FileBadge kind={kindOf(attachment.name) ?? "pdf"} />
              )}
              <span className="min-w-0 flex-1">
                <span className="block truncate text-xs font-medium text-foreground">
                  {attachment.name}
                </span>
                <span className="block text-[11px] text-muted-foreground">
                  {fmtSize(attachment.size)}
                </span>
              </span>
              <button
                type="button"
                onClick={() => setAttachment(null)}
                disabled={disabled}
                aria-label="Remove attachment"
                className="flex h-6 w-6 shrink-0 items-center justify-center rounded-full text-muted-foreground hover:bg-flame-soft hover:text-flame disabled:opacity-50"
              >
                <svg
                  className="h-3.5 w-3.5"
                  viewBox="0 0 24 24"
                  fill="none"
                  stroke="currentColor"
                  strokeWidth="2.4"
                  strokeLinecap="round"
                >
                  <path d="M18 6 6 18M6 6l12 12" />
                </svg>
              </button>
            </div>
          )}

          <div className="flex items-end gap-1.5 p-2">
            <input
              ref={fileInputRef}
              type="file"
              accept={ATTACHMENT_ACCEPT}
              onChange={(e) => {
                stage(e.target.files?.[0] ?? null);
                // Reset so re-picking the same file fires `change` again.
                e.target.value = "";
              }}
              className="hidden"
              disabled={disabled}
            />
            <button
              type="button"
              onClick={() => fileInputRef.current?.click()}
              disabled={disabled}
              aria-label="Attach file"
              title="Attach file"
              className="relative flex h-9 w-9 shrink-0 items-center justify-center rounded-lg text-muted-foreground hover:bg-primary-soft hover:text-primary disabled:opacity-50"
            >
              <Paperclip className="h-[18px] w-[18px]" />
            </button>
            <textarea
              ref={replyRef}
              rows={1}
              placeholder={disabled ? disabledPlaceholder : "Write a message..."}
              value={text}
              onChange={(e) => setText(e.target.value)}
              onKeyDown={handleKeyDown}
              onPaste={handlePaste}
              disabled={disabled}
              aria-label="Write a message"
              className="max-h-32 flex-1 resize-none bg-transparent px-2 py-2 text-sm text-foreground placeholder:text-muted-foreground focus:outline-none disabled:cursor-not-allowed disabled:opacity-60"
            />
            <button
              type="button"
              onClick={() => void submit()}
              disabled={disabled || sending || (!text.trim() && !attachment)}
              aria-label="Send"
              className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-primary text-white hover:bg-primary-dark disabled:opacity-50"
            >
              <Send className="h-4 w-4" />
            </button>
          </div>
        </div>

        <div className="mt-1.5 flex items-center justify-between gap-3 px-1 text-[11px]">
          <p role="alert" className="text-destructive">
            {error}
          </p>
          <p className="text-muted-foreground">
            Attach up to 1 file · 10 MB · PDF, images, Word, Excel, text.
          </p>
        </div>
      </div>
    </div>
  );
  },
);

