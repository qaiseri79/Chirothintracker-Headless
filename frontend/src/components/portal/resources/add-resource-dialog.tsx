"use client";

import { useState, type FormEvent } from "react";
import { Users, X } from "lucide-react";
import { cn } from "cn";
import { Dialog, DialogClose, DialogContent, DialogDescription, DialogTitle } from "@/components/ui/dialog";
import { createResource, ResourceAuthError } from "@/lib/resources/api";
import type { ResourceNode, ResourceOptions, ResourceTerm } from "@/lib/resources/types";
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
  REQUIRED,
  SHEET,
  FormChip,
} from "@/components/portal/content-forms/content-form-primitives";

interface AddResourceDialogProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  /**
   * The resource categories the form offers. `null` when the server could not
   * load them — the dialog then explains and offers a retry (router.refresh()),
   * so a broken options endpoint leaves the page usable instead of frozen.
   */
  options: ResourceOptions | null;
  /** The created resource, already mapped, to be listed without a reload. */
  onCreated: (resource: ResourceNode) => void;
  /** A lost session changed nothing here: the page knows the login flow. */
  onAuthError: () => void;
  /** Re-run the server fetch for the options payload (router.refresh()). */
  onRetry: () => void;
}

type FieldName = "title" | "resourceType";

type Errors = Partial<Record<FieldName, string>>;

const FIELD_IDS: Record<FieldName, string> = {
  title: "add-resource-title",
  resourceType: "add-resource-type",
};

const FIELD_ORDER: FieldName[] = ["title", "resourceType"];

const COPY = {
  title: "Give the resource a title.",
  resourceType: "Choose a resource category.",
} satisfies Record<FieldName, string>;

/**
 * The "Add resource" form, restyled on the same design language as
 * "New Design/ChiroThin — Add a recipe form.html" — the shared sheet, header,
 * `.field`/`.bad`/`.err` fields and pill chips — so the three content forms
 * read as one family.
 *
 * Server-side fields only — title, a single resource category, an optional
 * description, and an optional file. The audience is the caller's own clinic and
 * arrives with the request on the server, so there is deliberately no clinic
 * control here and no field for it travels from this form.
 *
 * The category row is a single-select chip picker because the field is
 * cardinality 1 on the node type and the endpoint refuses a second term:
 * selecting one clears the last, and there is no multi-select state to reach
 * a 400.
 *
 * A file is the one part of the form that is not one request: it is uploaded
 * first (so Drupal can validate it and hand back a managed-file id) and the
 * create then names that id as `resource`. A resource with no file is a real
 * thing — 12 of the 138 published ones have none — so the file control is
 * optional and the form submits without it.
 *
 * The page owns `open`. Submitting is a POST through `createResource`; a success
 * lists the row immediately (`onCreated`) and closes. The form keeps its values
 * until then so a failed submit (a 400 from the endpoint's own validation, or a
 * network miss) loses nothing.
 */
