"use client";

import { useEffect, useState } from "react";
import {
  AlertTriangle,
  Apple,
  Beef,
  Droplets,
  FileText,
  GraduationCap,
  Heart,
  MessageCircle,
  MessageSquare,
  Moon,
  Pencil,
  Scale,
  Utensils,
  Users,
  X,
  type LucideIcon,
} from "lucide-react";
import { Dialog, DialogContent, DialogDescription, DialogTitle } from "@/components/ui/dialog";
import { BTN_CANCEL, FORM_ERROR } from "../../content-forms/content-form-primitives";
import {
  deleteCustomNotification,
  fetchNotificationBank,
  saveCustomNotification,
  sendNotification,
  updateCustomNotification,
  type NotificationBank,
  type SavedNotification,
} from "@/lib/messages/notifications";
import type { PatientSummaryRow } from "@/lib/patients/summary";

/** Icons per template id; anything unmapped (and saved customs) use the fallback. */
const TEMPLATE_ICONS: Record<string, LucideIcon> = {
  default_review: MessageSquare,
  constipation: Droplets,
  please_log_food: Scale,
  skipped_meal: Utensils,
  vary_food_choices: Utensils,
  inadequate_sleep: Moon,
  no_changes_review: FileText,
  protein_day_today: Beef,
  protein_day_tomorrow: Beef,
  apply_day_today: Apple,
  apply_day_tomorrow: Apple,
  having_problems: AlertTriangle,
  low_adherence: AlertTriangle,
  water_intake: Droplets,
  proud_of_you: Heart,
  ask_for_referral: Users,
  graduated: GraduationCap,
  we_miss_you: MessageCircle,
};

/** One sendable card: a predefined template or a doctor's saved notification. */
type BankItem =
  | { kind: "template"; key: string; label: string; body: string; operation: string; nid?: undefined }
  | { kind: "custom"; key: string; label: string; body: string; operation: string; nid: number };

const EMPTY_BANK: NotificationBank = { templates: [], custom: [] };

/**
 * The first `max` sentences of an HTML fragment, with markup kept balanced.
 *
 * The template bodies are real HTML (paragraphs, lists, links), so cropping
 * plain text would show raw tags or split a sentence mid-word. This walks the
 * parsed DOM's text nodes, cuts after the nth sentence-ending character (`.`,
 * `!`, `?` when they end the text or are followed by whitespace, plus bullets
 * and hard newlines so the plain-newline questionnaires break cleanly too) and
 * drops everything that follows, keeping the surrounding tags intact.
 */
function excerptHtml(markup: string, max = 2): string {
  const target = Math.max(1, max);
  const doc = new DOMParser().parseFromString(markup || "", "text/html");
  const body = doc.body;

  const isBoundary = (text: string, index: number): boolean => {
    const char = text[index];
    if (char === "\n" || char === "•") return true;
    if (char === "*" && (index === 0 || text[index - 1] === "\n")) return true;
    if (char !== "." && char !== "!" && char !== "?") return false;
    const next = text[index + 1];
    return next === undefined || /\s/.test(next);
  };

  let cutNode: Text | null = null;
  let count = 0;
  const walker = doc.createTreeWalker(body, NodeFilter.SHOW_TEXT);
  let node: Node | null;
  while ((node = walker.nextNode())) {
    const text = node as Text;
    const value = text.data;
    let cut = -1;
    for (let i = 0; i < value.length; i += 1) {
      if (!isBoundary(value, i)) continue;
      count += 1;
      if (count < target) continue;
      cut = i + 1;
      break;
    }
    if (cut < 0) continue;
    text.data = value.slice(0, cut);
    cutNode = text;
    break;
  }

  // Every sibling after the cut belongs to a sentence we no longer want: remove
  // it at each ancestor level (the ancestors themselves stay — they still hold
  // the kept text).
  if (cutNode) {
    let ancestor: Node | null = cutNode;
    while (ancestor && ancestor !== body) {
      let sibling = ancestor.nextSibling;
      while (sibling) {
        const next = sibling.nextSibling;
        sibling.parentNode?.removeChild(sibling);
        sibling = next;
      }
      ancestor = ancestor.parentNode;
    }
  }

  return body.innerHTML;
}

/**
 * The design's `notify(p)` modal — "Send notification": a grid of pre-written
 * notifications; clicking one sends it to the patient through the same message
 * system they already use. The bank comes from `/api/messages/notifications`
 * (Drupal's `headless_message` templates + the doctor's `review_messages`), so
 * editing a template or saving one is reflected here on the next open.
 */
