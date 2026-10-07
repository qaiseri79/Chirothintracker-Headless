import type { ReactNode } from "react";
import { Container, Eyebrow } from "@/components/marketing/primitives";

/**
 * Shell for the Privacy Policy and Terms of Service pages.
 *
 * Both are long-form prose with no interactivity, so they share one layout
 * rather than each re-deriving the measure, heading scale and link treatment.
 */
export function LegalDocument({
  eyebrow,
  title,
  intro,
  sections,
  children,
}: {
  eyebrow: string;
  title: string;
  intro?: ReactNode;
  sections: { heading: string; body: ReactNode }[];
  /** Rendered after the last section — signature blocks, revision dates. */
  children?: ReactNode;
}) {
  return (
    <section className="py-14 sm:py-20">
      <Container className="max-w-[760px]">
        <Eyebrow>{eyebrow}</Eyebrow>
        <h1 className="mt-3 font-serif text-[clamp(34px,4.5vw,52px)] leading-[1.1] font-medium tracking-[-0.01em]">
          {title}
        </h1>

        {intro ? (
          <p className="mt-6 text-[17px] leading-[1.7] text-muted-foreground">
            {intro}
          </p>
        ) : null}

        <div className="mt-12 grid gap-10">
          {sections.map((section) => (
            <section key={section.heading}>
              <h2 className="font-serif text-[26px] leading-[1.2] font-medium tracking-[-0.01em]">
                {section.heading}
              </h2>
              <div className="mt-4 grid gap-4 text-[16px] leading-[1.7] text-muted-foreground">
                {section.body}
              </div>
            </section>
          ))}
        </div>

        {children ? <div className="mt-12">{children}</div> : null}
      </Container>
    </section>
  );
}

/** An in-prose mailto. */
export function MailLink({ href, children }: { href: string; children: ReactNode }) {
  return (
    <a
      href={href}
      className="font-medium text-primary underline underline-offset-2 hover:text-brand-dark"
    >
      {children}
    </a>
  );
}

/** A postal contact block. */
export function Address({ name, lines, phone }: { name: string; lines: string[]; phone: string }) {
  return (
    <address className="text-[16px] leading-[1.7] not-italic">
      <span className="block font-semibold text-foreground">{name}</span>
      {lines.map((line) => (
        <span key={line} className="block">
          {line}
        </span>
      ))}
      <span className="block">{phone}</span>
    </address>
  );
}