export function AddResourceDialog({
  open,
  onOpenChange,
  options,
  onCreated,
  onAuthError,
  onRetry,
}: AddResourceDialogProps) {
  const [title, setTitle] = useState("");
  const [resourceType, setResourceType] = useState<number | null>(null);
  const [description, setDescription] = useState("");
  const [selectedFile, setSelectedFile] = useState<File | null>(null);
  const [errors, setErrors] = useState<Errors>({});
  const [formError, setFormError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);

  const blank = () => {
    setTitle("");
    setResourceType(null);
    setDescription("");
    setSelectedFile(null);
    setErrors({});
    setFormError(null);
  };

  const clearError = (field: FieldName) =>
    setErrors((prev) => (prev[field] ? { ...prev, [field]: undefined } : prev));

  const validate = (): Errors => ({
    title: title.trim() ? undefined : COPY.title,
    resourceType: resourceType === null ? COPY.resourceType : undefined,
  });

  const handleSubmit = async (event: FormEvent) => {
    event.preventDefault();
    setFormError(null);

    const found = validate();
    setErrors(found);
    const firstInvalid = FIELD_ORDER.find((field) => found[field]);
    if (firstInvalid) {
      const el = document.getElementById(FIELD_IDS[firstInvalid]);
      if (el instanceof HTMLElement) {
        if (firstInvalid === "title") el.focus();
        else el.scrollIntoView({ behavior: "smooth", block: "center" });
      }
      return;
    }

    setSubmitting(true);
    try {
      const created = await createResource({
        title: title.trim(),
        description,
        resourceTypeIds: [resourceType as number],
        file: selectedFile,
      });
      blank();
      onCreated(created);
      onOpenChange(false);
    } catch (err) {
      if (err instanceof ResourceAuthError) {
        onAuthError();
        return;
      }
      setFormError(
        err instanceof Error ? err.message : "Unable to add this resource.",
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
              Add a resource
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

        {options === null ? (
          <div className="px-6 py-10 text-center">
            <p className="text-sm text-foreground">
              The resource categories could not be loaded.
            </p>
            <p className="mt-1 text-sm text-muted-foreground">
              Try again, or cancel and reload the page.
            </p>
            <button type="button" className={cn(BTN_SUBMIT, "mt-4")} onClick={onRetry}>
              Try again
            </button>
          </div>
        ) : (
          <form onSubmit={handleSubmit} noValidate className="flex min-h-0 flex-1 flex-col">
            <div className={BODY}>
              {formError && (
                <p role="alert" className={FORM_ERROR}>
                  {formError}
                </p>
              )}

              <div>
                <label htmlFor={FIELD_IDS.title} className={LABEL}>
                  Title {REQUIRED}
                </label>
                <input
                  id={FIELD_IDS.title}
                  className={cn(FIELD, errors.title && FIELD_BAD)}
                  placeholder="e.g. Losing Phase quick reference guide"
                  value={title}
                  onChange={(e) => {
                    setTitle(e.target.value);
                    clearError("title");
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
                <span className={LABEL}>Resource category {REQUIRED}</span>
                <p className={cn(HINT, "mb-3")}>
                  Choose one. Each resource sits under this heading.
                </p>
                {options.resourceTypes.length === 0 ? (
                  <p className="text-sm text-muted-foreground">None available.</p>
                ) : (
                  <div id={FIELD_IDS.resourceType} className="flex flex-wrap gap-2">
                    {options.resourceTypes.map((term: ResourceTerm) => (
                      <FormChip
                        key={term.id}
                        checked={resourceType === term.id}
                        onClick={() => {
                          setResourceType(term.id);
                          clearError("resourceType");
                        }}
                      >
                        {term.label}
                      </FormChip>
                    ))}
                  </div>
                )}
                {errors.resourceType && (
                  <p role="alert" className={FIELD_ERROR}>
                    {errors.resourceType}
                  </p>
                )}
              </div>

              <div>
                <label htmlFor="add-resource-description" className={LABEL}>
                  Description <span className="font-semibold text-muted-foreground">(optional)</span>
                </label>
                <textarea
                  id="add-resource-description"
                  rows={4}
                  className={cn(FIELD, "resize-y")}
                  placeholder="What is this resource and when should it be used?"
                  value={description}
                  onChange={(e) => setDescription(e.target.value)}
                />
              </div>

              <div>
                <span className={LABEL}>
                  File <span className="font-semibold text-muted-foreground">(optional)</span>
                </span>
                <p className={cn(HINT, "mb-3")}>
                  A PDF, image, document or video up to 20&nbsp;MB. A resource can
                  be published without a file.
                </p>
                {selectedFile ? (
                  <div className="flex items-center gap-3 rounded-xl border border-border bg-canvas px-3.5 py-2.5">
                    <span className="min-w-0 flex-1 truncate text-sm text-foreground">
                      {selectedFile.name}
                    </span>
                    <span className="text-xs text-muted-foreground">
                      {Math.max(1, Math.round((selectedFile.size / 1024) * 10) / 10)} KB
                    </span>
                    <button
                      type="button"
                      onClick={() => setSelectedFile(null)}
                      className="rounded-md px-2 py-1 text-sm font-medium text-muted-foreground transition-colors hover:bg-surface hover:text-foreground"
                    >
                      Remove
                    </button>
                  </div>
                ) : (
                  <label
                    htmlFor="add-resource-file"
                    className="inline-flex cursor-pointer items-center justify-center rounded-xl border border-line bg-surface px-4 py-2.5 text-sm font-medium text-foreground transition-colors hover:border-primary hover:text-primary"
                  >
                    Choose a file…
                  </label>
                )}
                <input
                  id="add-resource-file"
                  type="file"
                  className="hidden"
                  onChange={(e) => {
                    const file = e.target.files?.[0] ?? null;
                    setSelectedFile(file);
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
                {submitting ? "Creating…" : "Create resource"}
              </button>
            </div>
          </form>
        )}
      </DialogContent>
    </Dialog>
  );
}