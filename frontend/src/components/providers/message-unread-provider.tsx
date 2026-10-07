"use client";

import { createContext, useCallback, useContext, useEffect, useRef, useState } from "react";
import { useAuth } from "@/lib/auth";
import { resolvePortalAccess } from "@/lib/portal";

interface MessageUnreadContextValue {
  unreadCount: number | null;
  setUnreadCount: (count: number) => void;
}

const MessageUnreadContext = createContext<MessageUnreadContextValue | null>(null);

export function MessageUnreadProvider({ children }: { children: React.ReactNode }) {
  const { user, status } = useAuth();
  const email = user?.email ?? null;
  const hasPortalAccess = status === "authenticated"
    && resolvePortalAccess(user?.roles, user?.capabilities, user?.portalAccess) !== null;
  const [snapshot, setSnapshot] = useState<{ email: string; count: number } | null>(null);
  const revision = useRef(0);

  const setUnreadCount = useCallback((count: number) => {
    if (!email || !hasPortalAccess) return;
    revision.current++;
    setSnapshot({ email, count: Math.max(0, count) });
  }, [email, hasPortalAccess]);

  useEffect(() => {
    if (!email || !hasPortalAccess) return;
    const controller = new AbortController();
    async function refresh() {
      const startedAtRevision = revision.current;
      try {
        const response = await fetch("/api/messages/unread-count", {
          cache: "no-store",
          signal: controller.signal,
        });
        if (!response.ok) return;
        const data = await response.json();
        // A pending count request must not undo a newer read receipt.
        if (!controller.signal.aborted && startedAtRevision === revision.current
          && Number.isInteger(data.unread_count) && data.unread_count >= 0) {
          setSnapshot({ email: email!, count: data.unread_count });
        }
      } catch {
        // Keep the last known count when a refresh is unavailable.
      }
    }
    void refresh();
    const onFocus = () => { void refresh(); };
    window.addEventListener("focus", onFocus);
    return () => {
      controller.abort();
      window.removeEventListener("focus", onFocus);
    };
  }, [email, hasPortalAccess]);

  const unreadCount = hasPortalAccess && snapshot?.email === email ? snapshot.count : null;
  return (
    <MessageUnreadContext.Provider value={{ unreadCount, setUnreadCount }}>
      {children}
    </MessageUnreadContext.Provider>
  );
}

export function useMessageUnread(): MessageUnreadContextValue {
  const value = useContext(MessageUnreadContext);
  if (!value) throw new Error("useMessageUnread must be used within MessageUnreadProvider.");
  return value;
}
