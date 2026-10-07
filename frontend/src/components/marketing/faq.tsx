import { Minus, Plus } from "lucide-react";
import {
  CLINIC_SUBSCRIPTION_DESCRIPTION,
  SUBSCRIPTION_AVAILABILITY_NOTE,
  SUBSCRIPTION_SUPPORT_EMAIL,
} from "@/lib/subscriptions/copy";
import {
  Container,
  Eyebrow,
  Section,
  SectionTitle,
} from "@/components/marketing/primitives";

const QUESTIONS = [
  {
    question: "Does the portal include video conferencing?",
    answer:
      SUBSCRIPTION_AVAILABILITY_NOTE,
  },
  {
    question: "Is there an activation or build fee?",
    answer: "No. There is no activation or build fee on any plan.",
  },
  {
    question: "Am I locked into a long-term contract?",
    answer:
      "Monthly plans have no long-term contract. Veteran (Annual) is billed once a year. Cancellation stops future renewals; access continues through the already paid period.",
  },
  {
    question: "Can I try it before I subscribe?",
    answer:
      `You can request a free trial on Veteran or All Pro by emailing ${SUBSCRIPTION_SUPPORT_EMAIL}. Newbie and Rookie have no free trial. Checkout starts a paid subscription, rather than automatically starting a trial.`,
  },
  {
    question: "Can additional doctors use my clinic?",
    answer: CLINIC_SUBSCRIPTION_DESCRIPTION,
  },
  {
    question: "Which plans include Red Light/Laser tracking?",
    answer:
      `All Pro includes Red Light/Laser tracking. Veteran (Annual) offers it as a bonus by request: email ${SUBSCRIPTION_SUPPORT_EMAIL}. The annual bonus is not enabled automatically at checkout.`,
  },
  {
    question: "Can I sell products through it?",
    answer:
      "Newbie excludes e-commerce. Rookie, Veteran, All Pro and Veteran (Annual) include the store integration entitlement. The patient storefront is not yet available in this portal.",
  },
];

export function Faq() {
  return (
    <Section id="faq">
      <Container className="max-w-[860px]">
        <Eyebrow>FAQ</Eyebrow>
        <SectionTitle>Questions doctors ask</SectionTitle>

        <div className="mt-9 border-t border-border">
          {QUESTIONS.map(({ question, answer }, index) => (
            <details
              key={question}
              className="group border-b border-border py-5"
              open={index === 0}
            >
              <summary className="flex cursor-pointer list-none items-center justify-between gap-4 text-[18px] font-semibold [&::-webkit-details-marker]:hidden">
                {question}
                <Plus
                  aria-hidden
                  className="size-6 shrink-0 text-primary group-open:hidden"
                />
                <Minus
                  aria-hidden
                  className="hidden size-6 shrink-0 text-primary group-open:block"
                />
              </summary>
              <p className="mt-3 max-w-[720px] text-muted-foreground">{answer}</p>
            </details>
          ))}
        </div>
      </Container>
    </Section>
  );
}