export function SendNotificationDialog({ patient, onClose, showToast }: {
  patient: PatientSummaryRow;
  onClose: () => void;
  showToast?: (message: string) => void;
}) {
  const firstName = patient.name.split(" ")[0] ?? patient.name;
  const [bank, setBank] = useState<NotificationBank>(EMPTY_BANK);
  const [loading, setLoading] = useState(true);
  const [newOpen, setNewOpen] = useState(false);
  const [editing, setEditing] = useState<SavedNotification | null>(null);
  const [newTitle, setNewTitle] = useState("");
  const [newBody, setNewBody] = useState("");
  const [sending, setSending] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    let cancelled = false;
    fetchNotificationBank()
      .then((next) => {
        if (!cancelled) setBank(next);
      })
      .catch((failure: unknown) => {
        if (cancelled) return;
        setError(failure instanceof Error ? failure.message : "Unable to load notifications.");
      })
      .finally(() => {
        if (!cancelled) setLoading(false);
      });
    return () => {
      cancelled = true;
    };
  }, []);

  function personalize(body: string): string {
    return body.replaceAll("{name}", firstName);
  }

  async function sendTemplate(item: BankItem) {
    if (sending) return;
    setSending(true);
    setError(null);
    try {
      await sendNotification(patient.id, item.operation);
      showToast?.(`"${item.label}" sent to ${patient.name}`);
      onClose();
    } catch (failure) {
      setError(failure instanceof Error ? failure.message : "Unable to send notification. Please try again.");
      setSending(false);
    }
  }

  async function saveToMyList() {
    const title = newTitle.trim();
    const body = newBody.trim();
    if (!title || !body) {
      setError("Add a name and a message first.");
      return;
    }
    setError(null);
    try {
      if (editing) {
        const saved = await updateCustomNotification(editing.nid, title, body);
        setBank((current) => ({
          ...current,
          custom: current.custom.map((item) => (item.nid === saved.nid ? saved : item)),
        }));
        showToast?.("Changes saved");
      } else {
        const saved = await saveCustomNotification(title, body);
        setBank((current) => ({ ...current, custom: [saved, ...current.custom] }));
        showToast?.("Saved to your notifications");
      }
      setNewOpen(false);
      setEditing(null);
      setNewTitle("");
      setNewBody("");
    } catch (failure) {
      setError(failure instanceof Error ? failure.message : "Unable to save. Please try again.");
    }
  }

  /** Loads a saved notification into the editor for in-place updates. */
  function startEditing(item: BankItem) {
    if (item.kind !== "custom") return;
    setEditing({ nid: item.nid, title: item.label, body: item.body });
    setNewTitle(item.label);
    setNewBody(item.body);
    setNewOpen(true);
    setError(null);
  }

  /** Opens/closes the editor, discarding any in-progress edit when closed. */
  function toggleEditor() {
    if (newOpen) {
      setNewOpen(false);
      setEditing(null);
      setNewTitle("");
      setNewBody("");
      setError(null);
    } else {
      setNewOpen(true);
      setError(null);
    }
  }

  function closeEditor() {
    setNewOpen(false);
    setEditing(null);
    setNewTitle("");
    setNewBody("");
    setError(null);
  }

  async function removeCustom(nid: number) {
    if (sending) return;
    setError(null);
    try {
      await deleteCustomNotification(nid);
      setBank((current) => ({ ...current, custom: current.custom.filter((item) => item.nid !== nid) }));
    } catch (failure) {
      setError(failure instanceof Error ? failure.message : "Unable to delete notification.");
    }
  }

  const items: BankItem[] = [
    ...bank.templates.map((template) => ({
      kind: "template" as const,
      key: `t:${template.id}`,
      label: template.label,
      body: template.body,
      operation: template.id,
    })),
    ...bank.custom.map((custom) => ({
      kind: "custom" as const,
      key: `c:${custom.nid}`,
      nid: custom.nid,
      label: custom.title,
      body: custom.body,
      operation: `custom:${custom.nid}`,
    })),
  ];

  return (
    <Dialog open onOpenChange={(next) => { if (!next && !sending) onClose(); }}>
      <DialogContent showCloseButton={false} overlayClassName="bg-[#101827]/40" className="font-sans max-h-[90vh] w-[calc(100vw-2rem)] max-w-xl gap-0 overflow-y-auto rounded-[16px] border-0 bg-surface p-6 shadow-xl sm:rounded-[16px]">
        <DialogTitle className="font-serif text-lg font-normal">Send notification to {patient.name}</DialogTitle>
        <DialogDescription className="sr-only">Pick a pre-written notification to send to {patient.name} immediately.</DialogDescription>
        <p className="mt-2 text-sm text-muted-foreground">
          Pick a notification to send it to {firstName} right away. Saved notifications are shared across all your patients.
        </p>

        {loading ? (
          <p className="mt-6 text-sm text-muted-foreground">Loading notifications…</p>
        ) : (
          <div className="mt-4 grid gap-2 sm:grid-cols-2">
            {items.map((item) => {
              const Icon = TEMPLATE_ICONS[item.operation] ?? MessageSquare;
              return (
                <div key={item.key} className="relative">
                  <div className="group/tip relative">
                    <button
                      type="button"
                      disabled={sending}
                      onClick={() => void sendTemplate(item)}
                      className="flex h-full w-full items-start gap-2.5 rounded-lg border border-line bg-surface px-3 py-2.5 text-left transition hover:border-primary hover:bg-primary-soft disabled:cursor-not-allowed disabled:opacity-60"
                    >
                      <Icon className="mt-0.5 size-4 shrink-0 text-primary" aria-hidden="true" />
                      <span className="min-w-0 pr-12">
                        <span className="block text-sm font-semibold text-foreground">{item.label}</span>
                        <div
                          className="whitespace-pre-line text-xs font-normal leading-5 text-muted-foreground [&_a]:break-all [&_a]:font-medium [&_a]:text-primary [&_a]:underline [&_p]:my-0 [&_ul]:my-1 [&_ul]:list-disc [&_ul]:pl-4"
                          dangerouslySetInnerHTML={{ __html: personalize(excerptHtml(item.body)) }}
                        />
                      </span>
                    </button>
                    <div
                      role="tooltip"
                      className="pointer-events-none absolute bottom-full left-0 z-30 mb-2 hidden max-h-72 w-80 max-w-[calc(100vw-4rem)] overflow-y-auto rounded-lg border border-line bg-surface p-3 shadow-xl group-hover/tip:block"
                    >
                      <div
                        className="whitespace-pre-line text-xs leading-5 text-foreground [&_p]:my-0 [&_a]:break-all [&_a]:font-medium [&_a]:text-primary [&_a]:underline [&_ul]:my-1 [&_ul]:list-disc [&_ul]:pl-4"
                        dangerouslySetInnerHTML={{ __html: personalize(item.body) }}
                      />
                    </div>
                  </div>
                  {item.kind === "custom" ? (
                    <div className="absolute right-1.5 top-1.5 flex items-center gap-0.5">
                      <button
                        type="button"
                        disabled={sending}
                        onClick={() => startEditing(item)}
                        title="Edit"
                        aria-label={`Edit ${item.label}`}
                        className="rounded p-1 text-xs text-muted-foreground hover:bg-primary-soft hover:text-primary disabled:opacity-50"
                      >
                        <Pencil className="size-3.5" aria-hidden="true" />
                      </button>
                      <button
                        type="button"
                        disabled={sending}
                        onClick={() => void removeCustom(item.nid)}
                        title="Delete"
                        aria-label={`Delete ${item.label}`}
                        className="rounded p-1 text-xs text-muted-foreground hover:bg-red-50 hover:text-red-600 disabled:opacity-50"
                      >
                        <X className="size-3.5" aria-hidden="true" />
                      </button>
                    </div>
                  ) : null}
                </div>
              );
            })}
            <button
              type="button"
              onClick={toggleEditor}
              className="flex min-h-[64px] items-center justify-center gap-2 rounded-lg border border-dashed border-primary/50 px-3 py-2.5 text-sm font-semibold text-primary hover:bg-primary-soft"
            >
              New notification
            </button>
          </div>
        )}

        {newOpen ? (
          <div className="mt-3 space-y-2 rounded-lg border border-dashed border-primary/40 bg-primary-soft/40 p-3">
            <input
              value={newTitle}
              onChange={(event) => setNewTitle(event.target.value)}
              placeholder="Name it, e.g. Fasting reminder"
              className="w-full rounded-xl border border-line bg-surface px-3.5 py-2 text-sm text-foreground focus:border-primary focus:outline-none"
            />
            <textarea
              rows={3}
              value={newBody}
              onChange={(event) => setNewBody(event.target.value)}
              placeholder="Write the message. Type {name} to insert the patient's first name."
              className="w-full resize-none rounded-xl border border-line bg-surface px-3.5 py-2 text-sm text-foreground focus:border-primary focus:outline-none"
            />
            <div className="flex justify-end gap-2">
              <button
                type="button"
                onClick={closeEditor}
                className="rounded-lg px-3 py-1.5 text-sm font-medium text-muted-foreground hover:bg-surface"
              >
                Cancel
              </button>
              <button
                type="button"
                onClick={() => void saveToMyList()}
                className="rounded-lg bg-primary px-4 py-1.5 text-sm font-semibold text-white hover:bg-primary-dark"
              >
                {editing ? "Save changes" : "Save to my list"}
              </button>
            </div>
          </div>
        ) : null}

        {error ? <p role="alert" className={FORM_ERROR}>{error}</p> : null}

        <div className="mt-6 flex justify-end border-t border-line pt-5">
          <button type="button" onClick={onClose} disabled={sending} className={BTN_CANCEL}>Close</button>
        </div>
      </DialogContent>
    </Dialog>
  );
}