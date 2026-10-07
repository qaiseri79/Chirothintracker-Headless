import type { Metadata } from "next";
import Link from "next/link";
import { fetchSubscriptionCatalog } from "@/lib/drupal/subscriptions";
import { SubscribeFlow } from "@/components/subscriptions/subscribe-flow";
import { subscribeTheme } from "@/components/design-preview/theme";

export const metadata: Metadata = { title: "Doctor subscription — ChiroThin Tracker" };
export default async function SubscribePage({ searchParams }: { searchParams: Promise<{ plan?: string }> }) {
  const { plan } = await searchParams;
  const catalog = await fetchSubscriptionCatalog().catch(() => null);
  return <div style={subscribeTheme} className="mx-auto w-full max-w-[1120px] px-5 pb-10 font-sans">
    {catalog?.plans.length ? <SubscribeFlow catalog={catalog} initialPlanId={Number(plan)} /> : <div className="py-16 text-center"><h1 className="font-serif text-3xl">Subscriptions are temporarily unavailable</h1><p className="mt-4">Please try again in a moment.</p><Link href="/subscribe" className="mt-5 inline-block text-brand underline">Retry</Link></div>}
  </div>;
}
