"use client";

import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import {
  ChevronLeft,
  Send,
  X,
  Check,
  Search,
  Paperclip,
} from "lucide-react";
import { useAuth } from "@/lib/auth";
import { useMessageUnread } from "@/components/providers/message-unread-provider";
import { resolvePortalAccess } from "@/lib/portal";
import {
  fetchConversations,
  fetchThread,
  markConversationRead,
  markMessageRead,
  sendMessage,
  sendMassMessage,
} from "@/lib/messages/client";
import {
  applyAllRead,
  applyOneRead,
  applySent,
  previewOf,
  replaceConversation,
} from "@/lib/messages/update";
import { PatientList } from "@/components/portal/messages/patient-list";
import { MessageThread } from "@/components/portal/messages/message-thread";
import { InitialsAvatar } from "@/components/avatar";
import type { Conversation } from "@/lib/messages/types";

/**
 * The chiropractor's messages: a list of patients beside the selected thread.
 *
 * Mirrors the "ChiroThin — Doctor Messages" design layout:
 *   • Left sidebar (sm:w-80) with patient list, search, tabs
 *   • Header bar (sticky, brand + user initials)
 *   • Thread view area
 *
 * The outermost PortalShell sidebar/header have been removed to eliminate
 * the duplicate navigation bars reported by the user.  The component
 * renders the inline design layout that the design HTML specifies.
 */
