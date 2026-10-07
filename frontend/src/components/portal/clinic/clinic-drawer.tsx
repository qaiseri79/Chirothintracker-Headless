"use client";

import * as React from "react";
import { cn } from "cn";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogTitle,
} from "@/components/ui/dialog";
import type { ClinicLocation, Chiropractor } from "@/lib/clinic/types";
import { ClinicRequestError } from "@/lib/clinic/client";
import {
  BTN,
  BTN_OUTLINE,
  BTN_PRIMARY,
  FIELD,
  FIELD_BAD,
  FIELD_ERROR,
  FIELD_HINT,
  LABEL,
} from "./clinic-primitives";

/**
 * The design's `#dr` panel, for all three of its forms.
 *
 * The mock-up builds one drawer and swaps its body by kind — add chiropractor,
 * add location, edit location — which is why the title, the submit label and the
 * fields are all derived from `mode` here too rather than from three near-identical
 * components.
 *
 * ## Built on the shared Dialog rather than the mock-up's raw markup
 *
 * The mock-up hand-rolls a fixed panel with a click-to-close backdrop and an Escape
 * listener, and traps nothing: Tab walks straight out of the panel into the page
 * behind it, and the content underneath stays reachable. Radix's `Dialog` already
 * provides the focus trap, the `aria-modal` role, the labelled-by wiring and the
 * scroll lock, so the panel is styled with `DialogContent`'s own classes overridden
 * into a right-hand drawer. The overlay is deliberately dismissible, as the design
 * allows — nothing here is destructive, and Cancel sits in the same place the
 * backdrop click lands.
 */

export type DrawerMode = "add-chiropractor" | "edit-chiropractor" | "add-location" | "edit-location";

/** A new chiropractor, as the drawer collects them before the page adds the record. */
export interface ChiropractorDraft {
  /** `null` when no location was chosen; the form requires one, so normally an id. */
  locationId: number | null;
  name: string;
  email: string;
}

/** A location's title and parent clinic. */
export interface LocationDraft {
  title: string;
  clinic: string;
}

/** Every field either form can show. Errors are keyed by these. */
type FieldName = "locationId" | "name" | "email" | "title" | "clinic";

type Errors = Partial<Record<FieldName, string>>;

interface FieldCopy {
  label: string;
  error: string;
  hint?: string;
}

/**
 * The mock-up's `fld(id, label, inner, err, hint)` table, verbatim.
 *
 * Kept as data rather than markup so the label/error/hint for each field is stated
 * once: the design's `.err` is revealed by a sibling selector keyed on `.bad`, which
 * means the message must sit after the control, and that ordering is easy to lose
 * when the copy is inlined at each call site.
 */
const COPY = {
  locationId: { label: "Clinic location", error: "Choose a clinic location." },
  name: { label: "Chiropractor full name", error: "Enter their full name." },
  email: {
    label: "Chiropractor email",
    error: "Enter a valid email address.",
    hint: "Used for login and account setup.",
  },
  title: {
    label: "Title",
    error: "Enter a title.",
    hint: "The name of this location, e.g. “Downtown Clinic”.",
  },
  clinic: { label: "Clinic", error: "Choose a clinic." },
} satisfies Record<FieldName, FieldCopy>;

/** `/^\S+@\S+\.\S+$/` — the mock-up's `fe` rule, unchanged. */
const EMAIL_PATTERN = /^\S+@\S+\.\S+$/;

/**
 * `.field` with `.bad` applied.
 *
 * The design toggles `.bad` on the control itself and lets the sibling selector
 * reveal the error. React keeps the invalid state in a keyed object instead, which
 * is what lets the message be rendered — and announced — rather than only styled.
 */
function fieldClass(invalid: boolean) {
  return cn(FIELD, invalid && FIELD_BAD);
}

/** One labelled control plus its optional hint and error, in the design's order. */
function Field({
  name,
  copy,
  invalid,
  message,
  children,
}: {
  name: FieldName;
  copy: FieldCopy;
  invalid: boolean;
  message?: string;
  children: React.ReactNode;
}) {
  return (
    <div>
      <label className={LABEL} htmlFor={name}>
        {copy.label} <span className="text-flame">*</span>
      </label>
      {children}
      {copy.hint ? <p className={FIELD_HINT}>{copy.hint}</p> : null}
      {invalid ? (
        <p role="alert" className={FIELD_ERROR}>
          {message ?? copy.error}
        </p>
      ) : null}
    </div>
  );
}

