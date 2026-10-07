import type { Metadata } from "next";
import {
  Address,
  LegalDocument,
  MailLink,
} from "@/components/marketing/legal-document";

export const metadata: Metadata = {
  title: "Terms of Service — ChiroThin Tracker",
  description:
    "The terms governing access to and use of the ChiroThin Tracker websites and their subdomains, operated by Gardner InfoTech Managed Services, LLC.",
  alternates: { canonical: "/terms-of-service" },
};

const SUPPORT = "mailto:support@chirothintracker.com";

export default function TermsOfServicePage() {
  return (
    <LegalDocument
      eyebrow="Legal"
      title="Terms of Service"
      sections={[
        {
          heading: "Acceptance of these terms",
          body: (
            <p>
              Your access to and use of the ChiroThinTracker.com websites and its
              subdomains, including, but not limited to
              home.chirothintracker.com, support.chirothintracker.com,
              doctors.chirothintracker.com and any subscriber personalized
              subdomains, (“the service”) operated by Gardner InfoTech Managed
              Services, LLC (“us”, “we”, “our”), is subject to your acceptance
              and compliance with the terms provided below. These terms apply to
              all website visitors with privileged, subscriber, or guest access
              or use of the service. By accessing or using the service, you agree
              to be bound by these terms. If you disagree with any part of the
              terms, then you may not access the service.
            </p>
          ),
        },
        {
          heading: "Accounts",
          body: (
            <p>
              When you create an account with us, you must provide us with
              information that is up to date and complete. Failure to provide
              accurate information constitutes a breach of these terms, which
              may result in immediate termination of your account(s). You are
              responsible for safeguarding the password that you use to access
              the service and for any activities or actions using your password.
              You agree not to disclose your password to anyone. You must notify
              us immediately upon becoming aware of any breach of security or
              unauthorized use of your account or the data associated with it.
            </p>
          ),
        },
        {
          heading: "Third Party Links and Content",
          body: (
            <p>
              The service may contain links to third-party websites or content
              not owned or controlled by us. We have no control over, and assume
              no responsibility for, the content, privacy policies, or practices
              of any third-party websites or services. You acknowledge and agree
              that we will not be directly or indirectly liable for any damage
              or loss in connection with the use of any content, goods or
              services available on or through any websites or services. We
              strongly advise you to read and understand any terms and privacy
              policies associated with any third-party websites or services that
              you visit.
            </p>
          ),
        },
        {
          heading: "Changes",
          body: (
            <p>
              We reserve the right, at our sole discretion, to modify or
              replace these terms at any time. When possible, we will provide
              notice 30 days prior to any material changes to these terms. The
              latest terms revision will be available as a link at the bottom of
              every page of the websites, where possible and applicable. By
              continuing to access or use the service after any changes have been
              made, you agree to be bound by the revised terms.
            </p>
          ),
        },
        {
          heading: "Legal Rights",
          body: (
            <p>
              These terms shall be governed and construed in accordance with the
              laws of North Dakota, United States, without regard to its conflict
              of law provisions. Our failure to enforce any right or provision of
              these terms will not be considered a waiver of those rights. If
              any provision of these terms is held to be invalid or unenforceable
              by a court, the remaining provisions will remain in effect. If you
              have any questions or concerns regarding these terms, please
              contact us at{" "}
              <MailLink href={SUPPORT}>support@chirothintracker.com</MailLink>.
            </p>
          ),
        },
        {
          heading: "Revision",
          body: (
            <p>These terms have been revised as of July 5, 2018.</p>
          ),
        },
      ]}
    >
      <div className="grid gap-6 border-t border-border pt-8 sm:grid-cols-2">
        <Address
          name="Gardner InfoTech Managed Services, LLC"
          lines={["401 6th Ave SW", "Surrey, ND 58785"]}
          phone="(701) 712-8400"
        />
        <p className="text-[16px] leading-[1.7] text-muted-foreground">
          Questions about these terms?{" "}
          <MailLink href="mailto:support@gardnerinfotech.com">
            support@gardnerinfotech.com
          </MailLink>
        </p>
      </div>
    </LegalDocument>
  );
}