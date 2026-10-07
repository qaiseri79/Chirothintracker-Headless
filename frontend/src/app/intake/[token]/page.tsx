import type { Metadata } from "next";
import { IntakeForm } from "@/components/intake/intake-form";
import { resolveInvite } from "@/lib/intake/invite";

export const metadata: Metadata = {
  title: "Patient Intake",
};

export const dynamic = "force-dynamic";

function InvalidLink() {
  return (
    <main className="flex min-h-full items-center justify-center px-5 py-12">
      <div className="w-full max-w-md rounded-xl border border-border bg-surface p-8 text-center shadow-panel">
        <h1 className="font-serif text-2xl text-foreground">This link is no longer valid</h1>
        <p className="mt-2 text-sm text-muted-foreground">
          Intake links are single-use and expire. Please ask your clinic for a
          fresh link.
        </p>
      </div>
    </main>
  );
}

export default async function IntakePage({ params }: PageProps<"/intake/[token]">) {
  const { token } = await params;
  const resolved = await resolveInvite(token);

  if (!resolved.ok) {
    return <InvalidLink />;
  }

  return (
    <IntakeForm token={token} brand={resolved.invite.brand} legal={resolved.invite.legal} />
  );
}