import "server-only";
import { NextResponse } from "next/server";
import { drupalFetch } from "@/lib/drupal/client";

export async function GET(): Promise<NextResponse> {
  try {
    const response = await drupalFetch("/api/headless/patients?intake_count=1");
    if (!response.ok) {
      const status = response.status === 302 || response.status === 307 ? 401 : response.status;
      return NextResponse.json({ error: "unavailable" }, { status });
    }
    const data = await response.json();
    if (!Number.isInteger(data.intakeNewCount) || data.intakeNewCount < 0) {
      return NextResponse.json({ error: "invalid_response" }, { status: 502 });
    }
    return NextResponse.json({ intakeNewCount: data.intakeNewCount });
  } catch {
    return NextResponse.json({ error: "unavailable" }, { status: 502 });
  }
}
