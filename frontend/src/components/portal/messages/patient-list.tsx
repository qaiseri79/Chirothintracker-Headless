/**
 * The chiropractor's conversation list.
 *
 * The counterpart of the patient page's single thread: where a patient has one
 * provider, a chiropractor has one thread per patient, and this is how they pick
 * between them. Reads the same `Conversation[]` the thread itself is drawn from,
 * so a send or a read-receipt updates the row and the open thread together.
 *
 * Ported from "New Design/ChiroThin — Doctor Messages.html". The Mass Message
 * button that design puts above the search is not here: the feature is not built
 * yet, and a control that cannot do anything is worse than its absence.
 */

import { useMemo } from "react";
import { Search } from "lucide-react";
import { InitialsAvatar } from "@/components/avatar";
import { isInbound } from "@/lib/messages/update";
import type { Conversation } from "@/lib/messages/types";

export type PatientFilter = "all" | "unread";

export function PatientList({
  conversations,
  selectedUid,
  onSelect,
  filter,
  onFilter,
  query,
  onQuery,
  unreadTotal,
  loading = false,
}: {
  conversations: Conversation[];
  /** `null` when nothing is selected, which is the state before the first pick. */
  selectedUid: number | null;
  onSelect: (partnerUid: number) => void;
  filter: PatientFilter;
  onFilter: (filter: PatientFilter) => void;
  query: string;
  onQuery: (value: string) => void;
  /** Unread conversations across the whole list, before the search filter. */
  unreadTotal: number;
  loading?: boolean;
}) {
  const rows = useMemo(
    () => selectRows(conversations, filter, query),
    [conversations, filter, query],
  );

  return (
    // Fills the frame the page supplies. Width, border and surface belong to
    // that wrapper, which also carries the Mass Message button; repeating them
    // here drew a second border inside a sidebar that was already boxed.
    //
    // `min-h-0` on this and on the list below is what makes the list scroll.
    // A flex item defaults to `min-height: auto`, so a column sized by its
    // content never overflows, `flex-1` had nothing to constrain, and the
    // sidebar simply grew past the viewport where `overflow-hidden` clipped it
    // — which is why no scrollbar ever appeared.
    <div className="flex min-h-0 w-full flex-1 flex-col">
      <div className="space-y-3 border-b border-line p-4">
        <div className="relative">
          <Search
            className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground"
            aria-hidden="true"
          />
          <input
            type="text"
            placeholder="Search patients or messages..."
            value={query}
            onChange={(e) => onQuery(e.target.value)}
            aria-label="Search patients or messages"
            className="w-full rounded-lg border border-line bg-canvas py-2 pl-9 pr-3 text-sm text-foreground placeholder:text-muted-foreground focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
          />
        </div>

        <div className="flex rounded-lg bg-canvas p-0.5 text-xs font-medium" role="tablist">
          <FilterTab
            active={filter === "all"}
            label="All patients"
            onClick={() => onFilter("all")}
          />
          <FilterTab
            active={filter === "unread"}
            label={unreadTotal > 0 ? `Unread (${unreadTotal})` : "Unread"}
            onClick={() => onFilter("unread")}
          />
        </div>
      </div>

      {/* `overscroll-contain` stops the list from chaining its scroll to the
          page once it reaches the end. */}
      <div className="min-h-0 flex-1 overflow-y-auto overscroll-contain">
        {loading ? (
          <p role="status" className="p-6 text-center text-sm text-muted-foreground">
            Loading patients…
          </p>
        ) : rows.length === 0 ? (
          <p className="p-6 text-center text-sm text-muted-foreground">No patients found.</p>
        ) : (
          rows.map((conversation) => (
            <PatientRow
              key={conversation.partner_uid}
              conversation={conversation}
              selected={conversation.partner_uid === selectedUid}
              onSelect={onSelect}
            />
          ))
        )}
      </div>
    </div>
  );
}

function FilterTab({
  active,
  label,
  onClick,
}: {
  active: boolean;
  label: string;
  onClick: () => void;
}) {
  return (
    <button
      type="button"
      role="tab"
      aria-selected={active}
      onClick={onClick}
      className={`flex-1 rounded-md px-3 py-1.5 transition-colors ${
        active
          ? "bg-surface text-foreground shadow-panel"
          : "text-muted-foreground hover:text-foreground"
      }`}
    >
      {label}
    </button>
  );
}

