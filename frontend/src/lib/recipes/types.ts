/**
 * The recipe shape the UI renders, and the mapper from the API's.
 *
 * ## Two vocabularies, not one
 *
 * The endpoint returns `categories` and `types` as separate taxonomy fields, and
 * they are not synonyms:
 *
 * - `categories` (`field_recipe_category`) mixes program phase (`Losing Phase`,
 *   `Lifetime Phase`) with food group (`Poultry`, `Seafood`, `Soups`). 97 of the
 *   200 recipes sampled carry **two** terms, commonly one phase plus one group.
 * - `types` (`field_recipe_type`) is the narrower second taxonomy: `Free Item`,
 *   `Fruit`, `Vegetables`, `Beef`, `Poultry`, `Seafood`.
 *
 * The card design wants a single grouping heading and a single pill, so
 * {@link toRecipe} takes the first term of each. The full arrays are preserved on
 * the mapped object because dropping them loses information the payload already
 * paid for, and a category filter over the full set is a later change that should
 * not need a second fetch.
 *
 * ## The heading is not always present
 *
 * 9 of the 200 sampled recipes have no category term at all — only a type, e.g.
 * a fruit recipe. Those land in {@link UNCATEGORISED} rather than under an empty
 * `<h2>`, which is what the design would otherwise render.
 *
 * ## `steps` is the plain text, not the HTML
 *
 * The endpoint returns `body.text` and `body.html`. The card and the print modal
 * both render `steps` inside a `<p>`, and the modal adds `whitespace-pre-wrap` so
 * the line breaks survive. React escapes a string in a text node, so `body.html`
 * would print as literal `<br />`. `body.text` keeps the newlines and prints
 * correctly. If the body is ever rich text that matters, this needs a sanitizer and
 * a `dangerouslySetInnerHTML` with the same review the Drupal side gave it — not a
 * swap of one string for the other.
 */

export interface RecipeIngredient {
  name: string;
}

/** One `{id, label}` term as the endpoint emits it. */
export interface RecipeTerm {
  id: number;
  label: string;
}

/**
 * What the recipes page renders.
 *
 * `category` and `tag` are the flattened single values the card layout needs;
 * `categories`/`types` are the complete term lists behind them.
 */
export interface Recipe {
  id: number;
  title: string;
  /** Grouping heading: the first category term, or `Uncategorised`. */
  category: string;
  /** The pill on the card: the first type term, or `null` when there is none. */
  tag: string | null;
  fav: boolean;
  ingredients: RecipeIngredient[];
  /** Instructions as plain text, newline-separated. `""` when the body is empty. */
  steps: string;
  /** Every category term, not just the heading. */
  categories: RecipeTerm[];
  /** Every type term, not just the pill. */
  types: RecipeTerm[];
}

/** Group heading for a recipe that carries no category term. */
export const UNCATEGORISED = "Uncategorised";

export type RecipeFilter = "all" | "fav";

/**
 * What the "Add recipe" form may offer, per taxonomy field.
 *
 * Mirrors the payload key `categories`/`types` the create endpoint validates
 * against (ContentService::OPTION_VOCABULARIES), so the options the form renders
 * and the vocabulary a term id is checked against cannot differ by convention.
 */
export interface RecipeOptions {
  categories: RecipeTerm[];
  types: RecipeTerm[];
}

/**
 * The caller's capability flags, from the list payload's `capabilities` block.
 *
 * `canCreate` is what the "Add recipe" button is gated on. It is computed by the
 * backend against the actual create route (ContentCapabilities), so the button
 * never appears for a caller the endpoint would refuse.
 */
export interface RecipeCapabilities {
  canCreate: boolean;
  canDelete: boolean;
  canManageOwnContent: boolean;
  canFavourite: boolean;
  audience: string;
}

