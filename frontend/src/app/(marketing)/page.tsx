import { Hero } from "@/components/marketing/hero";
import { TrustStrip } from "@/components/marketing/trust-strip";
import { VideoTours, WhyTracker } from "@/components/marketing/feature-sections";
import { HowItWorks } from "@/components/marketing/how-it-works";
import { Pricing } from "@/components/marketing/pricing";
import { Faq } from "@/components/marketing/faq";
import { CtaBand } from "@/components/marketing/cta-band";

/**
 * The marketing landing page.
 *
 * A port of "ChiroThin Tracker New Front Page - B · Logo colors (teal).html"
 * (repo root, `New Design/`); the palette and faces live in
 * `components/marketing/theme.ts` and the section layout in
 * `components/marketing/primitives.tsx`. Header and footer come from
 * `(marketing)/layout.tsx`.
 */
export default function MarketingHomePage() {
  return (
    <>
      <Hero />
      <TrustStrip />
      <VideoTours />
      <WhyTracker />
      <HowItWorks />
      <Pricing />
      <Faq />
      <CtaBand />
    </>
  );
}