function PatientRow({
  conversation,
  selected,
  onSelect,
}: {
  conversation: Conversation;
  selected: boolean;
  onSelect: (partnerUid: number) => void;
}) {
  const unread = conversation.unread_count > 0;
  // Newest-first, so index 0 is the message the preview describes.
  const latest = conversation.messages[0];
  const mine = latest ? !isInbound(latest) : false;
  // The badge counts *unread* messages, not the thread total. `getConversations()`
  // loads whole threads, so a long history would otherwise outrank a patient
  // with 3 genuinely unread messages sitting at the top of the list.
  const unreadCount = conversation.unread_count;

  return (
    <button
      type="button"
      onClick={() => onSelect(conversation.partner_uid)}
      aria-current={selected ? "true" : undefined}
      data-partner-uid={conversation.partner_uid}
      data-unread={conversation.unread_count}
      className={`flex w-full items-start gap-3 border-b border-line px-4 py-3.5 text-left transition-colors ${
        selected ? "bg-primary-soft" : "hover:bg-canvas/60"
      }`}
    >
      <InitialsAvatar name={conversation.partner_name} className="size-10 text-xs" />
      <div className="min-w-0 flex-1">
        <div className="flex items-center justify-between gap-2">
          <p
            className={`truncate text-sm ${unread ? "font-semibold" : "font-medium"} text-foreground`}
          >
            {conversation.partner_name}
          </p>
          {/* The design's count badge: a solid `flame` pill carrying a bare
              number (the design's `accent`). */}
          <div className="flex shrink-0 items-center gap-2">
            {unreadCount > 0 && (
              <span
                title={`${unreadCount} unread message${unreadCount === 1 ? "" : "s"}`}
                className="rounded-full bg-flame px-1.5 py-0.5 text-[10px] font-semibold text-white"
              >
                {unreadCount}
              </span>
            )}
            <span className="text-[11px] text-muted-foreground">
              {conversation.last_message_time_formatted}
            </span>
          </div>
        </div>
        <div className="flex items-center justify-between gap-2">
          <p
            className={`truncate text-xs ${unread ? "text-foreground/80" : "text-muted-foreground"}`}
          >
            {mine ? "You: " : ""}
            {previewFor(conversation, latest?.text)}
          </p>
        </div>
      </div>
    </button>
  );
}

/**
 * Unread conversations first, then names alphabetically within each group.
 * Filtering creates a new array so sorting never mutates shared thread state.
 */
export function selectRows(
  conversations: Conversation[],
  filter: PatientFilter,
  query: string,
): Conversation[] {
  const q = query.trim().toLowerCase();
  return conversations.filter((c) => {
    // Coerced rather than compared with `=== 0`. The two tests of this value
    // disagreed: the tab total used `unread_count > 0` while this filter used a
    // strict `=== 0`, so a count arriving as the string "0" counted as unread in
    // the header and as read here, and the tab would show "Unread (1)" over an
    // empty list. Both now ask the same question of the same value.
    if (filter === "unread" && Number(c.unread_count) <= 0) return false;
    if (!q) return true;
    return (
      c.partner_name.toLowerCase().includes(q) ||
      (c.search_text ?? "").toLowerCase().includes(q) ||
      c.messages.some((m) => m.text.toLowerCase().includes(q))
    );
  }).sort((a, b) => {
    const unreadFirst = Number(Number(b.unread_count) > 0) - Number(Number(a.unread_count) > 0);
    return unreadFirst
      || a.partner_name.localeCompare(b.partner_name, undefined, { sensitivity: "base", numeric: true })
      || a.partner_uid - b.partner_uid;
  });
}

/**
 * What the row's second line says.
 *
 * A thread whose newest message is an attachment has no body to preview, so it
 * falls back to the file count rather than rendering an empty line.
 */
function previewFor(conversation: Conversation, text?: string): string {
  const body = text?.trim();
  if (body) return body;
  if (conversation.last_message_preview) return conversation.last_message_preview;
  const files = conversation.messages[0]?.attachments?.length ?? 0;
  return files ? `${files} file${files > 1 ? "s" : ""}` : "No messages yet";
}
