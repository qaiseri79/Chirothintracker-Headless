"use client";

import { useState, type FormEvent } from "react";
import { Users, X } from "lucide-react";
import { cn } from "cn";
import { Dialog, DialogClose, DialogContent, DialogDescription, DialogTitle } from "@/components/ui/dialog";
import { createRecipe, RecipeAuthError } from "@/lib/recipes/api";
import type { Recipe, RecipeOptions, RecipeTerm } from "@/lib/recipes/types";
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

interface AddRecipeDialogProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  /**
   * The categories and types the form offers. `null` when the server could not
   * load them — the dialog then explains and offers a retry (router.refresh()),
   * so a broken options endpoint leaves the page usable instead of frozen.
   */
  options: RecipeOptions | null;
  /** The created recipe, already mapped, to be listed without a reload. */
  onCreated: (recipe: Recipe) => void;
  /** A lost session changed nothing here: the page knows the login flow. */
  onAuthError: () => void;
  /** Re-run the server fetch for the options payload (router.refresh()). */
  onRetry: () => void;
}

type FieldName = "title" | "category" | "ingredients" | "instructions" | "types";

type Errors = Partial<Record<FieldName, string>>;

/** The chip rows have no focusable control of their own, so they are scrolled to instead of focused. */
const FOCUSABLE: FieldName[] = ["title", "ingredients", "instructions"];

const FIELD_IDS: Record<FieldName, string> = {
  title: "add-recipe-title",
  category: "add-recipe-category",
  ingredients: "add-recipe-ingredients",
  instructions: "add-recipe-instructions",
  types: "add-recipe-types",
};

const FIELD_ORDER: FieldName[] = ["title", "category", "ingredients", "instructions", "types"];

const COPY = {
  title: "Give your recipe a title.",
  category: "Pick at least one category.",
  ingredients: "Add at least one ingredient.",
  instructions: "Add the preparation steps.",
  types: "Choose at least one recipe type.",
} satisfies Record<FieldName, string>;

/**
 * The "Add recipe" form, from "New Design/ChiroThin — Add a recipe form.html".
 *
 * ## Built on the design's own markup
 *
 * The mock-up's geometry is kept as closely as React allows: a bottom sheet on
 * phones and a centred panel from `sm` up (`content-form-primitives.ts`), a
 * serif title with the shared-with-clinic description beside a custom close,
 * the `.field`/`.bad`/`.err` field language with its per-field messages, the
 * pill chips with the count badge for categories (2 max, mirroring the
 * backend's rule) and a live "n items" line for ingredients.
 *
 * ## Two deliberate deviations from the mock-up
 *
 * The mock-up marks `Recipe type` optional and never validates it, but the
 * backend requires 1+ terms from `recipe_types` and returns a 400 without one.
 * The form keeps that contract: the field carries the required marker and the
 * same `FIELD_ORDER` validation, so the endpoint never sees an empty list. It
 * also validates ingredients and instructions — fields the endpoint tolerates
 * as empty — because the design treats them as required product fields.
 *
 * Server-side fields only. The audience is the caller's own clinic and arrives
 * with the request on the server, so there is deliberately no clinic control
 * here and no field for it travels from this form.
 *
 * The page owns `open`. Submitting is a POST through `createRecipe`; a success
 * lists the row immediately (`onCreated`) and closes. The form keeps its values
 * until then so a failed submit (a 400 from the endpoint's own validation, or a
 * network miss) loses nothing.
 */
