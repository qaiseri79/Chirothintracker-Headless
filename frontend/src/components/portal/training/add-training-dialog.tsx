"use client";

import { useState, type FormEvent } from "react";
import { Users, X } from "lucide-react";
import { cn } from "cn";
import { Dialog, DialogClose, DialogContent, DialogDescription, DialogTitle } from "@/components/ui/dialog";
import { createTraining, TrainingAuthError } from "@/lib/training/api";
import type { TrainingNode } from "@/lib/training/types";
import {
  BODY,
  BTN_CANCEL,
  BTN_SUBMIT,
  FIELD,
  FIELD_BAD,
  FIELD_ERROR,
  FORM_ERROR,
  FOOTER,
  HINT,
  HEADER,
  LABEL,
  OVERLAY,
  SHEET,
} from "@/components/portal/content-forms/content-form-primitives";

interface AddTrainingDialogProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  /** The created training item, already mapped, to be listed without a reload. */
  onCreated: (node: TrainingNode) => void;
  /** A lost session changed nothing here: the page knows the login flow. */
  onAuthError: () => void;
}

type FieldName = "title";

type Errors = Partial<Record<FieldName, string>>;

const COPY = {
  title: "Give the training item a title.",
} satisfies Record<FieldName, string>;

/**
 * The "Add training" form, restyled on the same design language as
 * "New Design/ChiroThin — Add a recipe form.html" — the shared sheet, header
 * and `.field`/`.bad`/`.err` fields — so the three content forms read as one
 * family.
 *
 * Server-side fields only — title, an optional plain-text body, and an optional
 * video. The audience is the caller's own clinic and arrives with the request on
 * the server, so there is deliberately no clinic control here and no field for
 * it travels from this form.
 *
 * Training has no taxonomy (unlike recipes and resources), so the form offers no
 * category row and the dialog has no options payload to wait on.
 *
 * A video is the one part of the form that is not one request: it is uploaded
 * first (so Drupal can validate it and hand back a managed-file id) and the
 * create then names that id as `video`. The bundle's `field_video` accepts MP4
 * only, up to 100 MB, and a training item may be published without a video —
 * several of the published ones are body text and an embed — so the video
 * control is optional and the form submits without it.
 *
 * The page owns `open`. Submitting is a POST through `createTraining`; a success
 * lists the row immediately (`onCreated`) and closes. The form keeps its values
 * until then so a failed submit (a 400 from the endpoint's own validation, or a
 * network miss) loses nothing.
 */
