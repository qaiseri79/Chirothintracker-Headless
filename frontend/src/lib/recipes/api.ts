/**
 * The browser-side calls the recipes page makes.
 *
 * Two of them. Favouriting goes to `/api/content/recipe/{id}/favourite`; adding a
 * recipe goes to `/api/content/recipe`. Both are Next.js route handlers that
 * forward the visitor's cookie and check the session's role — never direct calls
 * to Drupal, because the browser holds no Drupal session cookie. The list itself
 * is fetched on the server and passed in as a prop
 * (`app/(portal)/recipes/page.tsx`), the same way the training and patients pages
 * do it, so the initial render needs no client-side round trip and the whole
 * library arrives with the HTML.
 *
 * ## The 401
 *
 * The handlers translate Drupal's 307-to-login into a 401, so the branch here is
 * on a status that means "not signed in" rather than on a redirect the browser
 * would follow. `fetch` follows redirects by default and would have turned the
 * login page into a 200 with an HTML body.
 */

import { toRecipes, type Recipe, type RecipeFilter } from "@/lib/recipes/types";

/** Thrown for a transport or server failure, carrying a message fit to show. */
export class RecipeRequestError extends Error {}

/** Raised when the session is gone, so the caller can send the user to log in. */
export class RecipeAuthError extends RecipeRequestError {}

/**
 * Flag or unflag one recipe. Returns the state the server settled on.
 *
 * Explicit `wanted` rather than a flip: a flip is not idempotent, so a retry after a
 * dropped response would invert the star — the exact bug the endpoint's
 * `testRepeatedExplicitToggleDoesNotInvert` exists to prevent. The endpoint accepts
 * this same `{"favourite": true|false}` body and treats repeating it as a no-op.
 *
 * A rejection is the caller's to handle. The star is optimistic, so a 403 here (no
 * `flag favorites`) or a 404 (not this account's row) has to put it back.
 */
export async function setFavourite(
  id: number,
  wanted: boolean,
): Promise<{ id: number; favourite: boolean }> {
  const response = await fetch(`/api/content/recipe/${id}/favourite`, {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ favourite: wanted }),
  });

  const body = (await response.json().catch(() => ({}))) as {
    favourite?: unknown;
    message?: unknown;
  };

  if (response.status === 401) {
    throw new RecipeAuthError("Your session has expired. Please sign in again.");
  }
  if (!response.ok) {
    throw new RecipeRequestError(
      typeof body.message === "string" && body.message
        ? body.message
        : "Unable to update this recipe.",
    );
  }

  // Trust the server's answer over the value we asked for: it is the only side that
  // read the flag, and it is the only one that knows whether the account may set it.
  return { id, favourite: body.favourite === true };
}

/**
 * The fields a doctor sends to add a recipe.
 *
 * `ingredients` travels as a list because the field is a list; the form that
 * collects them one per line does the splitting. `categoryIds`/`typeIds` are the
 * exact ids the options payload offered, so the server never has to grow its own
 * vocabulary.
 */
export interface NewRecipeInput {
  title: string;
  body: string;
  ingredients: string[];
  categoryIds: number[];
  typeIds: number[];
}

/**
 * Adds a recipe in the caller's clinic and returns the row to list.
 *
 * Goes through the Next.js route handler at `/api/content/recipe`, which checks
 * the session's chiropractor role before forwarding, and then to Drupal's
 * `POST /api/headless/content/recipe/create`. The audience is the doctor's own
 * clinic, decided server-side; the payload sent here deliberately carries no
 * clinic field.
 *
 * A 401 is a lost session, reported as {@link RecipeAuthError} so the page can
 * send the user to log in. A 400 carries the endpoint's own message ("title is
 * required", "categories accepts at most 2 terms") straight back into the form.
 */
export async function createRecipe(input: NewRecipeInput): Promise<Recipe> {
  const response = await fetch("/api/content/recipe", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({
      title: input.title,
      body: input.body,
      ingredients: input.ingredients,
      categories: input.categoryIds,
      types: input.typeIds,
    }),
  });

  const body = (await response.json().catch(() => ({}))) as {
    message?: unknown;
    error?: unknown;
  };

  if (response.status === 401) {
    throw new RecipeAuthError("Your session has expired. Please sign in again.");
  }
  if (!response.ok) {
    throw new RecipeRequestError(
      typeof body.message === "string" && body.message
        ? body.message
        : "Unable to add this recipe.",
    );
  }

  // The handler returns the endpoint's serialised row, so the same mapper the
  // list uses turns it into a Recipe. A created recipe that does not map is a
  // server disagreement worth surfacing rather than a silent no-op.
  const recipe = toRecipes([body])[0];
  if (!recipe) {
    throw new RecipeRequestError("The recipe was created but could not be shown.");
  }

  return recipe;
}

export function filterRecipes(recipes: Recipe[], filter: RecipeFilter): Recipe[] {
  if (filter === "fav") return recipes.filter((r) => r.fav);
  return recipes;
}

/**
 * Free-text search over title, category, type and ingredients.
 *
 * Client-side because the endpoint has no search parameter and adding one would mean
 * a request per keystroke against a list this page already holds. Over the term lists
 * rather than the flattened `category`/`tag`, so a recipe matching on its second
 * category term is still findable — 97 of the 200 sampled carry two.
 */
export function searchRecipes(recipes: Recipe[], query: string): Recipe[] {
  if (!query) return recipes;
  const q = query.toLowerCase();

  return recipes.filter(
    (r) =>
      r.title.toLowerCase().includes(q) ||
      r.category.toLowerCase().includes(q) ||
      (r.tag?.toLowerCase().includes(q) ?? false) ||
      r.categories.some((c) => c.label.toLowerCase().includes(q)) ||
      r.types.some((t) => t.label.toLowerCase().includes(q)) ||
      r.ingredients.some((i) => i.name.toLowerCase().includes(q)),
  );
}

/** Re-exported so the page does not import from two places for one type. */
export type { Recipe };
export { toRecipes };
