"use client";

/**
 * One message in a thread, shared by the patient and doctor views.
 *
 * The bubble is audience-agnostic on purpose. `Message.from` is relative to the
 * signed-in account — `patient` means "the account reading this", not "an
 * account with the patient role" — so `from === "patient"` is the *right* edge
 * for the patient page and the *left* edge for the doctor page without either
 * caller saying so. See `lib/messages/types.ts`.
 *
 * What differs between the two audiences is only the per-message actions, so
 * those are props rather than a `variant` flag: the patient thread offers
 * "Mark as read" on an unread inbound message, and the doctor thread adds a
 * "Reply" shortcut. Rendering is otherwise identical, which is the point — the
 * design reuses the same bubble on both sides.
 *
 * ## Colours
 *
 * The classes below follow the design's markup, but one token name had to be
 * remapped. The design's `accent` is its burnt orange `#C7522A`, which this app
 * carries as `--flame`; the app's own `accent` is `#f3f4f1`, a pale hover fill
 * (see `globals.css`). So the design's `accent` reads as `flame` here — using
 * `accent` renders the unread ring and the "Mark as read" label near-white and
 * they disappear. The rest of the design's names already exist as equivalents:
 * `ink` → `foreground`, `muted` → `muted-foreground`, `text-white` on a
 * `bg-primary` bubble → `primary-foreground`.
 */

import { useId, useState } from "react";
import { Check, CheckCheck } from "lucide-react";
import {
  AttachmentFiles,
  AttachmentImages,
  splitAttachments,
} from "@/components/portal/messages/attachments";
import type { Message } from "@/lib/messages/types";
export function MessageBubble({
  message,
  onMarkRead,
  onReply,
}: {
  message: Message;
  /** Omitted where the viewer cannot mark this message read. */
  onMarkRead?: (messageId: number) => Promise<void>;
  /** Omitted where there is no reply affordance. */
  onReply?: () => void;
}) {
  const [expanded, setExpanded] = useState(false);
  const bodyId = useId();
  const text = message.text.replace(/\r\n?/g, "\n");
  const preview = Array.from(text).slice(0, 400).join("").split("\n").slice(0, 6).join("\n");
  const canExpand = preview.length < text.length;
  const mine = message.from === "patient";
  const attachments = message.attachments || [];
  const { images, files } = splitAttachments(attachments);

  return (
    <div className={`flex ${mine ? "justify-end" : "justify-start"}`}>
      <div className="max-w-[85%] sm:max-w-[70%]">
        <div
          className={`whitespace-pre-line rounded-2xl px-4 py-3 text-sm leading-relaxed ${
            mine
              ? "rounded-br-sm bg-primary text-primary-foreground"
              : `rounded-bl-sm border bg-surface text-foreground shadow-panel ${
                  message.is_read ? "border-line" : "border-flame ring-1 ring-flame/30"
                }`
          }`}
        >
          {message.text && (
            <>
              {canExpand && !expanded ? (
                // React escapes the plain preview; never slice formatted HTML.
                <div id={bodyId} className="break-words">
                  {preview.trimEnd()}…
                </div>
              ) : (
                // Full content remains Drupal's sanitized HTML, preserving formatting.
                <div
                  id={bodyId}
                  className="break-words"
                  dangerouslySetInnerHTML={{ __html: message.html }}
                />
              )}
              {canExpand && (
                <button
                  type="button"
                  aria-expanded={expanded}
                  aria-controls={bodyId}
                  onClick={() => setExpanded((current) => !current)}
                  className={`mt-2 inline-block text-xs font-semibold underline underline-offset-2 hover:no-underline ${
                    mine ? "text-primary-foreground" : "text-primary"
                  }`}
                >
                  {expanded ? "View less" : "View all"}
                </button>
              )}
            </>
          )}

          <AttachmentImages attachments={images} />
          <AttachmentFiles attachments={files} mine={mine} />
        </div>

        <div
          className={`mt-1 flex flex-wrap items-center gap-2 text-[11px] text-muted-foreground ${
            mine ? "justify-end" : ""
          }`}
        >
          <span>{message.time}</span>

          {attachments.length > 0 && (
            <>
              <span className="text-line" aria-hidden="true">
                ·
              </span>
              <span>
                {attachments.length} file{attachments.length > 1 ? "s" : ""}
              </span>
            </>
          )}

          {/* The actions apply to an inbound message only: read state belongs to
              the recipient, so there is nothing for its sender to mark. */}
          {!mine ? (
            <>
              {onMarkRead && !message.is_read ? (
                <>
                  <span className="text-line" aria-hidden="true">
                    ·
                  </span>
                  <button
                    type="button"
                    onClick={() => void onMarkRead(message.id)}
                    className="rounded-full border border-flame/40 px-2 py-0.5 text-[11px] font-medium text-flame transition-colors hover:bg-flame hover:text-white"
                  >
                    Mark as read
                  </button>
                </>
              ) : null}

              {onReply ? (
                <>
                  <span className="text-line" aria-hidden="true">
                    ·
                  </span>
                  <button
                    type="button"
                    onClick={onReply}
                    className="font-medium text-primary hover:underline"
                  >
                    Reply
                  </button>
                </>
              ) : null}

              {/* An inbound message the recipient has already opened says so.
                  Keyed off `is_read` alone: read state is tracked for every
                  inbound message, and gating this on the absence of `onMarkRead`
                  made it unreachable, because `MessageThread` always supplies
                  that prop — so clicking "Mark as read" removed the link and
                  left nothing in its place. */}
              {message.is_read ? (
                <>
                  <span className="text-line" aria-hidden="true">
                    ·
                  </span>
                  <span className="flex items-center gap-1" title="Read">
                    <CheckCheck className="h-3.5 w-3.5 text-primary" aria-hidden="true" />
                    <span>Read</span>
                  </span>
                </>
              ) : null}
            </>
          ) : null}

          {mine && (
            <span className="flex items-center gap-1" title="Sent">
              <Check className="h-3.5 w-3.5 text-muted-foreground" aria-hidden="true" />
            </span>
          )}
        </div>
      </div>
    </div>
  );
}
