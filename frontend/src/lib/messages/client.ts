/**
 * The browser-side calls the message threads make, in one place.
 *
 * Both audiences talk to the same five endpoints and the same contract
 * (`lib/messages/types.ts`); only *which* conversations come back differs. The
 * patient page and the doctor page therefore share these functions rather than
 * each open-coding its own `fetch`, so a change to the response shape lands in
 * one place.
 *
 * All of these hit the Next.js route handlers under `/api/messages/*`, never
 * Drupal directly: the browser has no Drupal session cookie, and the handlers
 * are what forward it.
 */

import {
  failureReason,
  sentMessage,
  type Conversation,
  type Message,
  type SendResult,
} from "@/lib/messages/types";

/** Thrown for a transport or server failure, carrying a message fit to show. */
export class MessageRequestError extends Error {}

/**
 * Every conversation for the signed-in account.
 *
 * A patient gets exactly one — their provider, from `field_chiropractor` —
 * including when the thread is still empty. A chiropractor gets one per patient
 * they have exchanged a message with, each carrying that patient's own
 * `unread_count`, which is what the doctor page's sidebar lists.
 */
// Chiropractor sidebars request previews; patient pages keep the full response.
export async function fetchConversations(summaryOnly = false): Promise<Conversation[]> {
  const response = await fetch(
    summaryOnly ? "/api/messages?summary=1" : "/api/messages",
    { cache: "no-store" },
  );
  if (!response.ok) {
    throw new MessageRequestError("Failed to load conversations");
  }
  const data = await response.json();
  return Array.isArray(data) ? data : [];
}

/** The full thread with one other account. `null` when there is no such thread. */
export async function fetchThread(targetUid: number): Promise<Conversation | null> {
  const response = await fetch(`/api/messages/${targetUid}`, { cache: "no-store" });
  if (response.status === 404) return null;
  if (!response.ok) {
    throw new MessageRequestError("Failed to load this conversation");
  }
  return (await response.json()) as Conversation;
}

/**
 * Send a message, with at most one attachment.
 *
 * The file rides along in the same multipart request as the text rather than
 * being uploaded first for an fid: `MessagesService::sendMessage()` takes either
 * a body, an attachment, or both, and a two-step upload would leave an orphaned
 * file on `private://` whenever the send that referenced it failed.
 */
export async function sendMessage(
  targetUid: number,
  text: string,
  file?: File,
): Promise<Message> {
  const form = new FormData();
  form.append("target_uid", String(targetUid));
  form.append("text", text);
  if (file) form.append("files[attachment]", file);

  const response = await fetch("/api/messages", { method: "POST", body: form });
  const body = (await response.json().catch(() => ({}))) as SendResult;

  console.debug("[sendMessage] response:", { ok: response.ok, status: response.status, body });

  if (!response.ok || !body.success) {
    throw new MessageRequestError(
      failureReason(body) ?? "Unable to send message. Please try again.",
    );
  }

  // A 2xx that carries no message is not a send: `sentMessage()` returns null
  // and the caller must not append a bubble it would have to invent.
  const sent = sentMessage(body);
  if (!sent) {
    throw new MessageRequestError("Message sent but could not be confirmed. Refresh to check.");
  }
  return sent;
}

/**
 * Mark every inbound message in a thread as read.
 *
 * Only the returned `true`/`false` matters to the caller: the endpoint answers
 * 200 with `marked_read: 0` when there was nothing to mark, so a successful
 * response does not imply the count changed. Callers clear their own
 * `unread_count` only after this resolves true.
 */
export async function markConversationRead(targetUid: number): Promise<boolean> {
  const response = await fetch(`/api/messages/${targetUid}/read`, { method: "POST" });
  return response.ok;
}

/** Mark one inbound message read. The doctor page's per-message action. */
export async function markMessageRead(messageId: number): Promise<boolean> {
  const response = await fetch(`/api/messages/message/${messageId}/read`, { method: "POST" });
  return response.ok;
}

/** One recipient the backend could not reach. */
export interface MassSendFailure {
  uid: number;
  reason: string;
}

/**
 * `POST /api/messages/mass` response.
 *
 * `sent` carries the stored `Message` for every recipient that was reached, so
 * the caller can apply the real entity — real id, real attachment fids, real
 * personalisation — instead of guessing them locally. A batch that landed
 * somewhere still succeeds, with the misses listed in `failed`.
 */
export interface MassSendResult {
  success: boolean;
  requested: number;
  sent: { uid: number; id: number; message: Message }[];
  failed: MassSendFailure[];
  message: string;
}

/**
 * Send a mass message to multiple recipients with optional attachments.
 * Uses multipart/form-data to send recipient UIDs, message text, and files.
 *
 * The field is `files[]` rather than `files`: PHP keeps only the last entry when
 * repeated keys share a name, so the brackets are what make every upload
 * survive the trip to Drupal.
 */
export async function sendMassMessage(
  partnerUids: number[],
  text: string,
  files: File[],
): Promise<MassSendResult> {
  const formData = new FormData();
  formData.append("uids", JSON.stringify(partnerUids));
  formData.append("text", text);
  files.forEach((file) => formData.append("files[]", file));

  const res = await fetch("/api/messages/mass", {
    method: "POST",
    body: formData,
  });

  const data = (await res.json().catch(() => ({}))) as Partial<MassSendResult>;

  if (!res.ok) {
    throw new Error(
      typeof data.message === "string" ? data.message : "Failed to send mass message",
    );
  }

  return {
    success: true,
    requested: data.requested ?? partnerUids.length,
    sent: data.sent ?? [],
    failed: data.failed ?? [],
    message: data.message ?? "",
  };
}
