import { NextResponse } from "next/server";
import { drupalFetch } from "@/lib/drupal/client";

type Context = { params: Promise<{ id: string; path?: string[] }> };
async function proxy(request: Request, context: Context) {
  const { id, path = [] } = await context.params;
  if (!/^[1-9]\d*$/.test(id) || !Number.isSafeInteger(Number(id))) return NextResponse.json({ error: "Invalid patient." }, { status: 400 });
  const suffix = path.join("/");
  const allowed = suffix === "" || suffix === "photo" || suffix === "photos" || suffix === "notes" || suffix === "sessions" || suffix === "progress" || suffix === "files" || /^(files|photos)\/[1-9]\d*$/.test(suffix);
  if (!allowed) return NextResponse.json({ error: "Unknown patient action." }, { status: 404 });
  const url = new URL(request.url);
  let body: BodyInit | undefined;
  const headers: Record<string, string> = {};
  try {
    if (request.method === "POST") {
      if (suffix === "sessions" && request.headers.get("Content-Type")?.includes("multipart/form-data")) {
        const form = await request.formData();
        const photos = form.getAll("photos[]");
        if (photos.length > 10 || photos.some((photo) => !(photo instanceof File) || photo.size > 20 * 1024 * 1024)) return NextResponse.json({ error: "Choose up to 10 photos, 20 MB each." }, { status: 422 });
        body = form;
      } else if (suffix === "photos" && request.headers.get("Content-Type")?.includes("multipart/form-data")) {
        const form = await request.formData();
        const photos = form.getAll("photos[]");
        if (photos.length > 10 || photos.some((photo) => !(photo instanceof File) || photo.size > 20 * 1024 * 1024)) return NextResponse.json({ error: "Choose up to 10 before and after photos, 20 MB each." }, { status: 422 });
        const profile = form.get("profilePhoto");
        if (profile && (!(profile instanceof File) || profile.size > 20 * 1024 * 1024)) return NextResponse.json({ error: "Choose a profile photo up to 20 MB." }, { status: 422 });
        body = form;
      } else if (suffix === "files") {
        const form = await request.formData();
        const file = form.get("file");
        if (!(file instanceof File) || file.size > 20 * 1024 * 1024) return NextResponse.json({ error: "Choose a file up to 20 MB." }, { status: 422 });
        body = new FormData(); (body as FormData).append("file", file);
      } else {
        try { body = JSON.stringify(await request.json()); } catch { return NextResponse.json({ error: "Invalid request." }, { status: 400 }); }
        headers["Content-Type"] = "application/json";
      }
    }
    const query = new URLSearchParams();
    if (url.searchParams.has("section")) query.set("section", url.searchParams.get("section")!);
    if (url.searchParams.has("offset")) query.set("offset", url.searchParams.get("offset")!);
    const upstream = await drupalFetch(`/api/headless/patients/${id}/summary${suffix ? `/${suffix}` : ""}${query.size ? `?${query}` : ""}`, { method: request.method, headers, body });
    if ([301,302,303,307,308,401].includes(upstream.status)) return NextResponse.json({ error: "Your session has expired." }, { status: 401 });
    if (upstream.ok && (suffix === "photo" || /^(files|photos)\//.test(suffix)) && request.method === "GET") {
      const outgoing = new Headers({ "Cache-Control": "private, no-store", "X-Content-Type-Options": "nosniff" });
      for (const key of ["Content-Type", "Content-Disposition", "Content-Length"]) { const value = upstream.headers.get(key); if (value) outgoing.set(key, value); }
      return new Response(upstream.body, { headers: outgoing });
    }
    const data = await upstream.json().catch(() => null);
    return NextResponse.json(upstream.status >= 500 ? { error: "Patient data is unavailable." } : data ?? { error: "Patient data is unavailable." }, { status: upstream.status >= 500 ? 502 : upstream.status, headers: { "Cache-Control": "private, no-store" } });
  } catch { return NextResponse.json({ error: "Patient data is unavailable. Please try again." }, { status: 502 }); }
}
export const GET = proxy;
export const POST = proxy;
export const DELETE = proxy;
