"use client";

import { useRef, useState } from "react";
import Link from "next/link";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";

type ResetToken = { uid: number; timestamp: number; hash: string };

export function PasswordRecoveryForm({ token }: { token?: ResetToken }) {
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [confirmation, setConfirmation] = useState("");
  const [pending, setPending] = useState(false);
  const pendingRef = useRef(false);
  const [error, setError] = useState<string | null>(null);
  const [success, setSuccess] = useState<string | null>(null);

  async function submit(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (pendingRef.current) return;
    setError(null);
    if (!token && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email.trim())) {
      setError("Enter a valid email address."); return;
    }
    if (token && ([...password].length < 8 || new TextEncoder().encode(password).length > 512)) {
      setError("Use a password with at least 8 characters (maximum 512 bytes)."); return;
    }
    if (token && password !== confirmation) {
      setError("The passwords do not match."); return;
    }
    pendingRef.current = true;
    setPending(true);
    try {
      const response = await fetch(`/api/auth/password/${token ? "reset" : "request"}`, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(token ? { ...token, password } : { email: email.trim() }),
      });
      const data = await response.json().catch(() => null) as { message?: string } | null;
      if (!response.ok) throw new Error(data?.message ?? "Unable to reset your password. Please try again.");
      setSuccess(data?.message ?? "Check your email for a password reset link.");
      setPassword(""); setConfirmation("");
    } catch (err) {
      setError(err instanceof Error ? err.message : "Unable to reset your password. Please try again.");
    } finally {
      pendingRef.current = false; setPending(false);
    }
  }

  return (
    <div className="mx-auto flex min-h-full w-full max-w-5xl items-center justify-center px-5 py-12 sm:px-8">
      <div className="w-full max-w-md rounded-xl border border-border bg-surface p-8 shadow-panel">
        <h1 className="font-serif text-2xl text-foreground">{token ? "Choose a new password" : "Forgot your password?"}</h1>
        <p className="mt-1 text-sm text-muted-foreground">{token ? "Enter and confirm your new portal password." : "Enter your account email and we’ll send you a password reset link."}</p>
        {success ? (
          <p role="status" className="mt-6 rounded-lg border border-primary/30 bg-primary-soft p-3 text-sm text-primary">{success}</p>
        ) : (
          <form onSubmit={submit} className="mt-6 space-y-4" noValidate>
            {token ? (
              <>
                <div className="space-y-1.5">
                  <Label htmlFor="new-password">New password</Label>
                  <Input id="new-password" name="password" type="password" autoComplete="new-password" value={password} onChange={(e) => setPassword(e.target.value)} required aria-describedby="password-help" />
                  <p id="password-help" className="text-xs text-muted-foreground">Use at least 8 characters.</p>
                </div>
                <div className="space-y-1.5">
                  <Label htmlFor="confirm-password">Confirm password</Label>
                  <Input id="confirm-password" name="confirmation" type="password" autoComplete="new-password" value={confirmation} onChange={(e) => setConfirmation(e.target.value)} required />
                </div>
              </>
            ) : (
              <div className="space-y-1.5">
                <Label htmlFor="reset-email">Email</Label>
                <Input id="reset-email" name="email" type="email" autoComplete="email" value={email} onChange={(e) => setEmail(e.target.value)} placeholder="you@example.com" required />
              </div>
            )}
            {error && <p role="alert" className="rounded-lg border border-destructive/30 bg-destructive/10 p-3 text-sm text-destructive">{error}</p>}
            <Button type="submit" className="w-full font-semibold" disabled={pending}>{pending ? (token ? "Saving password…" : "Sending…") : (token ? "Reset password" : "Send reset link")}</Button>
          </form>
        )}
        <div className="mt-6 flex flex-wrap gap-4 border-t border-border pt-4 text-sm">
          <Link href="/login" className="text-primary hover:underline">Back to login</Link>
          {token && !success && <Link href="/forgot-password" className="text-primary hover:underline">Request a new link</Link>}
          {!token && success && <button type="button" className="text-primary hover:underline" onClick={() => setSuccess(null)}>Try another email</button>}
        </div>
      </div>
    </div>
  );
}
