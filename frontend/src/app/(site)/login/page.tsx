"use client";

import { useState } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { ArrowLeft, LogIn } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { accountHome } from "@/lib/portal";
import { useAuth } from "@/lib/auth";

/**
 * The shared sign-in form for every portal account — patients and
 * chiropractors, active or read-only.
 *
 * It only authenticates. Whether the account that just signed in is allowed on
 * a given dashboard, and what it may do there, is settled by that dashboard's
 * layout and components (`lib/portal.ts`), never here: a chiropractor and an
 * archived patient both come through this one form, and refusing either at the
 * door would be an authorisation decision in the wrong layer.
 */
export default function LoginPage() {
  const { login } = useAuth();
  const router = useRouter();
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);

  async function handleSubmit(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setError(null);
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email.trim())) {
      setError("Enter a valid email address.");
      return;
    }
    setSubmitting(true);
    try {
      const account = await login(email, password);
      router.push(accountHome(account));
    } catch (err) {
      setError(err instanceof Error ? err.message : "Unable to log in.");
      setSubmitting(false);
    }
  }

  return (
    <div className="mx-auto flex min-h-full w-full max-w-5xl items-center justify-center px-5 py-12 sm:px-8">
      <div className="w-full max-w-md rounded-xl border border-border bg-surface p-8 shadow-panel">
        <h1 className="font-serif text-2xl text-foreground">Log in</h1>
        <p className="mt-1 text-sm text-muted-foreground">
          Log in with the email and password from your portal account.
        </p>

        <form className="mt-6 space-y-4" onSubmit={handleSubmit} noValidate>
          <div className="space-y-1.5">
            <Label htmlFor="email">Email</Label>
            <Input
              id="email"
              name="email"
              type="email"
              autoComplete="email"
              placeholder="you@example.com"
              value={email}
              aria-invalid={error ? true : undefined}
              onChange={(event) => {
                setEmail(event.target.value);
                setError(null);
              }}
            />
          </div>
          <div className="space-y-1.5">
            <Label htmlFor="password">Password</Label>
            <Input
              id="password"
              name="password"
              type="password"
              autoComplete="current-password"
              placeholder="••••••••"
              value={password}
              onChange={(event) => {
                setPassword(event.target.value);
                setError(null);
              }}
            />
          </div>

          {error ? (
            <p role="alert" className="rounded-lg border border-destructive/30 bg-destructive/10 p-3 text-sm text-destructive">
              {error}
            </p>
          ) : null}

          <div className="text-right">
            <Link href="/forgot-password" className="text-sm text-primary underline-offset-4 hover:underline">
              Forgot password?
            </Link>
          </div>

          <Button type="submit" className="w-full px-6 font-semibold" disabled={submitting}>
            <LogIn data-icon="inline-start" />
            {submitting ? "Logging in…" : "Log in"}
          </Button>
        </form>

        <div className="mt-6 border-t border-border pt-4">
          <Link
            href="/"
            className="inline-flex items-center gap-1.5 text-sm text-primary underline-offset-4 hover:underline"
          >
            <ArrowLeft />
            Back to home
          </Link>
        </div>
      </div>
    </div>
  );
}