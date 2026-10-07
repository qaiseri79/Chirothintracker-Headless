/**
 * The doctor's notification bank: the pre-written messages and the doctor's own
 * saved ones, fetched from and stored through `/api/messages/notifications/*`.
 *
 * The dialog renders this bank and sends by *operation id*. A predefined
 * template sends as its template id; a doctor's saved one sends as
 * `custom:<nid>`. `{name}` personalisation happens server-side at send time, so
 * the same operation produces the same message regardless of which clinic or
 * patient the doctor is looking at.
 */

/** Thrown for a transport or server failure, carrying a message fit to show. */
export class NotificationRequestError extends Error {}

/** A pre-written notification from the JSON template bank. */
export interface NotificationTemplate {
  id: string;
  label: string;
  body: string;
}

/** One of the doctor's saved `review_messages` notifications. */
export interface SavedNotification {
  nid: number;
  title: string;
  body: string;
}

/** The two lists the popup renders, from the notifications endpoint. */
export interface NotificationBank {
  templates: NotificationTemplate[];
  custom: SavedNotification[];
}

/** GET the bank. The list is cached per-render; callers refetch on open. */
export async function fetchNotificationBank(): Promise<NotificationBank> {
  const response = await fetch("/api/messages/notifications", { cache: "no-store" });
  if (!response.ok) {
    throw new NotificationRequestError("Failed to load notifications");
  }
  const data = await response.json();
  return {
    templates: Array.isArray(data.templates) ? (data.templates as NotificationTemplate[]) : [],
    custom: Array.isArray(data.custom) ? (data.custom as SavedNotification[]) : [],
  };
}

/**
 * Send a notification to one patient.
 *
 * `operation` is a template id like `protein_day_today` or `custom:<nid>` for a
 * saved notification. The backend runs the review-flag, submission-tag and role
 * side-effects alongside the send.
 */
export async function sendNotification(targetUid: number, operation: string): Promise<void> {
  const response = await fetch("/api/messages/notifications", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ operation, target_uid: targetUid }),
  });
  const body = await response.json().catch(() => ({}));
  if (!response.ok) {
    throw new NotificationRequestError(
      typeof body.message_text === "string"
        ? body.message_text
        : "Unable to send notification. Please try again.",
    );
  }
}

/** Create a custom notification for the doctor's clinic. */
export async function saveCustomNotification(
  title: string,
  message: string,
): Promise<SavedNotification> {
  const response = await fetch("/api/messages/notifications/custom", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ title, message }),
  });
  const body = await response.json().catch(() => ({}));
  if (!response.ok || !body.success) {
    throw new NotificationRequestError(
      typeof body.message_text === "string"
        ? body.message_text
        : "Unable to save. Please try again.",
    );
  }
  return body.notification as SavedNotification;
}

/** Update one of the doctor's saved notifications in place. */
export async function updateCustomNotification(
  nid: number,
  title: string,
  message: string,
): Promise<SavedNotification> {
  const response = await fetch(`/api/messages/notifications/custom/${nid}/edit`, {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ title, message }),
  });
  const body = await response.json().catch(() => ({}));
  if (!response.ok || !body.success) {
    throw new NotificationRequestError(
      typeof body.message_text === "string"
        ? body.message_text
        : "Unable to save. Please try again.",
    );
  }
  return body.notification as SavedNotification;
}

/** Delete one of the doctor's saved notifications. */
export async function deleteCustomNotification(nid: number): Promise<void> {
  const response = await fetch(`/api/messages/notifications/custom/${nid}`, {
    method: "POST",
  });
  if (!response.ok) {
    throw new NotificationRequestError("Unable to delete notification.");
  }
}