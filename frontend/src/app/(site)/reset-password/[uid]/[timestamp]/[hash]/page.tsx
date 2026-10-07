import Link from "next/link";
import type { Metadata } from "next";
import { PasswordRecoveryForm } from "@/components/auth/password-recovery-form";

export const metadata: Metadata = { title: "Reset Password — ChiroThin", robots: { index: false, follow: false }, referrer: "no-referrer" };
export const dynamic = "force-dynamic";

export default async function ResetPasswordPage({ params }: { params: Promise<{ uid: string; timestamp: string; hash: string }> }) {
  const { uid, timestamp, hash } = await params;
  if (!/^[1-9]\d*$/.test(uid) || !/^[1-9]\d*$/.test(timestamp) || !Number.isSafeInteger(Number(uid)) || !Number.isSafeInteger(Number(timestamp)) || !/^[A-Za-z0-9_-]{43}$/.test(hash)) {
    return <div className="mx-auto max-w-md px-5 py-12"><h1 className="font-serif text-2xl">Invalid reset link</h1><p className="mt-3">Request a new link to reset your password.</p><Link href="/forgot-password" className="mt-4 inline-block text-primary underline">Request a new link</Link></div>;
  }
  return <PasswordRecoveryForm token={{ uid: Number(uid), timestamp: Number(timestamp), hash }} />;
}
