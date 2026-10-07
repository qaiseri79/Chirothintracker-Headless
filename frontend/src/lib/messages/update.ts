/**
 * Pure updates to a conversation, shared by the patient and doctor threads.
 *
 * Both threads re-render from local state after a mutation rather than re-fetching
 * the whole list, so these are the places where the optimistic result is decided.
 * Each returns a new object and leaves the argument alone: the doctor page holds
 * the same conversations in both its sidebar and its thread, and one of the two
 * updating in place would desync them.
 */

import type { Conversation, Message } from "@/lib/messages/types";

/**
 * True when a message came from the *other* party.
 *
 * `Message.from` is relative to the signed-in account — `patient` means "the
 * account reading this" — so "inbound" is the `doctor` value on both sides of
 * the product, just as "mine" is the `patient` value on both.
 */
export function isInbound(message: Message): boolean {
  return message.from === "doctor";
}

/**
 * Splice a just-sent message onto the top of a thread.
 *
 * The thread is newest-first, so a send prepends. `last_message_time` comes from
 * the browser clock: `Message` carries a formatted stamp and no epoch, and a send
 * genuinely is the newest event in its thread. The server re-derives the value
 * from `created` on the next fetch, so a skewed client clock self-corrects rather
 * than persisting.
 */
export function applySent(
  conversation: Conversation,
  sent: Message,
  fallbackPreview: string,
): Conversation {
  return {
    ...conversation,
    messages: [sent, ...conversation.messages],
    search_text: conversation.search_text === undefined
      ? undefined
      : conversation.search_text + "\n" + sent.text,
    last_message_time: Math.floor(Date.now() / 1000),
    last_message_preview: previewOf(sent, fallbackPreview),
  };
}

/** Clear the unread count and mark every inbound message read. */
export function applyAllRead(conversation: Conversation): Conversation {
  return {
    ...conversation,
    unread_count: 0,
    messages: conversation.messages.map((m) =>
      isInbound(m) && !m.is_read ? { ...m, is_read: true } : m,
    ),
  };
}

/**
 * Mark one inbound message read, decrementing the count by at most one.
 *
 * The clamp matters: the count is derived from flag state on the server, and a
 * second "Mark as read" on an already-read message must not push it negative.
 */
export function applyOneRead(conversation: Conversation, messageId: number): Conversation {
  const target = conversation.messages.find((m) => m.id === messageId);
  if (!target || !isInbound(target) || target.is_read) return conversation;
  return {
    ...conversation,
    unread_count: Math.max(0, conversation.unread_count - 1),
    messages: conversation.messages.map((m) =>
      m.id === messageId ? { ...m, is_read: true } : m,
    ),
  };
}

/** Replace one conversation in a list, matched by partner. */
export function replaceConversation(
  conversations: Conversation[],
  next: Conversation,
): Conversation[] {
  return conversations.map((c) =>
    c.partner_uid === next.partner_uid
      ? { ...next, search_text: next.search_text ?? c.search_text }
      : c,
  );
}

/** Add a conversation the list did not have yet, at the top. */
export function prependConversation(
  conversations: Conversation[],
  next: Conversation,
): Conversation[] {
  if (conversations.some((c) => c.partner_uid === next.partner_uid)) {
    return replaceConversation(conversations, next);
  }
  return [next, ...conversations];
}

/**
 * The row preview the sidebar shows: the body, else the file count, else a
 * caller-supplied stand-in for the case where the sent body was empty.
 */
export function previewOf(sent: Message, fallback: string): string {
  const body = sent.text?.trim();
  if (body) return body.slice(0, 100);
  if (fallback) return fallback.slice(0, 100);
  const files = sent.attachments?.length ?? 0;
  return files ? `${files} file${files > 1 ? "s" : ""}` : "";
}
