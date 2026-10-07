/**
 * The Drupal `/api/headless/messages` contract, as the frontend consumes it.
 *
 * Both endpoints return this one shape. `MessagesService::getThread()` used to
 * return a strict subset of it, which is why the list and the thread disagreed
 * about which fields exist — see the note on `Conversation` below.
 */

/**
 * Who sent a message, relative to the signed-in account.
 *
 * `patient` means "the signed-in account", not "an account with the
 * `patient_chirothin` role". `MessagesService::mapMessage()` derives it from
 * `field_from` compared against the current user, so it flips correctly for
 * whichever audience is reading. The portal only routes patients to
 * `/messages` today (chiropractors go to `/chiropractor`), so in practice
 * `patient` is always the reader.
 */
export type MessageAuthor = "patient" | "doctor";

export interface Message {
  id: number;
  from: MessageAuthor;
  /** Preformatted server-side, e.g. `Wed, 09/16 · 11:56`. */
  time: string;
  /** Plain text with newlines preserved. Used for search/preview. */
  text: string;
  /** Sanitized HTML from the server, safe to render with dangerouslySetInnerHTML. */
  html: string;
  /** Attachments on this message. */
  attachments: Attachment[];
  /** True when the recipient has not yet seen it. Doctor messages only. */
  is_read: boolean;
}

export interface Attachment {
  fid: number;
  name: string;
  size: number;
  mime: string;
}

export interface Conversation {
  partner_uid: number;
  partner_name: string;
  /** Human label from `MessagesService::getRoleLabel()`, e.g. "Your ChiroThin provider". */
  partner_role: string;
  messages: Message[];
  /** Plain history text supplied by the lightweight sidebar response for search. */
  search_text?: string;
  /** Messages to the signed-in account the recipient has not opened yet. */
  unread_count: number;
  /** Unix seconds of the most recent message, or null when there are none. */
  last_message_time: number | null;
  last_message_preview: string;
  /** Preformatted `Mon dd · HH:MM`, or "" when there are no messages. */
  last_message_time_formatted: string;
}

/**
 * `POST /api/headless/messages` response.
 *
 * `message` is overloaded in Drupal's response and this mirrors it rather than
 * papering over it: on success it is the created `Message`, on failure it is the
 * human-readable reason. Narrow it with a type guard before reading it.
 * `MessagesController::send()` was given a `message_text` alias for the failure
 * case, which is what `failureReason()` below prefers — the two agree.
 */
export interface SendResult {
  success: boolean;
  id?: number | null;
  message?: Message | string;
  message_text?: string;
}

/**
 * The human-readable failure reason from a send response, or null when the
 * response actually succeeded.
 *
 * Prefers `message_text` (always a string) and falls back to `message` only
 * when it is a string, so a successful send never reports a reason.
 */
export function failureReason(result: SendResult): string | null {
  if (result.success) return null;
  if (typeof result.message_text === "string") return result.message_text;
  return typeof result.message === "string" ? result.message : null;
}

/**
 * The created message from a successful send response, or null.
 */
export function sentMessage(result: SendResult): Message | null {
  if (!result.success) return null;
  return result.message && typeof result.message !== "string" ? result.message : null;
}

/**
 * A conversation with the signed-in account's provider that has no messages
 * yet. `MessagesService::getConversations()` always includes the provider —
 * read from `field_chiropractor` — even when the thread is empty, because the
 * design's empty state names the provider ("Start your conversation with
 * Dr. Reese") and there is nothing to take that name from otherwise.
 */
export function isEmptyConversation(conversation: Conversation | null): boolean {
  return !conversation || conversation.messages.length === 0;
}
