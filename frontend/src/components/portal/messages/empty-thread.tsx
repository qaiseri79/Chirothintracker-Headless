/**
 * The "no messages yet" block, shared by both threads.
 *
 * The design's version names the counterpart — "Start your conversation with
 * Dr. Reese" for a patient, the same sentence pointed at a patient for a
 * chiropractor — because there is nothing else on screen to take the name from.
 * The starter prompts are the patient's only, since a provider opening a
 * conversation with a patient writes the first message from a standing set of
 * their own; a patient needs prompts because they do not know the thread exists.
 */

import { MessageSquare } from "lucide-react";

export function EmptyThread({
  counterpartName,
  counterpartLabel = "provider",
  starterPrompts,
  onStarter,
  readOnly,
  readOnlyMessage,
}: {
  /** Display name of the other party. Empty falls back to the generic wording. */
  counterpartName: string;
  /** Used in the heading when there is no name, e.g. "provider" or "patient". */
  counterpartLabel?: string;
  /** Tapping one seeds the reply box. Omitted where there are none. */
  starterPrompts?: { label: string; text: string }[];
  onStarter?: (text: string) => void;
  readOnly: boolean;
  /** Shown instead of the prompts when the account may not post. */
  readOnlyMessage?: string;
}) {
  const heading = counterpartName
    ? `Start your conversation with ${counterpartName}`
    : `Start your conversation${counterpartLabel ? ` with your ${counterpartLabel}` : ""}`;

  return (
    <div className="mx-auto flex h-full max-w-md flex-col items-center justify-center text-center">
      <div className="mb-5 flex h-16 w-16 items-center justify-center rounded-full bg-primary-soft text-primary">
        <MessageSquare className="h-7 w-7" aria-hidden="true" />
      </div>
      <h2 className="font-serif text-xl text-foreground">{heading}</h2>
      <p className="mt-2 text-sm leading-relaxed text-muted-foreground">
        No messages yet. Send a note about your progress, ask a question, or share lab results and
        other documents. Replies will appear here.
      </p>

      {readOnly ? (
        <p className="mt-6 rounded-full border border-line bg-surface px-3.5 py-1.5 text-xs font-medium text-muted-foreground shadow-panel">
          {readOnlyMessage ??
            "Your account can read this conversation but cannot send to it."}
        </p>
      ) : starterPrompts && starterPrompts.length > 0 && onStarter ? (
        <div className="mt-6 flex flex-wrap justify-center gap-2">
          {starterPrompts.map((starter) => (
            <button
              key={starter.label}
              type="button"
              onClick={() => onStarter(starter.text)}
              className="rounded-full border border-line bg-surface px-3.5 py-1.5 text-xs font-medium text-foreground/80 shadow-panel transition-colors hover:border-primary hover:text-primary"
            >
              {starter.label}
            </button>
          ))}
        </div>
      ) : null}
    </div>
  );
}

/**
 * The prompts a patient gets to start their own thread.
 *
 * Kept here so the wording lives with the block that shows it. The greeting is
 * interpolated so a reply never opens with a bare "Hello" on a named thread.
 */
export function patientStarterPrompts(providerName: string): { label: string; text: string }[] {
  const name = providerName.trim();
  const greeting = name ? `Hi ${name},` : "Hello,";
  return [
    { label: "Ask about my protocol", text: `${greeting} I have a question about my protocol: ` },
    {
      label: "Question about my measurements",
      text: `${greeting} I have a question about my measurements: `,
    },
    { label: "Recommend a supplement", text: `${greeting} could you recommend a supplement for ` },
    { label: "Schedule an appointment", text: `${greeting} I'd like to schedule an appointment. ` },
    {
      label: "Send documents",
      text: `${greeting} attaching some documents for your review: `,
    },
  ];
}
