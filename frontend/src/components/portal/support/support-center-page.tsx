"use client";

import { useState } from "react";
import { LifeBuoy, FileText } from "lucide-react";
import { LiveQaBanner } from "@/components/portal/support/live-qa-banner";
import { SupportFormEmbed } from "@/components/portal/support/support-form-embed";
import { UserGuideViewer } from "@/components/portal/support/user-guide-viewer";

/**
 * The Support Center, as two tabs.
 *
 * Ported from "New Design/ChiroThin — Support Center.html". The design used a hash
 * route (`help` / `guide`) driven by `go()` and a sidebar sub-menu that both switched
 * the tab; here it is component state, because the sidebar is the real portal shell's
 * and duplicating nested sub-menus inside it would be a nav pattern this app does not
 * otherwise use. The tab strip from the design is kept as-is.
 *
 * Content per tab:
 *
 * - **Help desk** — the live-session banner (copied faithfully, see `LiveQaBanner`)
 *   and the ticket form, which is GoHighLevel's, not ours.
 * - **User guide** — the embedded PDF, see `UserGuideViewer`.
 *
 * Mounting only the visible tab matters for the guide specifically: it builds an
 * iframe pointing at a real PDF, and rendering that for a chiropractor who never opens
 * the tab is a download they did not ask for.
 */

type Tab = "help" | "guide";

const TABS = [
  { id: "help", label: "Help desk", icon: LifeBuoy, title: "How can we help?", blurb: "Join our weekly live session, or send us a ticket and we'll get back to you." },
  { id: "guide", label: "User guide", icon: FileText, title: "User guide", blurb: "Step-by-step help for you and your clinic team." },
] as const satisfies readonly { id: Tab; label: string; icon: typeof LifeBuoy; title: string; blurb: string }[];

export function SupportCenterPage() {
  const [tab, setTab] = useState<Tab>("help");
  const active = TABS.find((entry) => entry.id === tab) ?? TABS[0];

  return (
    <div>
      <div className="mx-auto max-w-5xl">
        <div className="mb-5">
          <h1 className="font-serif text-2xl text-foreground">{active.title}</h1>
          <p className="mt-1 text-sm text-muted-foreground">{active.blurb}</p>
        </div>

        <div
          role="tablist"
          aria-label="Support Center"
          className="mb-6 inline-flex rounded-xl border border-line bg-surface p-1 shadow-panel"
        >
          {TABS.map(({ id, label, icon: Icon }) => {
            const selected = id === tab;
            return (
              <button
                key={id}
                type="button"
                role="tab"
                id={`support-tab-${id}`}
                aria-selected={selected}
                aria-controls={`support-panel-${id}`}
                onClick={() => setTab(id)}
                className={[
                  "inline-flex items-center gap-2 rounded-lg px-4 py-2 text-sm font-semibold transition",
                  selected
                    ? "bg-primary text-white"
                    : "text-foreground/70 hover:bg-primary-soft hover:text-primary",
                ].join(" ")}
              >
                <Icon className="h-4 w-4 shrink-0" aria-hidden="true" />
                {label}
              </button>
            );
          })}
        </div>

        {tab === "help" ? (
          <div role="tabpanel" id="support-panel-help" aria-labelledby="support-tab-help">
            <LiveQaBanner />
            <div className="mt-6 grid gap-6 lg:grid-cols-3">
              <SupportFormEmbed />
            </div>
          </div>
        ) : null}

        {tab === "guide" ? (
          <div role="tabpanel" id="support-panel-guide" aria-labelledby="support-tab-guide">
            <UserGuideViewer />
          </div>
        ) : null}
      </div>
    </div>
  );
}