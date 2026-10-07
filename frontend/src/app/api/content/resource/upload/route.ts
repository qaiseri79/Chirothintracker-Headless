import "server-only";
import { NextResponse } from "next/server";
import { drupalFetch } from "@/lib/drupal/client";
import { fetchSession } from "@/lib/drupal/session";
import { resolvePortalAccess } from "@/lib/portal";

/**
 * POST /api/content/resource/upload — Store a resource file and get its fid.
 *
 * The first half of a resource create with a file: the file arrives as
 * multipart/form-data in a `files[attachment]` part (same shape as the messages
 * attachment upload) and is forwarded to Drupal, which validates the extension
 * (PDF, images, office documents, video), the 20 MB cap, stores the bytes under
 * public:// and answers with `{ fid, name, size, mime }`. The `fid` is what the
 * subsequent `/api/content/resource` create names as `resource`.
 *
 * Gated exactly like the create route: only an active chiropractor may store a
 * file, because only the create route could ever attach it to a node. Without
 * this gate a caller outside the role could litter public:// with files.
 *
 * The multipart body is passed through untouched — re-encoding it here would
 * risk the boundary or an accidental truncation of the bytes.
 */
export async function POST(request: Request): Promise<NextResponse> {
  let session;
  try {
    session = await fetchSession();
  } catch (error) {
    console.error("[api/content/resource/upload] session check failed", error);
    return NextResponse.json(
      { error: "unavailable", message: "The server could not be reached." },
      { status: 502 },
    );
  }

  if (!session) {
    return NextResponse.json(
      { error: "unauthenticated", message: "Please sign in again." },
      { status: 401 },
    );
  }

  if (session.capabilities?.billingOnly) {
    return NextResponse.json(
      { error: "billing", message: "Finish subscription setup before uploading files." },
      { status: 403 },
    );
  }

  const access = resolvePortalAccess(
    session.roles,
    session.capabilities,
    session.portalAccess,
  );

  if (!access || access.audience !== "chiropractor" || access.readOnly) {
    return NextResponse.json(
      { error: "forbidden", message: "Only an active doctor may add resources." },
      { status: 403 },
    );
  }

  const form = await request.formData();
  const file = form.get("files[attachment]") as File | null;

  if (!file) {
    return NextResponse.json(
      { error: "bad_request", message: "No file provided." },
      { status: 400 },
    );
  }

  const forward = new FormData();
  forward.append("files[attachment]", file);

  let response: Response;
  try {
    response = await drupalFetch("/api/headless/content/chirothin_resource/upload", {
      method: "POST",
      body: forward,
    });
  } catch (error) {
    console.error("[api/content/resource/upload] Upload failed", error);
    return NextResponse.json(
      { error: "unavailable", message: "The file could not be uploaded." },
      { status: 502 },
    );
  }

  if (response.status === 307 || response.status === 302) {
    return NextResponse.json(
      { error: "unauthenticated", message: "Please sign in again." },
      { status: 401 },
    );
  }

  const data = (await response.json().catch(() => ({}))) as {
    error?: unknown;
  };

  if (!response.ok) {
    // 400 = the upload's own rules (extension, size, empty); 403 = the role gate
    // catching something the session check above missed.
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

  return NextResponse.json(data, { status: 201 });
}