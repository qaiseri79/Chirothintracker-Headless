/**
 * The thread pane: header, scrolling messages, reply bar.
 *
 * One component for both audiences because the design draws the same thread on
 * both sides. What differs is passed in as props rather than branched on:
 *
 * - **Header**: the doctor pane adds a back affordance for the narrow layout,
 *   where the patient list and the thread are two views of one column.
 * - **Empty state**: the patient pane's names the provider and offers starter
 *   prompts; the doctor pane's is the same block pointed at a patient.
 * - **Per-message actions**: the doctor pane adds "Reply".
 *
 * The header, scroll behaviour, search filter and drag-and-drop target are
 * identical, which is why they live here rather than in either page.
 *
 * ## Twenty messages at a time
 *
 * A conversation is the longest list in the portal and a doctor can have
 * thousands of messages with one patient, so the thread opens showing the twenty
 * most recent and pulls in older batches as the reader scrolls up. The loading
 * mechanics are `hooks/use-progressive-list.ts`, the same hook the dashboard's
 * log history uses; only the direction differs, because an inbox fills downward
 * and a thread fills upward.
 *
 * The newest message is what the reader came for, which is why the first batch
 * is the *end* of the array rather than the beginning. Starting at the
 * beginning would put the oldest message on screen and the bottom of the list
 * would be nowhere near the conversation, which is the wrong place to land.
 *
 * The full array still arrives in one request, so what is limited here is how
 * much of it becomes DOM, not how much crosses the network. Bounding the
 * payload needs cursor paging in `MessagesService`, which has none today.
 */

import { useEffect, useMemo, useRef, useState } from "react";
import { Loader2, Search } from "lucide-react";
import { Button } from "@/components/ui/button";
import { InitialsAvatar } from "@/components/avatar";
import { MessageBubble } from "@/components/portal/messages/message-bubble";
import {
  MessageComposer,
  type MessageComposerHandle,
} from "@/components/portal/messages/message-composer";
import { EmptyThread } from "@/components/portal/messages/empty-thread";
import { useProgressiveList } from "@/hooks/use-progressive-list";
import type { Conversation, Message } from "@/lib/messages/types";

/**
 * How many messages the thread renders on open, and adds per batch.
 *
 * A first paint of 20 rather than the full array is what keeps opening a long
 * conversation cheap: the thread is the heaviest DOM in the portal, and a
 * doctor can have thousands of messages with one patient.
 */
const MESSAGE_BATCH = 20;

export interface MessageThreadProps {
  conversation: Conversation | null;
  /** Suppresses the "no messages yet" state while the first load is in flight. */
  loading: boolean;
  /** A read-only account may read the thread but not post to it. */
  readOnly: boolean;
  sending: boolean;
  searchQuery: string;
  onSearchQuery: (value: string) => void;
  onSend: (text: string, file?: File) => Promise<void>;
  onMarkAllRead: () => Promise<void>;
  onMarkOneRead: (messageId: number) => Promise<void>;
  /** Adds a "Reply" shortcut to inbound messages. */
  onReply?: () => void;
  /**
   * Quick-open prompts on the empty state. Tapping one seeds the reply box. Only
   * the patient thread has these — see `EmptyThread`.
   */
  starterPrompts?: { label: string; text: string }[];
  /** Rendered left of the avatar — the doctor pane's back-to-list button. */
  leading?: React.ReactNode;
  /** Sub-heading under the partner's name. Defaults to the conversation's role. */
  subtitle?: string;
  /** Stand-in when the conversation has no name yet. */
  partnerFallback?: string;
  /** Patient header shows the unread total next to the read-all action. */
  showUnreadCount?: boolean;
}

