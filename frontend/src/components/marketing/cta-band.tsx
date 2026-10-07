import { Button } from "@/components/ui/button";
import { Container, ctaRow, pillCta } from "@/components/marketing/primitives";

/**
 * The closing call to action, and the landing place for `id="demo"` — the hero's
 * "Request a demo" scrolls here. There is no demo form behind the mock-up's
 * `#demo`, so the button opens an email to the address in the footer.
 */
const DEMO_EMAIL =
  "mailto:support@chirothintracker.com?subject=ChiroThin%20Tracker%20demo%20request";

export function CtaBand() {
  return (
    <section id="demo" className="scroll-mt-16 pb-16 sm:pb-24">
      <Container>
        <div className="rounded-[28px] bg-primary px-4 py-12 text-center text-primary-foreground sm:px-10 sm:py-16">
          <h2 className="font-serif text-[clamp(30px,4vw,46px)] leading-[1.1] font-medium tracking-[-0.01em] text-white">
            Ready to simplify your practice?
          </h2>
          <p className="mx-auto mt-4 max-w-[560px] text-[18px] text-brand-soft">
            See the doctor portal in a short demo, or subscribe and start
            enrolling patients.
          </p>
          <div className={`mt-8 ${ctaRow} sm:justify-center`}>
            <Button
              asChild
              size="lg"
              className={`${pillCta} bg-surface text-primary hover:bg-brand-soft`}
            >
              <a href={DEMO_EMAIL}>Request a demo</a>
            </Button>
            <Button
              asChild
              size="lg"
              className={`${pillCta} bg-flame text-white hover:bg-[#8c3517]`}
            >
              <a href="#pricing">Subscribe now</a>
            </Button>
          </div>
        </div>
      </Container>
    </section>
  );
}