/** Narrows a `capabilities` block from the payload, or null when unusable. */
export function toCapabilities(value: unknown): RecipeCapabilities | null {
  if (typeof value !== "object" || value === null) return null;
  const raw = value as { canCreate?: unknown; canDelete?: unknown; canManageOwnContent?: unknown; canFavourite?: unknown; audience?: unknown };

  return {
    canCreate: raw.canCreate === true,
    canDelete: raw.canDelete === true,
    canManageOwnContent: raw.canManageOwnContent === true,
    canFavourite: raw.canFavourite === true,
    audience: typeof raw.audience === "string" ? raw.audience : "",
  };
}

/** Narrows the option payload, dropping unusable terms per field. */
export function toRecipeOptions(value: unknown): RecipeOptions | null {
  if (typeof value !== "object" || value === null) return null;
  const raw = value as { categories?: unknown; types?: unknown };

  return {
    categories: toTerms(raw.categories),
    types: toTerms(raw.types),
  };
}

/**
 * The raw list envelope from `GET /api/headless/content/recipe`.
 *
 * Every field is treated as untrusted: the mapper below narrows each one rather
 * than casting, because a 200 with an unexpected shape should render an empty
 * library rather than throw while the page is being rendered.
 */
export interface RecipeListResponse {
  bundle?: string;
  label?: string;
  visibility?: string;
  items?: unknown[];
  pagination?: {
    page?: number;
    pageSize?: number;
    pageCount?: number;
    totalItems?: number;
  };
  capabilities?: Record<string, unknown>;
}

/** The row shape the mapper reads. Declared loosely for the same reason. */
interface RawRecipe {
  id?: unknown;
  title?: unknown;
  favourite?: unknown;
  ingredients?: unknown;
  body?: { text?: unknown; html?: unknown } | null;
  categories?: unknown;
  types?: unknown;
}

/** Narrows one `{id, label}` term, or null when it is not usable as one. */
function toTerm(value: unknown): RecipeTerm | null {
  if (typeof value !== "object" || value === null) return null;
  const { id, label } = value as { id?: unknown; label?: unknown };

  // A term with no id cannot be keyed reliably, and one with no label renders as a
  // blank heading — which is the bug the UNCATEGORISED fallback exists to prevent.
  if (typeof id !== "number" || typeof label !== "string" || label === "") return null;

  return { id, label };
}

/** Narrows a list of terms, dropping unusable entries. */
function toTerms(value: unknown): RecipeTerm[] {
  if (!Array.isArray(value)) return [];
  return value.map(toTerm).filter((term): term is RecipeTerm => term !== null);
}

/**
 * One endpoint row to one `Recipe`.
 *
 * Returns null for a row with no usable id, which is the one field nothing can be
 * rendered without: the card is keyed by it and the favourite route needs it.
 */
export function toRecipe(raw: unknown): Recipe | null {
  if (typeof raw !== "object" || raw === null) return null;
  const row = raw as RawRecipe;

  if (typeof row.id !== "number") return null;

  const categories = toTerms(row.categories);
  const types = toTerms(row.types);

  return {
    id: row.id,
    title: typeof row.title === "string" ? row.title : "",
    category: categories[0]?.label ?? UNCATEGORISED,
    tag: types[0]?.label ?? null,
    // `=== true` rather than truthiness: the endpoint emits a real boolean, and a
    // truthy non-boolean here would mean a favourite the caller never set.
    fav: row.favourite === true,
    ingredients: Array.isArray(row.ingredients)
      ? row.ingredients
          .filter((item): item is string => typeof item === "string" && item !== "")
          .map((name) => ({ name }))
      : [],
    steps: typeof row.body?.text === "string" ? row.body.text : "",
    categories,
    types,
  };
}

/** The whole list, with unusable rows dropped. */
export function toRecipes(items: unknown): Recipe[] {
  if (!Array.isArray(items)) return [];
  return items.map(toRecipe).filter((recipe): recipe is Recipe => recipe !== null);
}

export function groupByCategory(recipes: Recipe[]): Record<string, Recipe[]> {
  const groups: Record<string, Recipe[]> = {};
  for (const r of recipes) {
    (groups[r.category] = groups[r.category] || []).push(r);
  }
  return groups;
}
