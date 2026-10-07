import Link from "next/link";
import { fetchSubscriptionCatalog } from "@/lib/drupal/subscriptions";
import { displayPlan, planPrice } from "@/lib/subscriptions/plans";
import {
  CLINIC_SUBSCRIPTION_DESCRIPTION,
  SUBSCRIPTION_AVAILABILITY_NOTE,
  SUBSCRIPTION_SUPPORT_EMAIL,
} from "@/lib/subscriptions/copy";
import { Button } from "@/components/ui/button";
import {
  Card,
  Checklist,
  Container,
  Eyebrow,
  GRID_4,
  Lead,
  Section,
  SectionTitle,
  pill,
} from "@/components/marketing/primitives";

/** `.price` — the Fraunces figure with its Figtree "/month" suffix. */
function Price({
  amount,
  period,
  onBrand = false,
}: {
  amount: string;
  period: string;
  onBrand?: boolean;
}) {
  return (
    <div className="mt-3.5 font-serif text-[44px] leading-none font-semibold">
      {amount}
      <small
        className={
          onBrand
            ? "font-sans text-[15px] font-medium text-brand-soft"
            : "font-sans text-[15px] font-medium text-muted-foreground"
        }
      >
        {" "}
        {period}
      </small>
    </div>
  );
}

/** `.b3` — the outlined pill the unfeatured plans use. */
function OutlineSubscribe({ href }: { href: string }) {
  return (
    <Button
      asChild
      variant="outline"
      size="lg"
      className={`${pill} mt-auto border-primary bg-transparent text-primary hover:bg-brand-soft`}
    >
      <Link href={href}>Subscribe now</Link>
    </Button>
  );
}

function PlanCard({
  name,
  amount,
  features,
  href,
  featured = false,
  period,
}: {
  name: string;
  amount: string;
  features: string[];
  href: string;
  featured?: boolean;
  period: string;
}) {
  return (
    <Card
      tone={featured ? "surface" : "canvas"}
      className={`flex flex-col gap-5 ${featured ? "border-primary bg-primary text-primary-foreground" : ""}`}
    >
      <div>
        <h3 className="font-sans text-[20px] font-bold tracking-[0.06em] uppercase">
          {name}
        </h3>
        <Price amount={amount} period={period} onBrand={featured} />
      </div>
      <Checklist items={features} onBrand={featured} />
      {featured ? (
        <Button
          asChild
          size="lg"
          className={`${pill} mt-auto bg-surface text-primary hover:bg-brand-soft`}
        >
          <Link href={href}>Subscribe now</Link>
        </Button>
      ) : (
        <OutlineSubscribe href={href} />
      )}
    </Card>
  );
}

export async function Pricing() {
  const catalog = await fetchSubscriptionCatalog().catch(() => null);
  const monthly = catalog?.plans.filter(plan => plan.per === "month") ?? [];
  const annual = catalog?.plans.filter(plan => plan.per === "year") ?? [];
  return (
    <Section id="pricing" tone="paper">
      <Container>
        <Eyebrow>Pricing</Eyebrow>
        <SectionTitle>Choose a plan, tailored to fit your needs</SectionTitle>
        <Lead>Choose a subscription for your clinic. Patient limits and store access depend on your plan. {CLINIC_SUBSCRIPTION_DESCRIPTION}</Lead>
        {!catalog?.plans.length ? <Card className="mt-12"><p>Subscription plans are temporarily unavailable. Please try again shortly.</p><Link href="/subscribe" className="mt-4 inline-block text-primary underline">View subscription plans</Link></Card> : <>
          <div className={`mt-12 grid items-stretch gap-5 ${GRID_4}`}>
            {monthly.map(plan => <PlanCard key={plan.id} name={plan.name} amount={planPrice(plan)} period={`/${plan.per}`} features={displayPlan(plan).features ?? []} href={`/subscribe?plan=${plan.id}`} featured={plan.laser} />)}
          </div>
          {annual.map(plan => <Card key={plan.id} className="mt-5 flex flex-wrap items-center justify-between gap-5 px-8 py-7">
            <div className="max-w-[620px]">
              <h3 className="font-serif text-[24px] leading-[1.1] font-medium tracking-[-0.01em]">{plan.name}</h3>
              <Checklist items={displayPlan(plan).features} className="mt-4 sm:grid-cols-2" />
            </div>
            <div className="flex flex-wrap items-center gap-4 sm:gap-6">
              <Price amount={planPrice(plan)} period={`/${plan.per}`} />
              <Button asChild size="lg" className={`${pill} bg-flame text-white hover:bg-[#8c3517]`}><Link href={`/subscribe?plan=${plan.id}`}>Subscribe now</Link></Button>
            </div>
          </Card>)}
          {catalog.plans.some(plan => plan.trialPolicy === "request_only") ? <p className="mt-5 text-[15px] text-muted-foreground">To ask about trial eligibility, email <a href={"mailto:" + SUBSCRIPTION_SUPPORT_EMAIL} className="font-bold text-primary underline underline-offset-2">{SUBSCRIPTION_SUPPORT_EMAIL}</a>. Checkout starts a paid subscription.</p> : null}
          <p className="mt-4 text-[14px] text-muted-foreground">{SUBSCRIPTION_AVAILABILITY_NOTE}</p>
        </>}
      </Container>
    </Section>
  );
}
