import "server-only";
import { NextResponse } from "next/server";
import { drupalFetch } from "@/lib/drupal/client";

/**
 * GET /api/messages/attachment/[fid] — Download an attachment.
 *
 * Streams the file from Drupal through this proxy so the session cookie is
 * forwarded and Drupal's authorization (party to the referencing message) is
 * enforced. Drupal returns a BinaryFileResponse with the correct headers.
 */
export async function GET(
  _request: Request,
  { params }: { params: Promise<{ fid: string }> },
): Promise<NextResponse> {
  const { fid } = await params;
  const fidNum = Number(fid);

  if (!Number.isFinite(fidNum) || fidNum <= 0) {
    return NextResponse.json({ error: "bad_request", message: "Invalid fid." }, { status: 400 });
  }

  let response: Response;
  try {
    response = await drupalFetch(`/api/headless/messages/attachment/${fidNum}`, {
      method: "GET",
    });
  } catch (error) {
    console.error("[api/messages/attachment/[fid]] Download failed", error);
    return NextResponse.json({ error: "unavailable" }, { status: 502 });
  }

  if (!response.ok) {
    const status = response.status;
    return NextResponse.json(
      { error: "drupal_error", message: `Drupal responded ${status}` },
      { status },
    );
  }

  // Stream the file through
  const contentType = response.headers.get("content-type") ?? "application/octet-stream";
  const contentDisposition = response.headers.get("content-disposition") ?? `attachment; filename="${fid}"`;
  const contentLength = response.headers.get("content-length");

  const headers = new Headers();
  headers.set("Content-Type", contentType);
  headers.set("Content-Disposition", contentDisposition);
  if (contentLength) headers.set("Content-Length", contentLength);

  return new NextResponse(response.body, {
    status: 200,
    headers,
  });
}