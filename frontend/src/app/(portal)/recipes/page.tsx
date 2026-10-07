import { redirect } from "next/navigation";
import { PortalShell } from "@/components/portal/portal-shell-server";
import { PortalAccessDenied } from "@/components/portal-access-denied";
import { fetchSession } from "@/lib/drupal/session";
import { resolvePortalAccess } from "@/lib/portal";
import { RecipesPage } from "@/components/portal/recipes/recipes-page";
import {
  fetchRecipeOptions,
  fetchRecipes,
  RecipesUnauthenticatedError,
} from "@/lib/recipes/data";
import type { Recipe, RecipeCapabilities, RecipeOptions } from "@/lib/recipes/types";

export const dynamic = "force-dynamic";

export default async function RecipesRoute() {
  let session = null;
  try {
    session = await fetchSession();
  } catch {
    redirect("/login");
  }

  if (!session) redirect("/login");
  if (session.capabilities?.billingOnly) redirect("/subscribe");

  const access = resolvePortalAccess(session.roles, session.capabilities, session.portalAccess);
  if (!access) return <PortalAccessDenied email={session.mail || session.name} />;

  // The library is fetched here and passed down, like the training and patients
  // pages: it is scoped to the signed-in user's clinic, so it cannot be cached
  // across users, and the browser has no Drupal session to fetch it with.
  //
  // A failed read is rendered as an error, not thrown. "This clinic has no recipes"
  // and "the backend is down" are different facts and the page has to be able to
  // say which — an unauthenticated session does go to login, because the session
  // check above passed but the API disagrees, and re-rendering would not fix that.
  let recipes: Recipe[] = [];
  let error: string | null = null;
  let capabilities: RecipeCapabilities | null = null;
  try {
    const fetched = await fetchRecipes();
    recipes = fetched.recipes;
    capabilities = fetched.capabilities;
  } catch (err) {
    if (err instanceof RecipesUnauthenticatedError) redirect("/login");
    error =
      err instanceof Error
        ? err.message
        : "The recipe library could not be loaded.";
  }

  // The create form's category and type options, fetched only for the caller who
  // can create: they exist for the "Add recipe" dialog, which no patient or
  // inactive chiropractor reaches. A failed read keeps the button but leaves the
  // dialog with a retry, so a broken options endpoint is not a broken page.
  let recipeOptions: RecipeOptions | null = null;
  if (capabilities?.canCreate === true) {
    try {
      recipeOptions = await fetchRecipeOptions();
    } catch (err) {
      if (err instanceof RecipesUnauthenticatedError) redirect("/login");
    }
  }

  return (
    <PortalShell audience={access.audience}>
      <RecipesPage
        audience={access.audience}
        recipes={recipes}
        error={error}
        canCreate={capabilities?.canCreate === true}
        recipeOptions={recipeOptions}
      />
    </PortalShell>
  );
}
