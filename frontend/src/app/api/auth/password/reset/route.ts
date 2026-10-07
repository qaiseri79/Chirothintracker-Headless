import { passwordResetProxy } from "@/lib/drupal/password-reset";

export async function POST(request: Request) {
  return passwordResetProxy(request, "reset");
}