export default function ChiropractorMessagesPage() {
  const { user } = useAuth();
  const { setUnreadCount } = useMessageUnread();
  const readOnly = resolvePortalAccess(user?.roles, user?.capabilities, user?.portalAccess)?.readOnly ?? true;

  const [conversations, setConversations] = useState<Conversation[]>([]);
  const [selectedUid, setSelectedUid] = useState<number | null>(null);
  const [selectedConversation, setSelectedConversation] = useState<Conversation | null>(null);
  const [loading, setLoading] = useState(true);
  const [threadLoading, setThreadLoading] = useState(false);
  const [sending, setSending] = useState(false);
  const [searchQuery, setSearchQuery] = useState("");
  const [filter, setFilter] = useState<"all" | "unread">("all");
  const [patientQuery, setPatientQuery] = useState("");
  const [showThread, setShowThread] = useState(false);
  const [showMassModal, setShowMassModal] = useState(false);
  const [massMode, setMassMode] = useState<"all" | "unread" | "pick">("all");
  const [massPicked, setMassPicked] = useState<Set<number>>(new Set());
  const [massQuery, setMassQuery] = useState("");
  const [massText, setMassText] = useState("");
  const [massAttachments, setMassAttachments] = useState<File[]>([]);
  const [massConfirm, setMassConfirm] = useState(false);
  const [massSending, setMassSending] = useState(false);
  const [toastMessage, setToastMessage] = useState<string | null>(null);
  const massFileInputRef = useRef<HTMLInputElement>(null);

  const userInitials = useMemo(() => {
    if (!user?.name) return "DR";
    return user.name
      .split(" ")
      .map((w) => w[0])
      .slice(0, 2)
      .join("")
      .toUpperCase();
  }, [user?.name]);

  const massRecipients = useMemo(() => {
    if (massMode === "all") return conversations;
    if (massMode === "unread") return conversations.filter((c) => c.unread_count > 0);
    return conversations.filter((c) => massPicked.has(c.partner_uid));
  }, [conversations, massMode, massPicked]);

  const massHasContent = massText.trim() || massAttachments.length > 0;

  function openMassModal() {
    setMassMode("all");
    setMassPicked(new Set());
    setMassQuery("");
    setMassText("");
    setMassAttachments([]);
    setMassConfirm(false);
    setShowMassModal(true);
  }

  function closeMassModal() {
    setShowMassModal(false);
    setMassAttachments([]);
  }

  function handleMassAttachClick() {
    massFileInputRef.current?.click();
  }

  function handleMassFileChange(e: React.ChangeEvent<HTMLInputElement>) {
    const files = Array.from(e.target.files || []);
    if (files.length > 0) {
      setMassAttachments((prev) => [...prev, ...files].slice(0, 10));
    }
    if (e.target) e.target.value = "";
  }

  async function handleMassSend() {
    if (!massHasContent || massRecipients.length === 0) return;
    if (!massConfirm) {
      setMassConfirm(true);
      return;
    }

    setMassSending(true);
    try {
      const uids = massRecipients.map((c) => c.partner_uid);
      const result = await sendMassMessage(uids, massText, massAttachments);

      // The backend returns the stored message for every recipient it reached,
      // so each row and the open thread get the real id, real attachment fids
      // and the real `{first_name}` substitution. Faking these locally was the
      // source of the id/fid drift this replaces.
      const sentByUid = new Map(result.sent.map((s) => [s.uid, s.message]));

      const withSent = (conv: Conversation) => {
        const sent = sentByUid.get(conv.partner_uid);
        return sent ? applySent(conv, sent, previewOf(sent, "")) : conv;
      };

      setConversations((prev) => prev.map(withSent));
      setSelectedConversation((current) => (current ? withSent(current) : current));

      setToastMessage(
        result.failed.length > 0
          ? `Sent to ${result.sent.length} of ${result.requested} patients. ${result.failed.length} could not be reached.`
          : `Mass message sent to ${result.sent.length} patient${result.sent.length === 1 ? "" : "s"}`,
      );
      closeMassModal();
    } catch (error) {
      setToastMessage(
        error instanceof Error && error.message
          ? error.message
          : "Failed to send mass message. Please try again.",
      );
    } finally {
      setMassSending(false);
    }
  }

  useEffect(() => {
    let cancelled = false;
    void (async () => {
      try {
        const data = await fetchConversations(true);
        if (cancelled) return;
        setConversations(data);
        setLoading(false);
        const firstUid = data[0]?.partner_uid ?? null;
        setSelectedUid(firstUid);
        if (firstUid) {
          setThreadLoading(true);
          const thread = await fetchThread(firstUid);
          if (!cancelled) {
            setSelectedConversation(thread);
            setThreadLoading(false);
          }
        }
      } catch {
        if (!cancelled) setConversations([]);
      } finally {
        if (!cancelled) setLoading(false);
      }
    })();
    return () => {
      cancelled = true;
    };
  }, []);

  // Auto-dismiss toast after 3.5s
  useEffect(() => {
    if (!toastMessage) return;
    const timer = setTimeout(() => setToastMessage(null), 3500);
    return () => clearTimeout(timer);
  }, [toastMessage]);

  const unreadTotal = useMemo(
    () => conversations.filter((c) => c.unread_count > 0).length,
    [conversations],
  );

  useEffect(() => {
    if (!loading && conversations.length > 0) {
      setUnreadCount(conversations.reduce((total, conversation) => total + conversation.unread_count, 0));
    }
  }, [loading, conversations, setUnreadCount]);

  const patchSelected = useCallback(
    (update: (conversation: Conversation) => Conversation) => {
      setConversations((prev) => {
        const current = prev.find((c) => c.partner_uid === selectedUid);
        if (!current) return prev;
        return replaceConversation(prev, update(current));
      });
      setSelectedConversation((prev) => {
        if (!prev || prev.partner_uid !== selectedUid) return prev;
        return update(prev);
      });
    },
    [selectedUid],
  );

  function handleSelect(partnerUid: number) {
    setSelectedUid(partnerUid);
    setSearchQuery("");
    setShowThread(true);
    setThreadLoading(true);
    fetchThread(partnerUid).then((thread) => {
      setSelectedConversation(thread);
      setThreadLoading(false);
    }).catch(() => {
      setSelectedConversation(null);
      setThreadLoading(false);
    });
  }

  const handleSend = useCallback(
    async (text: string, file?: File) => {
      if (!selectedUid || readOnly) return;
      const target = selectedUid;
      setSending(true);
      try {
        const sent = await sendMessage(target, text, file);
        patchSelected((prev) => applySent(prev, sent, file?.name ?? "Attachment"));
      } finally {
        setSending(false);
      }
    },
    [patchSelected, readOnly, selectedUid],
  );

  const handleMarkAllRead = useCallback(async () => {
    if (!selectedUid) return;
    const uid = selectedUid;
    if (!(await markConversationRead(uid))) return;
    setConversations((prev) => {
      const current = prev.find((c) => c.partner_uid === uid);
      if (!current) return prev;
      return replaceConversation(prev, applyAllRead(current));
    });
    setSelectedConversation((current) =>
      current?.partner_uid === uid ? applyAllRead(current) : current,
    );
  }, [selectedUid]);

  const handleMarkOneRead = useCallback(
    async (messageId: number) => {
      if (!selectedUid) return;
      const uid = selectedUid;
      if (!(await markMessageRead(messageId))) return;
      setConversations((prev) => {
        const current = prev.find((c) => c.partner_uid === uid);
        if (!current) return prev;
        const thread = selectedConversation?.partner_uid === uid ? selectedConversation : current;
        return replaceConversation(prev, {
          ...applyOneRead(thread, messageId),
          search_text: current.search_text,
        });
      });
      setSelectedConversation((current) =>
        current?.partner_uid === uid ? applyOneRead(current, messageId) : current,
      );
    },
    [selectedUid, selectedConversation],
  );

  /**
   * Role label shown under the partner's name in the thread header.
   * The design displays "Week X · Started MM/DD" for a patient with messages,
   * or just "Patient" when the thread is empty.
   */
  function roleLabel(conversation: Conversation): string {
    const count = conversation.messages.length;
    if (count === 0) return "Patient";
    const last = conversation.last_message_time_formatted;
    return `${count} message${count === 1 ? "" : "s"}${last ? ` · ${last}` : ""}`;
  }

  return (
    <>
      <div className="flex h-full w-full overflow-hidden bg-background">
        <div className="flex flex-1 min-h-0 w-full overflow-hidden">
          {/* `min-h-0` lets the PatientList below be bounded by this frame
              instead of by its own content, so its list scrolls. */}
          <div className="flex min-h-0 w-80 shrink-0 flex-col border-r border-line bg-surface">
            <div className="p-4">
              <button type="button" onClick={openMassModal} className="flex w-full items-center justify-center gap-2 rounded-lg bg-primary px-4 py-2.5 text-sm font-semibold text-white hover:bg-primary-dark">
                <Send className="h-4 w-4" />
                Mass Message
              </button>
            </div>
            <PatientList
              conversations={conversations}
              selectedUid={selectedUid}
              onSelect={handleSelect}
              filter={filter}
              onFilter={setFilter}
              query={patientQuery}
              onQuery={setPatientQuery}
              unreadTotal={unreadTotal}
              loading={loading}
            />
          </div>

          <div className="relative flex min-w-0 flex-1 flex-col bg-canvas">
            <MessageThread
              conversation={selectedConversation}
              showUnreadCount
              loading={loading || threadLoading}
              readOnly={readOnly}
              sending={sending}
              searchQuery={searchQuery}
              onSearchQuery={setSearchQuery}
              onSend={handleSend}
              onMarkAllRead={handleMarkAllRead}
              onMarkOneRead={handleMarkOneRead}
              partnerFallback="Select a patient"
              subtitle={selectedConversation ? roleLabel(selectedConversation) : undefined}
            />
          </div>
        </div>
      </div>

      {showMassModal && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
          <div className="flex max-h-full w-full max-w-xl flex-col overflow-hidden rounded-2xl bg-surface shadow-panel">
            <div className="flex items-center justify-between border-b border-line px-5 py-4">
              <div>
                <h2 className="font-serif text-lg">Mass Message</h2>
                <p className="text-xs text-muted-foreground">Each patient receives it in their own private thread.</p>
              </div>
              <button onClick={closeMassModal} aria-label="Close" className="rounded-lg p-1.5 text-muted-foreground hover:bg-canvas">
                <X className="h-5 w-5" />
              </button>
            </div>
            <div className="flex-1 space-y-4 overflow-y-auto p-5">
              <div>
                <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-muted-foreground">Recipients</p>
                <div className="grid grid-cols-3 gap-2 text-xs font-medium">
                  {[
                    ["all", `All patients (${conversations.length})`],
                    ["unread", `Unread replies (${conversations.filter((c) => c.unread_count > 0).length})`],
                    ["pick", "Choose..."],
                  ].map(([mode, label]) => (
                    <button
                      key={mode}
                      type="button"
                      onClick={() => {
                        setMassMode(mode as "all" | "unread" | "pick");
                        setMassConfirm(false);
                      }}
                      className={`rounded-lg border px-2 py-2 ${
                        massMode === mode
                          ? "border-primary bg-primary-soft text-primary"
                          : "border-line text-foreground/70 hover:bg-canvas"
                      }`}
                    >
                      {label}
                    </button>
                  ))}
                </div>
                {massMode === "pick" && (
                  <div className="mt-3 rounded-lg border border-line">
                    <div className="flex items-center gap-2 border-b border-line p-2">
                      <input
                        id="massQuery"
                        type="text"
                        placeholder="Filter patients..."
                        value={massQuery}
                        onChange={(e) => setMassQuery(e.target.value)}
                        className="min-w-0 flex-1 rounded-md bg-canvas px-2.5 py-1.5 text-xs focus:outline-none focus:ring-1 focus:ring-primary"
                      />
                      <button
                        type="button"
                        onClick={() => {
                          const list = conversations.filter((c) =>
                            c.partner_name.toLowerCase().includes(massQuery.toLowerCase()),
                          );
                          const allPicked = list.every((c) => massPicked.has(c.partner_uid));
                          list.forEach((c) => (allPicked ? massPicked.delete(c.partner_uid) : massPicked.add(c.partner_uid)));
                          setMassPicked(new Set(massPicked));
                          setMassConfirm(false);
                        }}
                        className="shrink-0 text-xs font-medium text-primary hover:underline"
                      >
                        {conversations.filter((c) => c.partner_name.toLowerCase().includes(massQuery.toLowerCase())).every((c) => massPicked.has(c.partner_uid)) && conversations.filter((c) => c.partner_name.toLowerCase().includes(massQuery.toLowerCase())).length ? "Deselect all" : "Select all"}
                      </button>
                    </div>
                    <div className="max-h-40 overflow-y-auto">
                      {conversations
                        .filter((c) => c.partner_name.toLowerCase().includes(massQuery.toLowerCase()))
                        .map((c) => (
                          <label key={c.partner_uid} className="flex cursor-pointer items-center gap-3 border-b border-line px-3 py-2 last:border-0 hover:bg-canvas/60">
                            <input
                              type="checkbox"
                              checked={massPicked.has(c.partner_uid)}
                              onChange={(e) => {
                                const next = new Set(massPicked);
                                e.target.checked ? next.add(c.partner_uid) : next.delete(c.partner_uid);
                                setMassPicked(next);
                                setMassConfirm(false);
                              }}
                              className="h-4 w-4 accent-primary"
                            />
                            <InitialsAvatar name={c.partner_name} className="h-7 w-7 text-[10px]" />
                            <span className="text-sm">{c.partner_name}</span>
                          </label>
                        ))}
                    </div>
                  </div>
                )}
              </div>
              <div>
                <div className="mb-2 flex items-center justify-between">
                  <p className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Message</p>
                  <button
                    type="button"
                    onClick={() => {
                      setMassText((prev) => prev + "{first_name}");
                      setMassConfirm(false);
                    }}
                    className="rounded-full border border-line px-2.5 py-0.5 text-[11px] font-medium text-primary hover:bg-primary-soft"
                  >
                    + Insert first name
                  </button>
                </div>
                <div className="rounded-xl border border-line bg-canvas focus-within:border-primary focus-within:ring-1 focus-within:ring-primary">
                  {massAttachments.length > 0 && (
                    <div className="flex flex-wrap gap-2 p-2.5 pb-0 border-b border-line">
                      {massAttachments.map((f, i) => (
                        <div key={i} className="flex items-center gap-2 rounded-lg border border-line bg-surface py-1.5 pl-1.5 pr-1 shadow-panel">
                          <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg text-[10px] font-bold tracking-wide text-white bg-primary">
                            {f.name.split(".").pop()?.toUpperCase().slice(0, 3)}
                          </span>
                          <span className="min-w-0">
                            <span className="block truncate text-xs font-medium">{f.name}</span>
                            <span className="block text-[11px] text-muted-foreground">{(f.size / 1024).toFixed(1)} KB</span>
                          </span>
                          <button
                            type="button"
                            onClick={() => setMassAttachments((prev) => prev.filter((_, idx) => idx !== i))}
                            className="flex h-6 w-6 shrink-0 items-center justify-center rounded-full text-muted-foreground hover:bg-flame-soft hover:text-flame"
                          >
                            <X className="h-3.5 w-3.5" />
                          </button>
                        </div>
                      ))}
                      {massAttachments.length > 1 && (
                        <button type="button" onClick={() => setMassAttachments([])} className="self-center px-1 text-[11px] font-medium text-muted-foreground hover:text-flame">
                          Clear all
                        </button>
                      )}
                    </div>
                  )}
                  <div className="flex items-end gap-1.5 p-2">
                    <input
                      ref={massFileInputRef}
                      type="file"
                      multiple
                      accept=".pdf,.jpg,.jpeg,.png,.gif,.webp,.heic,.doc,.docx,.xls,.xlsx,.csv,.txt"
                      onChange={handleMassFileChange}
                      className="hidden"
                      disabled={massAttachments.length >= 10}
                    />
                    <button
                      type="button"
                      onClick={handleMassAttachClick}
                      disabled={massAttachments.length >= 10}
                      aria-label="Attach files"
                      className="relative flex h-9 w-9 shrink-0 items-center justify-center rounded-lg text-muted-foreground hover:bg-primary-soft hover:text-primary disabled:opacity-50"
                    >
                      <Paperclip className="h-[18px] w-[18px]" />
                      {massAttachments.length > 0 && (
                        <span className="absolute -right-0.5 -top-0.5 flex h-4 min-w-4 items-center justify-center rounded-full bg-primary px-1 text-[10px] font-semibold text-white">
                          {massAttachments.length}
                        </span>
                      )}
                    </button>
                    <textarea
                      rows={4}
                      placeholder="Hi {first_name}, ..."
                      value={massText}
                      onChange={(e) => {
                        setMassText(e.target.value);
                        setMassConfirm(false);
                      }}
                      className="flex-1 resize-none bg-transparent px-2 py-2 text-sm focus:outline-none"
                    />
                  </div>
                </div>
                <p className="mt-1.5 text-[11px] text-muted-foreground">Use <code className="rounded bg-canvas px-1">{"{first_name}"}</code> to personalize. Up to 10 files, 10 MB each.</p>
              </div>
            </div>
            <div className="flex items-center justify-between gap-3 border-t border-line bg-canvas/60 px-5 py-3.5">
              <p className="text-xs text-muted">{massRecipients.length} patient{massRecipients.length > 1 ? "s" : ""} will receive this</p>
              <div className="flex shrink-0 gap-2">
                <button onClick={closeMassModal} className="rounded-lg px-3.5 py-2 text-sm font-medium text-muted hover:bg-canvas">
                  Cancel
                </button>
                <button
                  onClick={handleMassSend}
                  disabled={!massHasContent || massRecipients.length === 0 || massSending}
                  className={`rounded-lg px-4 py-2 text-sm font-semibold text-white ${
                    massSending
                      ? "bg-primary/70 cursor-wait"
                      : massConfirm
                      ? "bg-flame hover:bg-flame/90"
                      : "bg-primary hover:bg-primary-dark"
                  } disabled:cursor-not-allowed disabled:opacity-40`}
                >
                  {massSending ? "Sending..." : massConfirm ? `Confirm: send to ${massRecipients.length}` : "Review & send"}
                </button>
              </div>
            </div>
          </div>
        </div>
      )}
      {toastMessage && (
        <div className="pointer-events-none fixed bottom-6 left-1/2 z-50 -translate-x-1/2 rounded-full bg-ink px-4 py-2 text-sm text-white shadow-panel">
          {toastMessage}
        </div>
      )}
    </>
  );
}