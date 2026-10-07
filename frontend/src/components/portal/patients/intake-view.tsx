"use client";

import * as React from "react";
import { ChevronDown, Loader2 } from "lucide-react";
import { Dialog, DialogContent, DialogDescription, DialogTitle } from "@/components/ui/dialog";
import {
  agreementText,
  answersByStep,
  consentGiven,
  flaggedAnswers,
  intakeAnswers,
  storedField,
} from "@/lib/patients/intake-fields";
import type { IntakeSubmission } from "@/lib/patients/types";
import { fetchIntakeSubmission } from "@/lib/patients/api";

/**
 * One intake submission, read end to end.
 *
 * Transcribed from the design's `intakeTab(p)` in
 * `New Design/chirothin-patient-summary-v6.html`, which is the intake view of the
 * patient workspace: a "Submitted <date>" line, a Health screening card of
 * yes/no rows where a yes is amber, a Profile card of definition lists, and an
 * Agreements card of expandable bodies under a signature line.
 *
 * ## Where the content comes from
 *
 * Not from this file. Every label, every option and every step heading is read
 * from the intake blueprint (`lib/intake/blueprint.ts`) — the same generated
 * contract the public form renders — and joined to Drupal's stored values by
 * `lib/patients/intake-fields.ts`. The design's `QS` and `AGR` arrays are demo
 * data for four invented patients, and hard-coding them here would produce a view
 * that agrees with the design and disagrees with the form.
 *
 * ## Why it is a Radix `Dialog` and not a hand-rolled `fixed` div
 *
 * The portal shell wraps its content in a scrolling `<main>` and puts a `sticky`
 * `z-30` header above it, so a modal rendered inline as a plain `position: fixed`
 * div lands inside that stacking and scrolling context — which is what made this
 * dialog read as merged into the site header rather than floating over the page.
 * Radix renders the panel through a portal onto `document.body`, outside all of
 * it, and centres it with `left-[50%] top-[50%] translate(-50%,-50%)`, which no
 * amount of content can push off-centre. It also brings the focus trap, the
 * scroll lock and the Escape handling that were otherwise hand-rolled here.
 *
 * ## Why it fetches on open instead of arriving as a prop
 *
 * The snapshot behind the intake table carries a projection — a row per submission
 * with name, phone and goal weight, and nothing about health. Adding the answers to
 * it would put every patient's screening history in the list payload for every tab
 * on the page, to serve a view most of the time nobody opens. So this fetches on
 * mount and shows a loading state, which is also the only honest handling of the
 * fact that the record may have been deleted since the table was drawn.
 */
export function IntakeView({
  submissionId,
  open,
  onClose,
}: {
  /** The submission to read, or `null` when the view is closed. */
  submissionId: number | null;
  open: boolean;
  onClose: () => void;
}) {
  const [submission, setSubmission] = React.useState<IntakeSubmission | null>(null);
  const [status, setStatus] = React.useState<"loading" | "ready" | "error">("loading");
  const [error, setError] = React.useState<string | null>(null);

  // Re-targeting clears the previous attempt, so reopening after a failure does
  // not show the old error or the old patient's answers.
  const [target, setTarget] = React.useState<number | null>(null);
  if (target !== submissionId) {
    setTarget(submissionId);
    setSubmission(null);
    setStatus("loading");
    setError(null);
  }

  React.useEffect(() => {
    if (!open || submissionId === null) return;

    // Guards the state write below: closing the view mid-fetch must not leave a
    // result behind, and opening a second submission while the first is in flight
    // must not let the first answer for the second.
    let current = true;

    void (async () => {
      try {
        const result = await fetchIntakeSubmission(submissionId);
        if (!current) return;
        setSubmission(result);
        setStatus("ready");
      } catch (cause) {
        if (!current) return;
        setStatus("error");
        setError(
          cause instanceof Error && cause.message
            ? cause.message
            : "This intake submission could not be read.",
        );
      }
    })();

    return () => {
      current = false;
    };
  }, [open, submissionId]);

  // Escape and the close button close. The backdrop does not: a chiropractor
  // reading a long screening form is more likely to click away from it by accident
  // than the delete prompt, and nothing is lost either way, but losing your place
  // in a health history is annoying. `onOpenChange` only fires for the two that do
  // close, so guarding it is enough to keep the backdrop inert.
  const handleOpenChange = React.useCallback(
    (next: boolean) => {
      if (!next) onClose();
    },
    [onClose],
  );


  return (
    <Dialog open={open} onOpenChange={handleOpenChange}>
      {/* `grid-rows-[auto_minmax(0,1fr)]` gives the header its natural height and
          hands the rest to the scrolling body. The `minmax(0,1fr)` is the part that
          matters: a plain `1fr` floor is the row's own content height, so a long
          form would push the dialog past `max-h` instead of scrolling inside it. */}
      <DialogContent className="grid max-h-[90vh] grid-rows-[auto_minmax(0,1fr)] gap-0 overflow-hidden border-line bg-surface p-0 sm:max-w-2xl sm:rounded-2xl">
        {/* `pr-12` keeps the heading clear of the primitive's own close button. */}
        <div className="flex shrink-0 items-start justify-between gap-4 border-line border-b px-5 py-4 pr-12 sm:px-6">
          <div className="min-w-0">
            <DialogTitle className="font-serif text-lg text-foreground">
              Intake submission
            </DialogTitle>
            <DialogDescription className="mt-1 text-muted-foreground text-sm">
              {submission ? (
                <>
                  Submitted{" "}
                  <b className="font-medium text-foreground">
                    {new Date(submission.submitted * 1000).toLocaleDateString("en-US", {
                      month: "short",
                      day: "numeric",
                      year: "numeric",
                    })}
                  </b>
                </>
              ) : (
                "Loading this submission"
              )}
            </DialogDescription>
          </div>
        </div>

        {/* The scroll container. `min-h-0` lets it shrink below its content height
            so the dialog's own `max-h` is what bounds the dialog. */}
        <div className="min-h-0 overflow-x-hidden overflow-y-auto px-5 pb-5 sm:px-6 sm:pb-6">
          {status === "loading" ? (
            <p className="flex items-center gap-2 py-10 text-muted-foreground text-sm">
              <Loader2 className="size-4 animate-spin" aria-hidden="true" />
              Loading this submission&hellip;
            </p>
          ) : null}

          {status === "error" ? (
            <p role="alert" className="py-10 text-destructive text-sm">
              {error}
            </p>
          ) : null}

          {status === "ready" && submission ? <IntakeSubmissionContent submission={submission} /> : null}
        </div>
      </DialogContent>
    </Dialog>
  );
}

