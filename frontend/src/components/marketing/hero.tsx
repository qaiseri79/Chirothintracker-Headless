import Link from "next/link";
import { Button } from "@/components/ui/button";
import { Container, GRID_HERO, ctaRow, pillCta } from "@/components/marketing/primitives";

/**
 * The illustrative patient card beside the hero copy: an avatar, a progress
 * bar, a weight-loss trend line and three at-a-glance stats. Every number here
 * is sample data, which the card says out loud.
 */
function SamplePatientCard() {
  return (
    <div
      className="rounded-[20px] border border-border bg-surface p-6"
      style={{ boxShadow: "0 30px 60px -30px rgba(16,24,39,.35)" }}
    >
      <div className="flex items-center gap-3.5">
        <div className="flex size-[46px] items-center justify-center rounded-full bg-brand-soft font-bold text-primary">
          SP
        </div>
        <div className="flex-1">
          <div className="font-bold">Sample patient</div>
          <div className="text-[13px] text-muted-foreground">
            Red Light + Weight Loss · Day 48
          </div>
        </div>
        <span
          className="rounded-full px-2.5 py-1 text-[12px] font-bold"
          style={{ background: "#fbe9e1", color: "#8c3517" }}
        >
          New
        </span>
      </div>

      <div className="mt-[22px] flex justify-between text-[13px] text-muted-foreground">
        <span>Progress to goal</span>
        <b className="text-foreground">62%</b>
      </div>
      <div className="mt-2 h-2.5 rounded-full bg-border">
        <div className="h-full w-[62%] rounded-full bg-primary" />
      </div>

      <svg
        viewBox="0 0 240 80"
        className="mt-[22px] w-full"
        fill="none"
        role="img"
        aria-label="Sample weight-loss trend over 48 days, trending down towards the goal line"
      >
        <path
          d="M0 12C25 14 35 30 60 32S95 46 120 50 165 62 190 66 225 70 240 72V80H0Z"
          fill="currentColor"
          className="text-primary"
          opacity="0.1"
        />
        <path
          d="M0 12C25 14 35 30 60 32S95 46 120 50 165 62 190 66 225 70 240 72"
          stroke="currentColor"
          className="text-primary"
          strokeWidth="2.5"
          strokeLinecap="round"
        />
        <path
          d="M0 68H240"
          stroke="currentColor"
          className="text-flame"
          strokeWidth="1.5"
          strokeDasharray="5 4"
        />
      </svg>

      <div className="mt-[18px] grid grid-cols-3 gap-2 sm:gap-2.5">
        {[
          { label: "Net change", value: "▼ 12.4 lbs" },
          { label: "Water", value: "96 oz" },
          { label: "Sessions left", value: "11" },
        ].map((stat) => (
          <div key={stat.label} className="rounded-xl bg-background p-2.5 sm:p-3">
            <div className="text-[11px] text-muted-foreground sm:text-[12px]">
              {stat.label}
            </div>
            <b className="text-[15px] sm:text-base">{stat.value}</b>
          </div>
        ))}
      </div>

      <p className="mt-3.5 text-[12px] text-muted-foreground">
        Sample data for illustration
      </p>
    </div>
  );
}

export function Hero() {
  return (
    <section
      id="top"
      className="bg-[radial-gradient(1200px_500px_at_85%_-10%,var(--brand-soft),transparent)] pt-16 pb-16 sm:pt-20 sm:pb-24"
    >
      <Container className={`grid items-center gap-14 ${GRID_HERO}`}>
        <div>
          <span className="inline-block rounded-full border border-border bg-surface px-3.5 py-2 text-[14px] tracking-[0.06em] text-primary">
            Endorsed by Chiro Nutraceutical™
          </span>
          <h1 className="mt-6 font-serif text-[clamp(34px,5.4vw,68px)] leading-[1.1] font-medium tracking-[-0.01em]">
            Simplify your practice with ChiroThin Tracker
          </h1>
          <p className="mt-4 max-w-[620px] text-[19px] text-muted-foreground">
            Built by ChiroThin® doctors for ChiroThin® doctors. The only
            authorized patient tracking software, fully endorsed by Chiro
            Nutraceutical™, the makers of ChiroThin®.
          </p>

          <div className={`mt-9 ${ctaRow}`}>
            <Button asChild size="lg" className={pillCta}>
              <a href="#demo">Request a demo</a>
            </Button>
            <Button
              asChild
              size="lg"
              className={`${pillCta} bg-flame text-white hover:bg-[#8c3517]`}
            >
              <a href="#pricing">Subscribe now</a>
            </Button>
          </div>

          <p className="mt-5 text-[14px] text-muted-foreground">
            Already a patient?{" "}
            <Link
              href="/login"
              className="font-bold text-primary underline underline-offset-2"
            >
              Log in to your portal
            </Link>
            . New patients: use the intake link from your clinic.
          </p>
        </div>

        <SamplePatientCard />
      </Container>
    </section>
  );
}