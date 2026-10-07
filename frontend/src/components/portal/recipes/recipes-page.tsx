"use client";

import { useCallback, useMemo, useState } from "react";
import { useRouter } from "next/navigation";
import { Search, Utensils } from "lucide-react";
import { RecipeCard } from "@/components/portal/recipes/recipe-card";
import { AddRecipeDialog } from "@/components/portal/recipes/add-recipe-dialog";
import { Toast } from "@/components/portal/toast";
import { filterRecipes, searchRecipes, RecipeAuthError } from "@/lib/recipes/api";
import { groupByCategory, type Recipe, type RecipeFilter, type RecipeOptions } from "@/lib/recipes/types";
import type { PortalAudience } from "@/lib/portal";

interface RecipesPageProps {
  audience: PortalAudience;
  /**
   * The whole library, fetched on the server.
   *
   * Empty when the server could not reach the backend — `error` then carries the
   * reason. The page does not fetch for itself, so there is no loading state here:
   * either the rows arrived with the HTML or `error` is set.
   */
  recipes: Recipe[];
  /** Why the library is empty, or null when it loaded. */
  error?: string | null;
  /**
   * Whether the backend would admit a recipe create for this caller. The "Add
   * recipe" button shows only for active chiropractors; patients and inactive
   * doctors never see it because the endpoint would refuse them.
   */
  canCreate: boolean;
  /**
   * The form's category and type options, fetched server-side for the caller who
   * can create. `null` when they failed to load — the dialog then offers a retry
   * instead of rendering an empty form.
   */
  recipeOptions: RecipeOptions | null;
}