export function ClinicDrawer({
  mode,
  clinics,
  locations,
  /** The location being edited, or `null` when adding. */
  location,
  chiropractor,
  busy,
  onClose,
  onAddChiropractor,
  onSaveLocation,
}: {
  mode: DrawerMode;
  clinics: string[];
  locations: ClinicLocation[];
  location: ClinicLocation | null;
  chiropractor: Chiropractor | null;
  busy: boolean;
  onClose: () => void;
  onAddChiropractor: (draft: ChiropractorDraft) => Promise<void>;
  /** Called for both add and edit; the page knows which it is from `mode`. */
  onSaveLocation: (draft: LocationDraft) => Promise<void>;
}) {
  const formRef = React.useRef<HTMLFormElement>(null);

  // One flat value bag for both forms. Which fields are read is decided by `mode`,
  // so a chiropractor draft never has to satisfy the location rules.
  const [values, setValues] = React.useState({
    locationId: chiropractor?.locationId ? String(chiropractor.locationId) : "",
    name: chiropractor?.name ?? "",
    email: chiropractor?.email ?? "",
    title: location?.title ?? "",
    clinic: location?.clinic ?? clinics[0] ?? "",
  });
  const [errors, setErrors] = React.useState<Errors>({});
  const [formError, setFormError] = React.useState<string | null>(null);

  // Re-seeding on open rather than on save means a cancelled drawer's typing is gone
  // next time, which is what the mock-up gets from re-rendering `#drB` on every
  // `openDrawer`. Keying on the record as well means an edit form shows the record it
  // was opened for, not the one that happened to be last.
  const seedKey = `${mode}:${chiropractor?.id ?? location?.id ?? "new"}`;
  const [seeded, setSeeded] = React.useState(seedKey);
  if (seeded !== seedKey) {
    setSeeded(seedKey);
    setErrors({});
    setValues({
      locationId: chiropractor?.locationId ? String(chiropractor.locationId) : "",
      name: chiropractor?.name ?? "",
      email: chiropractor?.email ?? "",
      title: location?.title ?? "",
      clinic: location?.clinic ?? clinics[0] ?? "",
    });
  }

  const addingChiropractor = mode === "add-chiropractor" || mode === "edit-chiropractor";
  const title = addingChiropractor
    ? mode === "edit-chiropractor" ? "Edit chiropractor / health coach" : "Add chiropractor / health coach"
    : mode === "edit-location"
      ? "Edit clinic location"
      : "Add clinic location";

  const set = (field: FieldName) => (value: string) => {
    setValues((previous) => ({ ...previous, [field]: value }));
    // The mock-up clears `.bad` on any input, so a corrected field stops shouting
    // before the next submit rather than after it.
    setErrors((previous) =>
      previous[field] ? { ...previous, [field]: undefined } : previous,
    );
  };

  function validate(): Errors {
    if (addingChiropractor) {
      return {
        locationId: values.locationId ? undefined : COPY.locationId.error,
        // `trim().length > 1` in the design: a single character is not a name.
        name: values.name.trim().length > 1 ? undefined : COPY.name.error,
        email: EMAIL_PATTERN.test(values.email) ? undefined : COPY.email.error,
      };
    }
    return {
      title: values.title.trim() ? undefined : COPY.title.error,
      clinic: values.clinic ? undefined : COPY.clinic.error,
    };
  }

  async function handleSubmit(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (busy) return;
    setFormError(null);

    const found = validate();
    const firstInvalid = (Object.keys(found) as FieldName[]).find(
      (field) => found[field],
    );
    setErrors(found);
    if (firstInvalid) {
      // The design focuses the first bad control on submit; querying by name keeps
      // that ordering in one place rather than at each field.
      formRef.current
        ?.querySelector<HTMLElement>(`[name="${firstInvalid}"]`)
        ?.focus();
      return;
    }

    try {
    if (addingChiropractor) {
      await onAddChiropractor({
        locationId: values.locationId === "" ? null : Number(values.locationId),
        name: values.name.trim(),
        email: values.email.trim(),
      });
    } else {
      await onSaveLocation({ title: values.title.trim(), clinic: values.clinic });
    }
    onClose();
    } catch (failure) {
      setFormError(failure instanceof Error ? failure.message : "The change could not be completed.");
      if (failure instanceof ClinicRequestError) setErrors(failure.fields as Errors);
    }
  }

  return (
    <Dialog
      open
      onOpenChange={(next) => {
        if (!next) onClose();
      }}
    >
      <DialogContent
        // `bg-ink/40 backdrop-blur-[2px]`, the mock-up's `#ov`.
        overlayClassName="bg-foreground/40 backdrop-blur-[2px]"
        // Overrides `DialogContent`'s centred-modal geometry with the design's
        // right-hand panel, and swaps its left-to-right slide for a slide in from the
        // right edge.
        className="inset-y-0 top-0 right-0 left-auto flex h-full max-w-md translate-x-0 translate-y-0 flex-col gap-0 rounded-none border-line bg-surface p-0 shadow-2xl data-[state=open]:slide-in-from-right-full data-[state=open]:slide-in-from-top-0 data-[state=open]:zoom-in-100 data-[state=closed]:slide-out-to-right-full data-[state=closed]:slide-out-to-top-0 data-[state=closed]:zoom-out-100"
        showCloseButton={false}
      >
        <div className="flex items-center justify-between border-b border-line px-6 py-4">
          <DialogTitle className="font-serif text-xl font-semibold text-foreground">
            {title}
          </DialogTitle>
          <button
            type="button"
            onClick={onClose}
            aria-label="Close"
            className="rounded-lg p-1.5 text-muted-foreground transition-colors hover:bg-canvas focus-visible:ring-3 focus-visible:ring-ring/50 focus-visible:outline-none"
          >
            <svg
              className="size-4"
              viewBox="0 0 24 24"
              fill="none"
              stroke="currentColor"
              strokeWidth={2}
              strokeLinecap="round"
              aria-hidden="true"
            >
              <path d="M18 6 6 18M6 6l12 12" />
            </svg>
          </button>
        </div>

        <form
          ref={formRef}
          onSubmit={(event) => void handleSubmit(event)}
          noValidate
          className="flex min-h-0 flex-1 flex-col"
        >
          <fieldset disabled={busy} className="contents">
          {formError ? <p role="alert" className="mx-6 mt-4 rounded-lg bg-flame-soft p-3 text-sm text-flame">{formError}</p> : null}
          {/* Radix wants a description, and the design has one for the chiropractor
              form: the invitation hint. For the location forms it is the hint under
              Title, which the visible hint already carries, so it is described here
              instead of being repeated. */}
          <DialogDescription className="sr-only">
            {addingChiropractor
              ? COPY.email.hint
              : "Locations group the chiropractors on your team."}
          </DialogDescription>

          <div className="min-h-0 flex-1 space-y-5 overflow-y-auto px-6 py-6">
            {addingChiropractor ? (
              <>
                <Field
                  name="locationId"
                  copy={COPY.locationId}
                  invalid={Boolean(errors.locationId)}
                  message={errors.locationId}
                >
                  <select
                    id="locationId"
                    name="locationId"
                    value={values.locationId}
                    onChange={(event) => set("locationId")(event.target.value)}
                    aria-invalid={Boolean(errors.locationId)}
                    className={fieldClass(Boolean(errors.locationId))}
                  >
                    <option value="">- Select -</option>
                    {locations.map((option) => (
                      <option key={option.id} value={option.id}>
                        {option.title}
                      </option>
                    ))}
                  </select>
                </Field>

                <Field
                  name="name"
                  copy={COPY.name}
                  invalid={Boolean(errors.name)}
                  message={errors.name}
                >
                  <input
                    id="name"
                    name="name"
                    type="text"
                    autoComplete="off"
                    value={values.name}
                    onChange={(event) => set("name")(event.target.value)}
                    aria-invalid={Boolean(errors.name)}
                    className={fieldClass(Boolean(errors.name))}
                  />
                </Field>

                <Field
                  name="email"
                  copy={COPY.email}
                  invalid={Boolean(errors.email)}
                  message={errors.email}
                >
                  <input
                    id="email"
                    name="email"
                    type="email"
                    autoComplete="off"
                    value={values.email}
                    onChange={(event) => set("email")(event.target.value)}
                    aria-invalid={Boolean(errors.email)}
                    className={fieldClass(Boolean(errors.email))}
                  />
                </Field>
              </>
            ) : (
              <>
                <Field
                  name="title"
                  copy={COPY.title}
                  invalid={Boolean(errors.title)}
                  message={errors.title}
                >
                  <input
                    id="title"
                    name="title"
                    type="text"
                    autoComplete="off"
                    value={values.title}
                    onChange={(event) => set("title")(event.target.value)}
                    aria-invalid={Boolean(errors.title)}
                    className={fieldClass(Boolean(errors.title))}
                  />
                </Field>

                <Field
                  name="clinic"
                  copy={COPY.clinic}
                  invalid={Boolean(errors.clinic)}
                  message={errors.clinic}
                >
                  <select
                    id="clinic"
                    name="clinic"
                    value={values.clinic}
                    onChange={(event) => set("clinic")(event.target.value)}
                    aria-invalid={Boolean(errors.clinic)}
                    className={fieldClass(Boolean(errors.clinic))}
                  >
                    {clinics.map((option) => (
                      <option key={option} value={option}>
                        {option}
                      </option>
                    ))}
                  </select>
                </Field>
              </>
            )}
          </div>

          <div className="flex gap-3 border-t border-line px-6 py-4">
            <button
              type="button"
              onClick={onClose}
              className={cn(BTN, BTN_OUTLINE, "flex-1")}
            >
              Cancel
            </button>
            <button type="submit" className={cn(BTN, BTN_PRIMARY, "flex-1")}>
              {busy ? "Saving…" : mode === "edit-chiropractor" ? "Save changes" : addingChiropractor ? "Send invitation" : "Save"}
            </button>
          </div>
          </fieldset>
        </form>
      </DialogContent>
    </Dialog>
  );
}