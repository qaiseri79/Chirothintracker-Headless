"use client";

import { useCallback, useEffect, useState } from "react";
import { MessageThread } from "@/components/portal/messages/message-thread";
import {
  fetchThread,
  markConversationRead,
  markMessageRead,
  sendMessage,
} from "@/lib/messages/client";
import { applyAllRead, applyOneRead, applySent } from "@/lib/messages/update";
import type { Conversation } from "@/lib/messages/types";
import { useSummaryData } from "./summary-data-provider";

/**
 * The workspace panel's Messages tab: this patient's thread with the doctor.
 *
 * The thread itself is the portal's shared one — the same component, endpoints
 * and optimistic updates `/chiropractor/messages` uses — so bubbles,
 * attachments, search, drag-and-drop and the composer are not re-implemented
 * here. This component only reads the one conversation for `patientId`, holds
 * it, and answers the thread's callbacks.
 *
 * A patient the doctor has never written to answers 404 from `fetchThread`,
 * and the tab substitutes an empty conversation named for the patient so the
 * thread's own empty state ("Start your conversation with …") reads correctly
 * and the first send has a conversation to land in.
 *
 * The thread is bounded to the design's `calc(100vh - 13rem)` so its messages
 * scroll inside the panel rather than with the panel body.
 */

/** The conversation a 404 stands for: these two have not written to each other. */
function emptyConversation(patientId: number, patientName: string): Conversation {
  return {
    partner_uid: patientId,
    partner_name: patientName,
    partner_role: "Patient",
    messages: [],
    unread_count: 0,
    last_message_time: null,
    last_message_preview: "",
    last_message_time_formatted: "",
  };
}

export function MessagesTab({
  patientId,
  patientName,
  onCountChange,
}: {
  patientId: number;
  patientName: string;
  /** Reports the thread's length for the tab bar's badge. */
  onCountChange?: (count: number) => void;
}) {
  const { readOnly } = useSummaryData();
  const [conversation, setConversation] = useState<Conversation | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [sending, setSending] = useState(false);
  const [searchQuery, setSearchQuery] = useState("");
  /** Bumped by Retry; part of the read effect's key so it re-fetches. */
  const [attempt, setAttempt] = useState(0);

  // The panel mounts this tab fresh for each patient (it unmounts whenever the
  // panel closes, and the tab passes a per-patient key), so the state below
  // only has to be reset by a Retry — in the click handler, not here.
  useEffect(() => {
    let cancelled = false;
    fetchThread(patientId)
      .then((thread) => {
        if (!cancelled) setConversation(thread ?? emptyConversation(patientId, patientName));
      })
      .catch(() => {
        if (!cancelled) setError("Unable to load this conversation.");
      })
      .finally(() => {
        if (!cancelled) setLoading(false);
      });
    return () => {
      cancelled = true;
    };
  }, [patientId, patientName, attempt]);

  /** A failed read starts over: clear the failure and read the thread again. */
  function retry() {
    setAttempt((current) => current + 1);
    setLoading(true);
    setError(null);
    setConversation(null);
    setSearchQuery("");
  }

  // The tab badge follows the thread, the way the other tabs' counts follow
  // the sections they were built from. Unmounting leaves the last count in
  // place, so switching tabs does not drop the badge.
  useEffect(() => {
    onCountChange?.(conversation?.messages.length ?? 0);
  }, [conversation, onCountChange]);

  const handleSend = useCallback(
    async (text: string, file?: File) => {
      if (readOnly) return;
      setSending(true);
      try {
        const sent = await sendMessage(patientId, text, file);
        setConversation((current) =>
          current ? applySent(current, sent, file?.name ?? "Attachment") : current,
        );
      } finally {
        setSending(false);
      }
    },
    [patientId, readOnly],
  );

  const handleMarkAllRead = useCallback(async () => {
    if (!(await markConversationRead(patientId))) return;
    setConversation((current) => (current ? applyAllRead(current) : current));
  }, [patientId]);

  const handleMarkOneRead = useCallback(async (messageId: number) => {
    if (!(await markMessageRead(messageId))) return;
    setConversation((current) => (current ? applyOneRead(current, messageId) : current));
  }, []);

  if (error) {
    return (
      <div className="rounded-2xl border border-line bg-white p-6">
        <p role="alert" className="text-sm text-destructive">{error}</p>
        <button
          type="button"
          onClick={retry}
          className="mt-3 text-sm font-semibold text-primary underline"
        >
          Retry
        </button>
      </div>
    );
  }

  return (
    <section className="flex h-[calc(100vh-13rem)] min-h-[440px] flex-col overflow-hidden rounded-2xl border border-line bg-canvas">
      <MessageThread
        conversation={conversation}
        loading={loading}
        readOnly={readOnly}
        sending={sending}
        searchQuery={searchQuery}
        onSearchQuery={setSearchQuery}
        onSend={handleSend}
        onMarkAllRead={handleMarkAllRead}
        onMarkOneRead={handleMarkOneRead}
        partnerFallback={patientName}
      />
    </section>
  );
}
