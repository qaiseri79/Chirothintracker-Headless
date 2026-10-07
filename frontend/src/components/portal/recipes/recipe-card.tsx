"use client";

import { useCallback, useRef, useState } from "react";
import { Star, Printer, ChevronDown, X } from "lucide-react";
import type { Recipe } from "@/lib/recipes/types";
import { setFavourite } from "@/lib/recipes/api";
import type { PortalAudience } from "@/lib/portal";

interface RecipeCardProps {
  recipe: Recipe;
  audience: PortalAudience;
  /**
   * Applies a confirmed favourite state to the page, so the row the list holds
   * agrees with the star and survives the "Favorites only" filter.
   */
  onFavouriteChange: (id: number, favourite: boolean) => void;
  /**
   * Reports a failed toggle, so the page can show one message rather than 634.
   * The error is passed through rather than a pre-formatted string so the page can
   * recognise a lost session and send the user to log in.
   */
  onFavouriteError: (error: unknown) => void;
}

export function RecipeCard({
  recipe,
  audience,
  onFavouriteChange,
  onFavouriteError,
}: RecipeCardProps) {
  const [isOpen, setIsOpen] = useState(false);
  const [showPrintModal, setShowPrintModal] = useState(false);
  /**
   * Counts clicks, so a response that lands late cannot overwrite a newer intent.
   *
   * The star is optimistic now — it moves on click and the POST settles afterwards —
   * which means a second click can arrive while the first is still in flight. The old
   * `isSaving` flag handled that by ignoring the click, but blocking the control is
   * what made the star feel slow, so the sequencing moved here instead. A ref rather
   * than state: nothing renders from it, and a render on every click is the cost the
   * optimistic update exists to avoid.
   *
   * The star is still not mirrored locally. The page owns the rows and hands back the
   * state, so the card and the "Favorites only" filter can never disagree about a row.
   */
  const intentRef = useRef(0);

  const handleToggleOpen = useCallback(() => {
    setIsOpen((prev) => !prev);
  }, []);

  const handleToggleFav = useCallback(
    async (e: React.MouseEvent) => {
      e.stopPropagation();

      // The state being asked for, read before it changes. Explicit rather than a flip,
      // so a retry or an out-of-order arrival cannot invert the star.
      const wanted = !recipe.fav;

      // Optimistic: the star moves now, the round trip settles after. This is the same
      // POST the button always made, and the endpoint treats a repeated value as a
      // no-op, so there is nothing worth blocking the click on — the user asked for a
      // result the server has already been told about.
      onFavouriteChange(recipe.id, wanted);

      const intent = ++intentRef.current;

      try {
        // The server's answer, not the value we asked for: it is the only side that
        // read the flag, and the only one that knows whether this account may set it.
        const result = await setFavourite(recipe.id, wanted);
        // Only the newest click may write back. An older response arriving late is a
        // truth about the past, not about what the star should show now.
        if (intent === intentRef.current) {
          onFavouriteChange(result.id, result.favourite);
        }
      } catch (error) {
        // Put the star back — but only if the user has not clicked again since, because
        // that click carries its own rollback and undoing it here would land the star
        // somewhere neither click asked for. The error is reported either way: a 403 is
        // a property of the account, so it is true of every star on the page.
        if (intent === intentRef.current) {
          onFavouriteChange(recipe.id, recipe.fav);
        }
        onFavouriteError(error);
      }
    },
    [recipe.id, recipe.fav, onFavouriteChange, onFavouriteError],
  );

  const handlePrintClick = useCallback((e: React.MouseEvent) => {
    e.stopPropagation();
    setShowPrintModal(true);
  }, []);

  const handleCloseModal = useCallback(() => {
    setShowPrintModal(false);
  }, []);

  const handlePrint = useCallback(() => {
    window.print();
  }, []);

  return (
    <>
      <div className="recipe rounded-xl border border-border bg-surface shadow-panel h-fit">
        {/* The star is a sibling of the toggle, not a child.
            It was an <svg onClick> inside the toggle button, which cannot be focused
            or activated by keyboard and carries no accessible name — a favourite
            control reachable by mouse only. A <button> cannot be nested in one, so
            the header is a flex row with two controls in it. Same layout, same
            click targets. */}
        <div className="recipe-toggle flex w-full items-center justify-between gap-3 px-5 py-4">
          <div className="flex min-w-0 items-center gap-2">
            {audience === "patient" && (
              <button
                type="button"
                onClick={handleToggleFav}
                aria-pressed={recipe.fav}
                aria-label={
                  recipe.fav
                    ? `Remove ${recipe.title} from favorites`
                    : `Add ${recipe.title} to favorites`
                }
                className="shrink-0 rounded p-0.5"
              >
                <Star
                  className={`h-4 w-4 transition-colors ${
                    recipe.fav ? "fill-flame text-flame" : "text-muted-foreground"
                  }`}
                  aria-hidden="true"
                />
              </button>
            )}
            <button
              type="button"
              className="min-w-0 text-left"
              aria-expanded={isOpen}
              onClick={handleToggleOpen}
            >
              <span className="block truncate font-medium text-foreground">
                {recipe.title}
              </span>
            </button>
          </div>
          <div className="flex shrink-0 items-center gap-3">
            {recipe.tag && (
              <span className="rounded-full bg-flame-soft px-2.5 py-1 text-xs font-semibold text-flame">
                {recipe.tag}
              </span>
            )}
            <button
              type="button"
              onClick={handlePrintClick}
              aria-label={`Print ${recipe.title}`}
              className="rounded p-0.5"
            >
              <Printer
                className="h-4 w-4 text-muted-foreground hover:text-foreground"
                aria-hidden="true"
              />
            </button>
            <button
              type="button"
              onClick={handleToggleOpen}
              aria-expanded={isOpen}
              aria-label={isOpen ? "Hide instructions" : "Show instructions"}
              className="rounded p-0.5"
            >
              <ChevronDown
                className={`h-4 w-4 text-muted-foreground transition-transform duration-200 ${
                  isOpen ? "rotate-180" : ""
                }`}
                aria-hidden="true"
              />
            </button>
          </div>
        </div>

        {isOpen && (
          <div className="recipe-body border-t border-border px-5 py-4">
            {/* Both sections are conditional. Every sampled recipe has both, but the
                endpoint does not promise it — a recipe with an empty ingredient field
                would otherwise render a bare "Ingredients" heading over an empty list,
                which reads as a loading failure rather than as missing data. */}
            {recipe.ingredients.length > 0 && (
              <>
                <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                  Ingredients
                </p>
                <ul className="mb-4 list-disc space-y-1 pl-5 text-sm text-foreground/80">
                  {recipe.ingredients.map((ing, idx) => (
                    <li key={idx}>{ing.name}</li>
                  ))}
                </ul>
              </>
            )}
            {recipe.steps ? (
              <>
                <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                  Instructions
                </p>
                <p className="whitespace-pre-wrap text-sm leading-relaxed text-foreground/80">
                  {recipe.steps}
                </p>
              </>
            ) : (
              <p className="text-sm text-muted-foreground">
                No instructions have been added for this recipe yet.
              </p>
            )}
          </div>
        )}

      </div>

      {/* Print Modal */}
      {showPrintModal && (
        <div
          className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4"
          onClick={handleCloseModal}
          role="dialog"
          aria-modal="true"
          aria-labelledby="print-modal-title"
        >
          <div
            className="w-full max-w-2xl rounded-2xl bg-surface shadow-xl p-6"
            onClick={(e) => e.stopPropagation()}
          >
            <div className="flex items-center justify-between mb-4">
              <h2 id="print-modal-title" className="font-serif text-xl text-foreground">
                Print Recipe
              </h2>
              <button
                type="button"
                className="rounded-lg p-1.5 text-muted-foreground hover:bg-canvas"
                onClick={handleCloseModal}
                aria-label="Close print modal"
              >
                <X className="h-5 w-5" />
              </button>
            </div>

            <div className="mb-4">
              <h3 className="font-serif text-lg text-foreground">{recipe.title}</h3>
              <div className="mt-1 flex items-center gap-2 text-sm text-muted-foreground">
                {recipe.tag && (
                  <span className="rounded-full bg-flame-soft px-2 py-0.5 text-xs font-semibold text-flame">
                    {recipe.tag}
                  </span>
                )}
                <span>Category: {recipe.category}</span>
              </div>
            </div>

            {recipe.ingredients.length > 0 && (
              <div className="mb-4">
                <h4 className="mb-2 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                  Ingredients
                </h4>
                <ul className="list-disc space-y-1 pl-5 text-sm text-foreground/80">
                  {recipe.ingredients.map((ing, idx) => (
                    <li key={idx}>{ing.name}</li>
                  ))}
                </ul>
              </div>
            )}

            <div className="mb-6">
              <h4 className="mb-2 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                Instructions
              </h4>
              <p className="text-sm leading-relaxed text-foreground/80 whitespace-pre-wrap">
                {recipe.steps || "No instructions have been added for this recipe yet."}
              </p>
            </div>

            <div className="flex justify-end gap-3 border-t border-border pt-4">
              <button
                type="button"
                className="rounded-lg px-4 py-2 text-sm font-medium text-muted-foreground hover:bg-canvas"
                onClick={handleCloseModal}
              >
                Close
              </button>
              <button
                type="button"
                className="rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-primary-dark"
                onClick={handlePrint}
              >
                Print
              </button>
            </div>
          </div>
        </div>
      )}
    </>
  );
}