export function MessageThread({
  conversation,
  loading,
  readOnly,
  sending,
  searchQuery,
  onSearchQuery,
  onSend,
  onMarkAllRead,
  onMarkOneRead,
  onReply,
  starterPrompts,
  leading,
  subtitle,
  partnerFallback = "Your provider",
  showUnreadCount = false,
}: MessageThreadProps) {
  const [error, setError] = useState<string | null>(null);
  const [dragOver, setDragOver] = useState(false);
  const threadRef = useRef<HTMLDivElement>(null);
  const composerRef = useRef<MessageComposerHandle>(null);

  // Memoised because `conversation?.messages ?? []` allocates a new array on
  // every render while loading, which would defeat the `ordered` memo below and
  // re-reverse the thread on each paint.
  const messages = useMemo(() => conversation?.messages ?? [], [conversation?.messages]);
  const partnerName = conversation?.partner_name ?? "";
  const unreadCount = conversation?.unread_count ?? 0;

  // Oldest-first on screen: the conversation reads top-to-bottom like an inbox,
  // with the newest message at the bottom.
  //
  // Reversed here rather than in `MessagesService`, which still delivers
  // newest-first. The patient sidebar's preview depends on `messages[0]` being
  // the newest, and `applySent`/`applyOneRead` prepend/assume that order too, so
  // flipping the service would have quietly broken the list previews. Copying
  // the array before reversing keeps the caller's data intact — `.reverse()`
  // mutates in place and would corrupt the conversation the page still holds.
  const ordered = useMemo(() => [...messages].reverse(), [messages]);

  // Pin to the newest message. With oldest-first that is the end of the list, so
  // this is a scroll to the bottom rather than to 0. Deferred to a rAF because
  // the new bubble has not been laid out at effect time, and pinning before
  // layout lands leaves the view one message short of the bottom.
  useEffect(() => {
    const el = threadRef.current;
    if (!el) return;
    const id = requestAnimationFrame(() => {
      el.scrollTop = el.scrollHeight;
    });
    return () => cancelAnimationFrame(id);
  }, [conversation?.partner_uid, messages.length, searchQuery]);

  // Older messages are added as the reader scrolls *up*, so this is the mirror
  // image of the log history on the dashboard, which appends as the patient
  // scrolls *down*. The full `ordered` array is already in memory, so
  // progressive loading here bounds how many bubbles become DOM, not how much is
  // transferred — the one request still returns the whole conversation.
  //
  // Search filters first and the batch is taken from the filtered result, so a
  // term that matches five scattered messages shows those five rather than the
  // twenty most recent. `resetKey` couples the pagination to the filter and the
  // conversation: changing either re-reads from the newest message, and arriving
  // at a different conversation does not inherit how far back the previous one
  // had been scrolled.
  const filtered = useMemo(() => filterMessages(ordered, searchQuery), [ordered, searchQuery]);

  const { visible, hasMore, remaining, loadMore, sentinelRef, isPending } = useProgressiveList(
    filtered,
    {
      pageSize: MESSAGE_BATCH,
      mode: "prepend",
      rootRef: threadRef,
      resetKey: `${conversation?.partner_uid}:${searchQuery.trim()}`,
    },
  );

  if (loading && !conversation) {
    return (
      <div className="flex flex-1 items-center justify-center">
        <p className="text-muted-foreground">Loading messages…</p>
      </div>
    );
  }

  return (
    <div className="flex min-w-0 flex-1 flex-col overflow-hidden">
      <div className={`flex items-center gap-3 border-b border-line bg-surface px-6 py-3.5 ${showUnreadCount ? "flex-wrap md:flex-nowrap" : ""}`}>
        {leading}
        <InitialsAvatar name={partnerName} className="size-10 text-sm" />
        <div className="min-w-0 flex-1">
          <p className="truncate text-sm font-semibold text-foreground">
            {partnerName || partnerFallback}
          </p>
          <p className="truncate text-xs text-muted-foreground">
            {subtitle ?? conversation?.partner_role ?? "Provider"}
          </p>
        </div>

        <div className="flex shrink-0 items-center gap-2">
        {/* Guarded on a non-zero count, like the "Mark all read" button below it and the
            sidebar's message badge. "0 unread" states the result of a check rather
            than a queue that needs one, and the header already shows that nothing
            here is waiting. */}
        {showUnreadCount && unreadCount > 0 && (
          <span aria-live="polite" className="shrink-0 whitespace-nowrap text-xs font-medium text-flame">
            {unreadCount.toLocaleString()} unread
          </span>
        )}

        {/* Same treatment as the per-message "Mark as read" pill, and for the
            same reason: the design's `accent` is this app's `flame`. */}
        {unreadCount > 0 && (
          <button
            type="button"
            disabled={readOnly}
            title={readOnly ? "This operation requires active access." : undefined}
            onClick={() => void onMarkAllRead()}
            className={`disabled:opacity-40 shrink-0 whitespace-nowrap rounded-full border border-flame/40 px-3 py-1 text-[11px] font-medium text-flame transition-colors hover:bg-flame hover:text-white ${showUnreadCount ? "inline-flex" : "hidden md:inline-flex"}`}
          >
            Mark all read
          </button>
        )}

        </div>

        <div className={showUnreadCount ? "relative w-full shrink-0 md:w-64" : "relative w-44 shrink-0 sm:w-64"}>
          <Search
            className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground"
            aria-hidden="true"
          />
          <input
            type="text"
            placeholder="Search messages & files..."
            value={searchQuery}
            onChange={(e) => onSearchQuery(e.target.value)}
            disabled={messages.length === 0}
            aria-label="Search messages"
            className="w-full rounded-lg border border-line bg-canvas py-2 pl-9 pr-3 text-sm text-foreground placeholder:text-muted-foreground focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary disabled:opacity-50"
          />
        </div>
      </div>

      <div
        ref={threadRef}
        className="relative flex-1 overflow-y-auto px-6 py-6"
        onDragOver={(e) => {
          if (e.dataTransfer.types.includes("Files")) {
            e.preventDefault();
            setDragOver(true);
          }
        }}
        onDragLeave={() => setDragOver(false)}
        onDrop={(e) => {
          e.preventDefault();
          setDragOver(false);
          const file = e.dataTransfer.files?.[0];
          // Handed to the composer rather than validated here, so a dropped file
          // meets the same rule as a picked one and lands in the same staged slot.
          if (file) composerRef.current?.stageFile(file);
        }}
      >
        {dragOver && (
          <div className="pointer-events-none fixed inset-0 z-50 flex items-center justify-center bg-primary/10 backdrop-blur-[1px]">
            <div className="flex h-[calc(100%-2rem)] w-[calc(100%-2rem)] flex-col items-center justify-center rounded-2xl border-2 border-dashed border-primary bg-surface/80 text-center">
              <p className="font-serif text-xl text-primary">Drop file to attach</p>
              <p className="mt-1 text-sm text-muted-foreground">
                PDF, images, Word, Excel, text
              </p>
            </div>
          </div>
        )}

        {messages.length === 0 ? (
          <EmptyThread
            counterpartName={partnerName}
            starterPrompts={starterPrompts}
            onStarter={(text) => composerRef.current?.seedReply(text)}
            readOnly={readOnly}
            readOnlyMessage={`Your account can read this conversation but cannot send to it.`}
          />
        ) : visible.length === 0 ? (
          <p className="mt-16 text-center text-sm text-muted-foreground">
            No messages match &ldquo;{searchQuery.trim()}&rdquo;.
          </p>
        ) : (
          <div className="mx-auto max-w-[90%]">
            {/*
              The loader sits at the top because loading older messages is a
              scroll-up interaction, the mirror of the log history on the
              dashboard. `rootMargin` means the batch is usually already there by
              the time the reader reaches it, and the button bound to the same
              handler keeps it reachable by keyboard and screen reader, both of
              which an IntersectionObserver on its own would lock out.
            */}
            {hasMore ? (
              <div ref={sentinelRef} className="flex flex-col items-center gap-2 py-2">
                <Button
                  variant="outline"
                  size="lg"
                  onClick={loadMore}
                  disabled={isPending}
                  aria-busy={isPending}
                >
                  {isPending ? (
                    <>
                      <Loader2 className="animate-spin" aria-hidden="true" />
                      Loading&hellip;
                    </>
                  ) : (
                    `Load ${Math.min(MESSAGE_BATCH, remaining)} older`
                  )}
                </Button>
                <p className="text-muted-foreground text-xs">
                  Showing {visible.length} of {filtered.length}
                </p>
              </div>
            ) : null}

            <div className="space-y-4">
              {visible.map((message) => (
                <MessageBubble
                  key={message.id}
                  message={message}
                  onMarkRead={readOnly ? undefined : onMarkOneRead}
                  onReply={readOnly ? undefined : onReply}
                />
              ))}
            </div>
          </div>
        )}
      </div>

      {error ? (
        <p role="alert" className="border-t border-line bg-surface px-6 py-2 text-sm text-destructive">
          {error}
        </p>
      ) : null}

      <MessageComposer
        ref={composerRef}
        disabled={readOnly || sending}
        sending={sending}
        onSend={onSend}
        onError={setError}
      />
    </div>
  );
}

/**
 * The messages a search term leaves visible.
 *
 * Matches body text and attachment filenames, so "lab" finds the message that
 * only carries `Lab-Results-Sept.pdf`.
 */
export function filterMessages(messages: Message[], query: string): Message[] {
  const q = query.trim().toLowerCase();
  if (!q) return messages;
  return messages.filter(
    (m) =>
      m.text.toLowerCase().includes(q) ||
      (m.attachments || []).some((a) => a.name.toLowerCase().includes(q)),
  );
}