export function RecipesPage({
  audience,
  recipes: loaded,
  error,
  canCreate,
  recipeOptions,
}: RecipesPageProps) {
  const router = useRouter();
  const [recipes, setRecipes] = useState<Recipe[]>(loaded);
  const [filter, setFilter] = useState<RecipeFilter>("all");
  const [category, setCategory] = useState("");
  const [search, setSearch] = useState("");
  const [favouriteError, setFavouriteError] = useState<string | null>(null);
  const [addOpen, setAddOpen] = useState(false);
  const [toast, setToast] = useState<string | null>(null);

  /**
   * A confirmed favourite state, applied to the row the list holds.
   *
   * Without this the "Favorites only" filter reads the `fav` from the server render:
   * un-favouriting while the filter is on would leave the recipe listed until the
   * next reload.
   */
  const handleFavouriteChange = useCallback((id: number, favourite: boolean) => {
    setRecipes((prev) => prev.map((r) => (r.id === id ? { ...r, fav: favourite } : r)));
  }, []);

  /**
   * A lost session is not a per-card failure: every star on the page is now broken
   * the same way, so the user is sent to log in rather than shown 634 identical
   * messages. Anything else is shown once for the page — a 403 here means the
   * account lacks `flag favorites`, which is true of every row.
   */
  const handleFavouriteError = useCallback(
    (err: unknown) => {
      if (err instanceof RecipeAuthError) {
        router.replace("/login");
        return;
      }
      setFavouriteError(
        err instanceof Error ? err.message : "Unable to update this recipe.",
      );
    },
    [router],
  );

  /**
   * A newly created recipe lists immediately, above everything else — it is the
   * freshest row and the one the doctor is looking for — with no reload and at
   * the top of whichever group it lands in.
   */
  const handleRecipeCreated = useCallback((recipe: Recipe) => {
    setRecipes((prev) => [recipe, ...prev]);
    setToast("Recipe published");
  }, []);

  const categories = useMemo(
    () => Array.from(new Set(recipes.map((r) => r.category))).sort(),
    [recipes],
  );

  // A category that no longer exists would silently show an empty library, so it
  // resolves to "" during render rather than being corrected in an effect. Deriving
  // it means no second render pass and no setState in an effect.
  const activeCategory = category && categories.includes(category) ? category : "";

  const groups = useMemo(() => {
    let filtered = filterRecipes(recipes, filter);
    filtered = searchRecipes(filtered, search);
    if (activeCategory) {
      filtered = filtered.filter((r) => r.category === activeCategory);
    }
    return groupByCategory(filtered);
  }, [recipes, filter, search, activeCategory]);

  const header = (
    <div className="flex flex-wrap items-start justify-between gap-3">
      <div>
        <h1 className="font-serif text-2xl text-foreground">Recipes</h1>
        <p className="mt-1 text-sm text-muted-foreground">
          Program-approved meals, organized by category.
        </p>
      </div>
      {canCreate && (
        <button
          type="button"
          onClick={() => setAddOpen(true)}
          className="inline-flex items-center justify-center gap-2 rounded-xl px-4 py-2.5 text-sm font-semibold transition-colors disabled:opacity-40 bg-primary text-white shadow-sm hover:bg-brand-dark"
        >
          <Utensils className="size-[18px] shrink-0" aria-hidden="true" />
          Add recipe
        </button>
      )}
    </div>
  );

  if (error) {
    return (
      <div className="space-y-4">
        {header}
        <div
          role="alert"
          className="rounded-xl border border-border bg-surface p-5 shadow-panel"
        >
          <p className="text-sm text-foreground">{error}</p>
          <button
            type="button"
            onClick={() => router.refresh()}
            className="mt-3 rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-primary-dark"
          >
            Try again
          </button>
        </div>
      </div>
    );
  }

  return (
    <div className="space-y-6">
      {header}

      {canCreate && (
        <AddRecipeDialog
          open={addOpen}
          onOpenChange={setAddOpen}
          options={recipeOptions}
          onCreated={handleRecipeCreated}
          onAuthError={() => router.replace("/login")}
          onRetry={() => router.refresh()}
        />
      )}

      {/* Filter bar */}
      <div className="rounded-xl border border-border bg-surface p-5 shadow-panel">
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-4">
          <div className="sm:col-span-2">
            <label
              htmlFor="recipe-search"
              className="mb-1.5 block text-xs font-medium text-muted-foreground"
            >
              Recipe Search
            </label>
            <div className="relative">
              <Search
                className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground"
                aria-hidden="true"
              />
              <input
                id="recipe-search"
                type="text"
                placeholder="Search recipes…"
                value={search}
                onChange={(e) => setSearch(e.target.value)}
                className="w-full rounded-lg border border-border bg-canvas py-2.5 pl-9 pr-3.5 text-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
              />
            </div>
          </div>
          <div>
            <label
              htmlFor="recipe-category"
              className="mb-1.5 block text-xs font-medium text-muted-foreground"
            >
              Category
            </label>
            <select
              id="recipe-category"
              value={activeCategory}
              onChange={(e) => setCategory(e.target.value)}
              className="w-full rounded-lg border border-border bg-canvas px-3.5 py-2.5 text-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
            >
              <option value="">- Any -</option>
              {categories.map((c) => (
                <option key={c} value={c}>
                  {c}
                </option>
              ))}
            </select>
          </div>
          <div>
            <label
              htmlFor="recipe-fav"
              className="mb-1.5 block text-xs font-medium text-muted-foreground"
            >
              Favorites
            </label>
            <select
              id="recipe-fav"
              value={filter}
              onChange={(e) => setFilter(e.target.value as RecipeFilter)}
              className="w-full rounded-lg border border-border bg-canvas px-3.5 py-2.5 text-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
            >
              <option value="all">- Any -</option>
              <option value="fav">Favorites only</option>
            </select>
          </div>
        </div>
      </div>

      {/* One message for the page rather than one per card: a 403 here is the account
          lacking `flag favorites`, which is true of every row. */}
      {favouriteError && (
        <p
          role="alert"
          className="rounded-lg border border-border bg-surface px-4 py-2.5 text-sm text-foreground"
        >
          {favouriteError}
        </p>
      )}

      {/* Groups */}
      {groups === undefined || Object.keys(groups).length === 0 ? (
        <p className="py-10 text-center text-sm text-muted-foreground">
          No recipes match your search.
        </p>
      ) : (
        <div className="space-y-8">
          {Object.entries(groups).map(([cat, rows]) => (
            <div key={cat}>
              <h2 className="mb-3 font-serif text-xl text-foreground">{cat}</h2>
              <div className="grid grid-cols-1 gap-4 md:grid-cols-2 items-start">
                {rows.map((recipe) => (
                  <RecipeCard
                    key={recipe.id}
                    recipe={recipe}
                    audience={audience}
                    onFavouriteChange={handleFavouriteChange}
                    onFavouriteError={handleFavouriteError}
                  />
                ))}
              </div>
            </div>
          ))}
        </div>
      )}

      {toast && <Toast message={toast} onClose={() => setToast(null)} />}
    </div>
  );
}