export function AddTrainingDialog({
  open,
  onOpenChange,
  onCreated,
  onAuthError,
}: AddTrainingDialogProps) {
  const [title, setTitle] = useState("");
  const [body, setBody] = useState("");
  const [selectedVideo, setSelectedVideo] = useState<File | null>(null);
  const [errors, setErrors] = useState<Errors>({});
  const [formError, setFormError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);

  const blank = () => {
    setTitle("");
    setBody("");
    setSelectedVideo(null);
    setErrors({});
    setFormError(null);
  };

  const handleSubmit = async (event: FormEvent) => {
    event.preventDefault();
    setFormError(null);

    if (!title.trim()) {
      setErrors({ title: COPY.title });
      document.getElementById("add-training-title")?.focus();
      return;
    }

    setSubmitting(true);
    try {
      const created = await createTraining({
        title: title.trim(),
        body,
        video: selectedVideo,
      });
      blank();
      onCreated(created);
      onOpenChange(false);
    } catch (err) {
      if (err instanceof TrainingAuthError) {
        onAuthError();
        return;
      }
      setFormError(
        err instanceof Error ? err.message : "Unable to add this training item.",
      );
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <Dialog
      open={open}
      onOpenChange={(next) => {
        onOpenChange(next);
        if (!next) blank();
      }}
    >
      <DialogContent overlayClassName={OVERLAY} className={SHEET} showCloseButton={false}>
        <div className={HEADER}>
          <div>
            <DialogTitle className="font-serif text-2xl text-foreground">
              Add a training item
            </DialogTitle>
            <DialogDescription className="mt-1 flex items-start gap-1.5 text-sm text-muted-foreground">
              <Users className="mt-0.5 size-4 shrink-0 text-primary" aria-hidden="true" />
              Shared with everyone on your clinic&apos;s plan. Published immediately.
            </DialogDescription>
          </div>
          <DialogClose asChild>
            <button
              aria-label="Close"
              className="rounded-lg p-1.5 text-muted-foreground transition-colors hover:bg-canvas"
            >
              <X className="size-5" aria-hidden="true" />
            </button>
          </DialogClose>
        </div>

        <form onSubmit={handleSubmit} noValidate className="flex min-h-0 flex-1 flex-col">
          <div className={BODY}>
            {formError && (
              <p role="alert" className={FORM_ERROR}>
                {formError}
              </p>
            )}

            <div>
              <label htmlFor="add-training-title" className={LABEL}>
                Title <span className="text-flame">*</span>
              </label>
              <input
                id="add-training-title"
                className={cn(FIELD, errors.title && FIELD_BAD)}
                placeholder="e.g. Phase One introduction"
                value={title}
                onChange={(e) => {
                  setTitle(e.target.value);
                  setErrors((prev) =>
                    prev.title ? { ...prev, title: undefined } : prev,
                  );
                }}
                aria-invalid={Boolean(errors.title)}
              />
              {errors.title && (
                <p role="alert" className={FIELD_ERROR}>
                  {errors.title}
                </p>
              )}
            </div>

            <div>
              <label htmlFor="add-training-body" className={LABEL}>
                Notes{" "}
                <span className="font-semibold text-muted-foreground">(optional)</span>
              </label>
              <textarea
                id="add-training-body"
                rows={4}
                className={cn(FIELD, "resize-y")}
                placeholder="What should viewers know before or while they watch?"
                value={body}
                onChange={(e) => setBody(e.target.value)}
              />
            </div>

            <div>
              <span className={LABEL}>
                Video{" "}
                <span className="font-semibold text-muted-foreground">(optional)</span>
              </span>
              <p className={cn(HINT, "mb-3")}>
                An MP4 video up to 100&nbsp;MB. A training item can be published
                without a video.
              </p>
              {selectedVideo ? (
                <div className="flex items-center gap-3 rounded-xl border border-border bg-canvas px-3.5 py-2.5">
                  <span className="min-w-0 flex-1 truncate text-sm text-foreground">
                    {selectedVideo.name}
                  </span>
                  <span className="text-xs text-muted-foreground">
                    {Math.max(1, Math.round((selectedVideo.size / 1024) * 10) / 10)} KB
                  </span>
                  <button
                    type="button"
                    onClick={() => setSelectedVideo(null)}
                    className="rounded-md px-2 py-1 text-sm font-medium text-muted-foreground transition-colors hover:bg-surface hover:text-foreground"
                  >
                    Remove
                  </button>
                </div>
              ) : (
                <label
                  htmlFor="add-training-video"
                  className="inline-flex cursor-pointer items-center justify-center rounded-xl border border-line bg-surface px-4 py-2.5 text-sm font-medium text-foreground transition-colors hover:border-primary hover:text-primary"
                >
                  Choose a video…
                </label>
              )}
              <input
                id="add-training-video"
                type="file"
                accept="video/mp4,.mp4"
                className="hidden"
                onChange={(e) => {
                  const file = e.target.files?.[0] ?? null;
                  setSelectedVideo(file);
                  e.target.value = "";
                }}
              />
            </div>
          </div>

          <div className={FOOTER}>
            <DialogClose asChild>
              <button type="button" className={BTN_CANCEL}>
                Cancel
              </button>
            </DialogClose>
            <button type="submit" disabled={submitting} className={BTN_SUBMIT}>
              {submitting ? "Creating…" : "Create training"}
            </button>
          </div>
        </form>
      </DialogContent>
    </Dialog>
  );
}