import "server-only";
import { NextResponse } from "next/server";
import { drupalFetch } from "@/lib/drupal/client";

/**
 * POST /api/messages/attachment — Upload a file and get its fid.
 *
 * The file is sent as multipart/form-data. Drupal's MessagesService::storeAttachment()
 * validates extension (pdf, docx, jpeg, png, xlsx, doc, xls), size (20 MB), and
 * stores it under private://. The response contains the fid for use in send.
 */
export async function POST(request: Request): Promise<NextResponse> {
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
    response = await drupalFetch("/api/headless/messages/attachment", {
      method: "POST",
      body: forward,
    });
  } catch (error) {
    console.error("[api/messages/attachment] Upload failed", error);
    return NextResponse.json({ error: "unavailable" }, { status: 502 });
  }

  const data = await response.json().catch(() => ({}));

  if (!response.ok) {
    return NextResponse.json(
      {
        error: data.error ?? "drupal_error",
        message: data.message ?? `Drupal responded ${response.status}`,
      },
      { status: response.status },
    );
  }

  return NextResponse.json(data, { status: response.status });
}