import "server-only";
import { NextResponse } from "next/server";
import { drupalFetch } from "@/lib/drupal/client";

/**
 * POST /api/content/recipe/[id]/favourite — flag or unflag one recipe.
 *
 * Proxies `POST /api/headless/content/recipe/{node}/favourite`. The body is passed
 * through untouched: the endpoint reads `favourite` as a bool from JSON or a string
 * from a form post, and rejects only a shape it cannot read as one. Re-encoding it
 * here would be a second, weaker copy of that rule.
 *
 * The `{id}` is validated as an integer before it reaches the path, because it is
 * interpolated into a URL Drupal will match against `\d+`. A non-numeric value
 * would otherwise become a 404 from the route matcher rather than a 400 from here,
 * and the two mean different things to the caller.
 */
export async function POST(
  request: Request,
  context: { params: Promise<{ id: string }> },
): Promise<NextResponse> {
  const { id } = await context.params;

  if (!/^\d+$/.test(id)) {
    return NextResponse.json(
      { error: "bad_request", message: "Recipe id must be numeric." },
      { status: 400 },
    );
  }

  const body = await request.text();

  let response: Response;
  try {
    response = await drupalFetch(`/api/headless/content/recipe/${id}/favourite`, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      // An empty body is meaningful to the endpoint: it means "flip". Forwarding
      // "" rather than substituting "{}" keeps that reachable, and the client sends
      // an explicit value anyway.
      body,
    });
  } catch (error) {
    console.error("[api/content/favourite] Drupal request failed", error);
    return NextResponse.json({ error: "unavailable" }, { status: 502 });
  }

  if (response.status === 307 || response.status === 302) {
    return NextResponse.json(
      { error: "unauthenticated", message: "Please sign in again." },
      { status: 401 },
    );
  }

  const data = (await response.json().catch(() => ({}))) as {
    favourite?: unknown;
    error?: unknown;
  };

  if (!response.ok) {
    // 403 = the account lacks `flag favorites`; 404 = not this account's row.
    // Both are passed through with the endpoint's own message, because the client
    // shows it and "you can't favourite this" is more useful than a status code.
    return NextResponse.json(
      {
        error: typeof data.error === "string" ? data.error : "drupal_error",
        message:
          typeof data.error === "string" && data.error
            ? data.error
            : `Drupal responded ${response.status}`,
      },
      { status: response.status },
    );
  }

  return NextResponse.json(data);
}
