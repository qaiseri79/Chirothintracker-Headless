"use client";

import { useCallback, useEffect, useState } from "react";
import { useAuth } from "@/lib/auth";
import { useMessageUnread } from "@/components/providers/message-unread-provider";
import { resolvePortalAccess } from "@/lib/portal";
import {
  fetchConversations,
  markConversationRead,
  markMessageRead,
  sendMessage,
} from "@/lib/messages/client";
import { applyAllRead, applyOneRead, applySent } from "@/lib/messages/update";
import { MessageThread } from "@/components/portal/messages/message-thread";
import { patientStarterPrompts } from "@/components/portal/messages/empty-thread";
import type { Conversation } from "@/lib/messages/types";

/**
 * The patient's thread — the only one they have.
 *
 * A patient can message one provider, named on `user.field_chiropractor`, so
 * there is no list to choose from: `MessagesService::getConversations()` returns
 * exactly one conversation, including when it carries no messages yet, and this
 * page opens it. The chiropractor's equivalent is
 * `app/(portal)/chiropractor/messages`, which lists one conversation per patient.
 *
 * The thread, bubble, composer and empty state are shared with that page. What
 * lives here is the patient-specific state machine: load the single thread, and
 * the three mutations the patient can perform on it.
 */
export default function MessagesPage() {
  const { user } = useAuth();
  const { setUnreadCount } = useMessageUnread();
  const readOnly = resolvePortalAccess(user?.roles, user?.capabilities, user?.portalAccess)?.readOnly ?? true;

  const [conversation, setConversation] = useState<Conversation | null>(null);
  const [loading, setLoading] = useState(true);
  const [sending, setSending] = useState(false);
  const [searchQuery, setSearchQuery] = useState("");

  useEffect(() => {
    let cancelled = false;
    void (async () => {
      try {
        const data = await fetchConversations();
        if (!cancelled) setConversation(data[0] ?? null);
      } catch {
        // Left null: the thread renders its empty state, which is what a patient
        // with no reachable provider looks like. `onError` below surfaces the
        // failure where a send happens.
        if (!cancelled) setConversation(null);
      } finally {
        if (!cancelled) setLoading(false);
      }
    })();
    return () => {
      cancelled = true;
    };
  }, []);

  useEffect(() => {
    if (!loading && conversation) setUnreadCount(conversation.unread_count);
  }, [loading, conversation, setUnreadCount]);

  const handleSend = useCallback(
    async (text: string, file?: File) => {
      if (!conversation || readOnly) return;
      const target = conversation.partner_uid;
      setSending(true);
      try {
        const sent = await sendMessage(target, text, file);
        setConversation((prev) =>
          prev && prev.partner_uid === target
            ? applySent(prev, sent, file?.name ?? "Attachment")
            : prev,
        );
      } finally {
        setSending(false);
      }
    },
    [conversation, readOnly],
  );

  const handleMarkAllRead = useCallback(async () => {
    if (!conversation) return;
    const uid = conversation.partner_uid;
    // Only clear the badge once the server confirms. The endpoint answers 200
    // with `marked_read: 0` when there was nothing to mark, so a resolved promise
    // is the only signal that the write landed.
    if (!(await markConversationRead(uid))) return;
    setConversation((prev) => (prev ? applyAllRead(prev) : prev));
  }, [conversation]);

  const handleMarkOneRead = useCallback(async (messageId: number) => {
    if (!conversation) return;
    if (!(await markMessageRead(messageId))) return;
    setConversation((prev) => (prev ? applyOneRead(prev, messageId) : prev));
  }, [conversation]);

  return (
    <MessageThread
      conversation={conversation}
      showUnreadCount
      loading={loading}
      readOnly={readOnly}
      sending={sending}
      searchQuery={searchQuery}
      onSearchQuery={setSearchQuery}
      onSend={handleSend}
      onMarkAllRead={handleMarkAllRead}
      onMarkOneRead={handleMarkOneRead}
      starterPrompts={patientStarterPrompts(conversation?.partner_name ?? "")}
    />
  );
}