/**
 * Renders a field's values as a list, or inline when there is only one.
 *
 * A checkbox grid answers with up to seventeen ticks and reads as one run-on
 * sentence set inline; a name or a date answers with one thing and reads as a
 * second heading set as a list.
 */
function MultiValue({ values }: { values: string[] }) {
  if (values.length === 1) return <>{values[0]}</>;
  return (
    // Wrapping flex rather than a stacked list, so a seventeen-item checkbox grid
    // stays inside its column instead of forcing the whole grid wider.
    <ul className="flex flex-wrap gap-x-2 gap-y-0.5">
      {values.map((value) => (
        <li key={value} className="min-w-0 break-words">
          {value}
        </li>
      ))}
    </ul>
  );
}

/**
 * Fields that need the full row width.
 *
 * A textarea and a repeatable are the two widgets whose answers run longer than a
 * column, and they are named by widget rather than by field so a field added to the
 * form later is classified without this list being updated.
 */
function wide(name: string): boolean {
  return /_motivation$|_programs$|_medical_symptoms$|_other_symptoms$|_medications$|_cravings$|_health_challenge$|_stressors/.test(
    name,
  );
}
/** Shared blueprint-based intake reader for the roster dialog and summary tab. */
export function IntakeSubmissionContent({ submission }: { submission: IntakeSubmission }) {
  const answers = submission ? intakeAnswers(submission.fields) : [];
  const flagged = flaggedAnswers(answers);
  const groups = answersByStep(answers);
  const agreements = submission ? agreementText(submission.fields) : [];
  const signature = submission ? storedField(submission.fields, "field_consent") : "";
  const consented = submission ? consentGiven(submission.fields) : false;

  return (
            <div className="space-y-4">
              {/* Health screening first, and flagged above the rest.
                  The design does this deliberately: the questions that change whether
                  a patient is safe to start are the ones a chiropractor should not
                  have to go looking for. */}
              <section className="rounded-xl border border-line bg-surface">
                <div className="flex flex-wrap items-center justify-between gap-2 px-4 py-4 sm:px-5 sm:py-5">
                  <h3 className="font-serif text-lg text-foreground">Health screening</h3>
                  <span
                    className={`rounded-full px-3 py-1 font-semibold text-xs ${
                      flagged.length > 0
                        ? "bg-amber-100 text-amber-900"
                        : "bg-success-soft text-success"
                    }`}
                  >
                    {flagged.length > 0
                      ? `${flagged.length} ${flagged.length === 1 ? "answer needs" : "answers need"} your attention`
                      : "No health flags"}
                  </span>
                </div>

                {/* Rows are a single-column grid, so the chips always sit on their own line below
                    the question.

                    Two earlier attempts both broke, for the same underlying reason. A
                    `flex` row with a `shrink-0` value cell could not shrink, so a long
                    option like "Gastric Bypass (Roux-en-Y-Gastric Bypass)" pushed the
                    chips out past the panel edge and over the question text. Replacing
                    that with `grid-cols-[minmax(0,1fr)_auto]` was worse in a quieter
                    way: an `auto` track is sized from its content's *max-content*
                    width, so a long chip claimed that width first and left the `1fr`
                    question track with almost nothing — which is what stacked
                    "History of Bariatric Surgery" down the left edge one letter per
                    line. An `auto` track cannot be capped by a sibling, so there is no
                    side-by-side variant here that is safe at every width. Stacking is
                    the only arrangement that cannot overlap or collapse. */}
                <ul className="divide-y divide-line px-4 pb-4 sm:px-5 sm:pb-5">
                  {answers
                    .filter((answer) => answer.affirmative)
                    .map((answer) => {
                      const isFlagged = flagged.includes(answer);
                      return (
                        <li
                          key={answer.name}
                          className={`grid gap-1.5 rounded-lg px-2 py-2.5 ${
                            isFlagged ? "bg-amber-50" : ""
                          }`}
                        >
                          <span className="min-w-0 break-words text-foreground text-sm">
                            {answer.label}
                          </span>
                          <span className="flex min-w-0 flex-wrap gap-1.5">
                            {answer.values.map((value) => (
                              <span
                                key={value}
                                className={`max-w-full rounded-full px-2.5 py-0.5 font-bold text-xs break-words ${
                                  isFlagged ? "bg-amber-200 text-amber-900" : "bg-canvas text-muted-foreground"
                                }`}
                              >
                                {value}
                              </span>
                            ))}
                          </span>
                        </li>
                      );
                    })}
                </ul>
              </section>

              {/* Every answered field, in the order the form asked it. `wide()` puts the long
                  ones on their own row.

                  Four fields legitimately share the label "Year Diagnosed" (diabetes,
                  blood pressure, thyroid, cholesterol): the blueprint labels each of
                  them that way and the form disambiguates them by asking under a
                  condition heading. Flattened into a grid they repeat, and that is left
                  alone on purpose — the fixes would be a hard-coded condition→field map
                  or a naming convention the blueprint does not carry, and either would
                  drift from the form, which is the thing this view is built not to do.
                  They are keyed by field name, so the repeats are distinct rows rather
                  than a rendering clash. */}
                {groups
                  .filter((group) => !group.answers.every((answer) => answer.affirmative))
                  .map((group) => (
                    <section key={group.title} className="rounded-xl border border-line bg-surface p-4 sm:p-5">
                      <h3 className="font-serif text-lg text-foreground">{group.title}</h3>
                      <dl className="mt-4 grid gap-4 sm:grid-cols-2">
                        {group.answers
                          .filter((answer) => !answer.affirmative)
                          .map((answer) => (
                            // `min-w-0` so a long unbroken answer cannot widen its grid
                            // column and squeeze the neighbouring one.
                            <div
                              key={answer.name}
                              className={`min-w-0 ${wide(answer.name) ? "sm:col-span-2" : ""}`}
                            >
                              <dt className="font-semibold text-muted-foreground text-xs uppercase tracking-[0.05em]">
                                {answer.label}
                              </dt>
                              <dd className="mt-0.5 font-medium break-words text-foreground text-sm">
                                <MultiValue values={answer.values} />
                              </dd>
                            </div>
                          ))}
                      </dl>
                    </section>
                  ))}

                <section className="rounded-xl border border-line bg-surface p-4 sm:p-5">
                  <h3 className="font-serif text-lg text-foreground">Agreements</h3>
                  <p className="text-muted-foreground text-xs">Signed by typing full legal name</p>

                  <div className="mt-4 space-y-2">
                    {agreements.map((agreement) => (
                      <details key={agreement.name} className="group rounded-xl border border-line">
                        <summary className="flex cursor-pointer list-none flex-wrap items-center gap-x-3 gap-y-1 px-4 py-3 font-semibold text-sm">
                          <span className="min-w-0 flex-1 break-words">{agreement.label}</span>
                          <span className="shrink-0 rounded-full bg-success-soft px-2.5 py-0.5 font-semibold text-success text-xs">
                            Agreed
                          </span>
                          <ChevronDown
                            className="shrink-0 text-muted-foreground transition group-open:rotate-180"
                            aria-hidden="true"
                          />
                        </summary>
                        <p className="border-line border-t px-4 py-3 leading-relaxed text-foreground/80 text-sm whitespace-pre-line">
                          {agreement.text}
                        </p>
                      </details>
                    ))}
                  </div>

                  {/* Only claimed when the record can support it. A submission that did not
                      tick the consent box has no signature to show, and saying so is
                      the difference between this screen being evidence and being
                      decoration. */}
                  {consented && signature ? (
                    <p className="mt-4 rounded-lg bg-canvas px-4 py-3 text-sm">
                      Signed electronically by{" "}
                      <b className="font-serif text-base">{signature}</b>
                      {submission.submitted
                        ? ` on ${new Date(submission.submitted * 1000).toLocaleDateString("en-US", {
                            month: "short",
                            day: "numeric",
                            year: "numeric",
                          })}`
                        : ""}
                    </p>
                  ) : consented ? (
                    <p className="mt-4 rounded-lg bg-canvas px-4 py-3 text-muted-foreground text-sm">
                      Consent was given, but no legal name was typed against it.
                    </p>
                  ) : (
                    <p className="mt-4 rounded-lg bg-canvas px-4 py-3 text-muted-foreground text-sm">
                      No consent signature recorded for this submission.
                    </p>
                  )}
                </section>
              </div>

  );
}