export function AddRecipeDialog({
  open,
  onOpenChange,
  options,
  onCreated,
  onAuthError,
  onRetry,
}: AddRecipeDialogProps) {
  const [title, setTitle] = useState("");
  const [categories, setCategories] = useState<number[]>([]);
  const [types, setTypes] = useState<number[]>([]);
  const [ingredients, setIngredients] = useState("");
  const [instructions, setInstructions] = useState("");
  const [errors, setErrors] = useState<Errors>({});
  const [formError, setFormError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);

  const blank = () => {
    setTitle("");
    setCategories([]);
    setTypes([]);
    setIngredients("");
    setInstructions("");
    setErrors({});
    setFormError(null);
  };

  const clearError = (field: FieldName) =>
    setErrors((prev) => (prev[field] ? { ...prev, [field]: undefined } : prev));

  const toggle = (list: number[], id: number, max: number | null): number[] =>
    list.includes(id)
      ? list.filter((x) => x !== id)
      : max !== null && list.length >= max
        ? list
        : [...list, id];

  const validate = (): Errors => ({
    title: title.trim() ? undefined : COPY.title,
    category: categories.length > 0 ? undefined : COPY.category,
    ingredients: ingredients.trim() ? undefined : COPY.ingredients,
    instructions: instructions.trim() ? undefined : COPY.instructions,
    types: types.length > 0 ? undefined : COPY.types,
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
        // The design scrolls the first bad field into view; chip rows cannot be
        // focused, so they scroll, the rest focus.
        if (FOCUSABLE.includes(firstInvalid)) el.focus();
        else el.scrollIntoView({ behavior: "smooth", block: "center" });
      }
      return;
    }

    setSubmitting(true);
    try {
      const created = await createRecipe({
        title: title.trim(),
        body: instructions,
        ingredients: ingredients
          .split("\n")
          .map((line) => line.trim())
          .filter((line) => line !== ""),
        categoryIds: categories,
        typeIds: types,
      });
      blank();
      onCreated(created);
      onOpenChange(false);
    } catch (err) {
      if (err instanceof RecipeAuthError) {
        onAuthError();
        return;
      }
      setFormError(
        err instanceof Error ? err.message : "Unable to add this recipe.",
      );
    } finally {
      setSubmitting(false);
    }
  };

  const ingredientCount = ingredients
    ? ingredients.split("\n").filter((line) => line.trim()).length
    : 0;

  const header = (
    <div className={HEADER}>
      <div>
        <DialogTitle className="font-serif text-2xl text-foreground">
          Add a recipe
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
  );

  return (
    <Dialog
      open={open}
      onOpenChange={(next) => {
        onOpenChange(next);
        if (!next) blank();
      }}
    >
      <DialogContent overlayClassName={OVERLAY} className={SHEET} showCloseButton={false}>
        {header}

        {options === null ? (
          <div className="px-6 py-10 text-center">
            <p className="text-sm text-foreground">
              The recipe categories and types could not be loaded.
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
                  maxLength={90}
                  placeholder="e.g. Herb-roasted chicken with greens"
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
                <div className="flex items-baseline justify-between">
                  <span className={LABEL}>Recipe category {REQUIRED}</span>
                  <span className="rounded-full bg-primary-soft px-2.5 py-0.5 text-[11px] font-semibold text-primary">
                    {categories.length} / 2
                  </span>
                </div>
                <p className={cn(HINT, "mb-3")}>
                  Choose up to 2. Each recipe sits under one of these headings.
                </p>
                {options.categories.length === 0 ? (
                  <p className="text-sm text-muted-foreground">None available.</p>
                ) : (
                  <div id={FIELD_IDS.category} className="flex flex-wrap gap-2">
                    {options.categories.map((term: RecipeTerm) => (
                      <FormChip
                        key={term.id}
                        checked={categories.includes(term.id)}
                        disabled={!categories.includes(term.id) && categories.length >= 2}
                        onClick={() => {
                          setCategories((prev) => toggle(prev, term.id, 2));
                          clearError("category");
                        }}
                      >
                        {term.label}
                      </FormChip>
                    ))}
                  </div>
                )}
                {errors.category && (
                  <p role="alert" className={FIELD_ERROR}>
                    {errors.category}
                  </p>
                )}
              </div>

              <div>
                <div className="flex items-baseline justify-between">
                  <label htmlFor={FIELD_IDS.ingredients} className={LABEL}>
                    Ingredients {REQUIRED}
                  </label>
                  <span className="text-[11px] font-medium text-muted-foreground">
                    {ingredientCount} {ingredientCount === 1 ? "item" : "items"}
                  </span>
                </div>
                <p className={cn(HINT, "mb-2")}>One ingredient per line.</p>
                <textarea
                  id={FIELD_IDS.ingredients}
                  rows={5}
                  className={cn(
                    FIELD,
                    "resize-y leading-6",
                    errors.ingredients && FIELD_BAD,
                  )}
                  placeholder={"Chicken breast\nOlive oil\nLemon, juiced"}
                  value={ingredients}
                  onChange={(e) => {
                    setIngredients(e.target.value);
                    clearError("ingredients");
                  }}
                  aria-invalid={Boolean(errors.ingredients)}
                />
                {errors.ingredients && (
                  <p role="alert" className={FIELD_ERROR}>
                    {errors.ingredients}
                  </p>
                )}
              </div>

              <div>
                <label htmlFor={FIELD_IDS.instructions} className={LABEL}>
                  Instructions {REQUIRED}
                </label>
                <textarea
                  id={FIELD_IDS.instructions}
                  rows={5}
                  className={cn(
                    FIELD,
                    "resize-y leading-6",
                    errors.instructions && FIELD_BAD,
                  )}
                  placeholder="How to prepare and serve it."
                  value={instructions}
                  onChange={(e) => {
                    setInstructions(e.target.value);
                    clearError("instructions");
                  }}
                  aria-invalid={Boolean(errors.instructions)}
                />
                {errors.instructions && (
                  <p role="alert" className={FIELD_ERROR}>
                    {errors.instructions}
                  </p>
                )}
              </div>

              <div>
                <span className={LABEL}>Recipe type {REQUIRED}</span>
                <p className={cn(HINT, "mb-3")}>Choose one or more types.</p>
                {options.types.length === 0 ? (
                  <p className="text-sm text-muted-foreground">None available.</p>
                ) : (
                  <div id={FIELD_IDS.types} className="flex flex-wrap gap-2">
                    {options.types.map((term: RecipeTerm) => (
                      <FormChip
                        key={term.id}
                        checked={types.includes(term.id)}
                        onClick={() => {
                          setTypes((prev) => toggle(prev, term.id, null));
                          clearError("types");
                        }}
                      >
                        {term.label}
                      </FormChip>
                    ))}
                  </div>
                )}
                {errors.types && (
                  <p role="alert" className={FIELD_ERROR}>
                    {errors.types}
                  </p>
                )}
              </div>
            </div>

            <div className={FOOTER}>
              <DialogClose asChild>
                <button type="button" className={BTN_CANCEL}>
                  Cancel
                </button>
              </DialogClose>
              <button type="submit" disabled={submitting} className={BTN_SUBMIT}>
                {submitting ? "Creating…" : "Create recipe"}
              </button>
            </div>
          </form>
        )}
      </DialogContent>
    </Dialog>
  );
}