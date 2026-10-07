import { Container } from "@/components/marketing/primitives";

const CLAIMS = [
  "Trusted service since 2017",
  "HIPAA-compliant and secure",
  "The only authorized ChiroThin® tracking software",
  "Available 24/7, on any device",
];

/** The four-up reassurance band that separates the hero from the features. */
export function TrustStrip() {
  return (
    <section className="border-y border-border bg-surface">
      <Container className="flex flex-wrap justify-between gap-x-10 gap-y-5 py-7 text-[15px] font-semibold">
        {CLAIMS.map((claim) => (
          <span key={claim}>{claim}</span>
        ))}
      </Container>
    </section>
  );
}