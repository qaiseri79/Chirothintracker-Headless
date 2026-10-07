import "server-only";
import { NextResponse } from "next/server";
import { drupalFetch } from "@/lib/drupal/client";

export async function GET(): Promise<NextResponse> {
  try {
    const response = await drupalFetch("/api/headless/messages?unread_count=1");
    if (!response.ok) {
      return NextResponse.json({ error: "unavailable" }, { status: response.status });
    }
    const data = await response.json();
    if (!Number.isInteger(data.unread_count) || data.unread_count < 0) {
      return NextResponse.json({ error: "invalid_response" }, { status: 502 });
    }
    return NextResponse.json({ unread_count: data.unread_count });
  } catch {
    return NextResponse.json({ error: "unavailable" }, { status: 502 });
  }
}
