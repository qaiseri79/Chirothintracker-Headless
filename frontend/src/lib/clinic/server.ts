import "server-only";
import { drupalJson } from "@/lib/drupal/client";
import type { ClinicSnapshot } from "./types";
export function fetchClinic(): Promise<ClinicSnapshot> {
  return drupalJson<ClinicSnapshot>("/api/headless/clinic");
}
