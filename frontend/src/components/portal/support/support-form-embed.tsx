"use client";

import { AlertCircle } from "lucide-react";
import { SUPPORT_FORM_URL } from "@/lib/support/config";

/**
 * The GoHighLevel support-ticket form, embedded.
 *
 * ## Why an iframe and not the form's own fields
 *
 * GoHighLevel owns the ticket form: validation, file attachments, the SMS consent
 * box, notifications and where the submission lands. Re-implementing any of that here
 * would mean a second copy of the schema to keep in step with theirs, and submissions
 * would go somewhere the GoHighLevel automations never see. So the design's ticket
 * form — first name, last name, phone, issue, screenshot dropzone — is deliberately
 * **not** built. What is kept from the design is the surrounding card, the heading
 * and the intro line, so the page still reads as the design.
 *
 * Their embed code is a single `https://` URL intended for an iframe, which is what
 * `NEXT_PUBLIC_SUPPORT_FORM_URL` carries.
 *
 * ## The unconfigured state
 *
 * `SUPPORT_FORM_URL` is empty until the form is provisioned, and an iframe pointed at
 * an empty string renders as a broken box. A chiropractor opening the Support Center
 * to report a problem and finding a broken form is the worst possible outcome for
 * this page, so the missing URL gets an explicit panel instead — naming the env var
 * to set — and the iframe is not rendered at all.
 */
export function SupportFormEmbed() {
  return (
    <section className="rounded-2xl border border-line bg-surface p-5 shadow-panel sm:p-7 lg:col-span-2">
      <h3 className="font-serif text-xl">Submit a support ticket</h3>
      <p className="mt-1 text-sm text-muted-foreground">
        Please let us know about the issue you&rsquo;re having.
      </p>

      {SUPPORT_FORM_URL ? (
        <iframe
          title="Submit a support ticket"
          src={SUPPORT_FORM_URL}
          className="mt-6 h-[640px] w-full rounded-xl border border-line"
          loading="lazy"
        />
      ) : (
        <div
          role="status"
          className="mt-6 rounded-xl border border-dashed border-line bg-canvas/60 p-5"
        >
          <div className="flex items-start gap-3">
            <AlertCircle
              className="mt-0.5 h-5 w-5 shrink-0 text-flame"
              aria-hidden="true"
            />
            <div className="min-w-0 text-sm">
              <p className="font-semibold text-foreground">
                The ticket form is not connected yet
              </p>
              <p className="mt-1 text-muted-foreground">
                Set{" "}
                <code className="rounded bg-surface px-1 py-0.5 text-xs">
                  NEXT_PUBLIC_SUPPORT_FORM_URL
                </code>{" "}
                to the embed URL from your GoHighLevel form, then restart the dev
                server.
              </p>
              <p className="mt-3 text-muted-foreground">
                In the meantime you can{" "}
                <a
                  href="mailto:support@chirothintracker.com"
                  className="font-semibold text-primary underline underline-offset-2"
                >
                  email support
                </a>{" "}
                or join the live session above.
              </p>
            </div>
          </div>
        </div>
      )}
    </section>
  );
}