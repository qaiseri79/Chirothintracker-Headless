import type { Metadata } from "next";
import {
  Address,
  LegalDocument,
  MailLink,
} from "@/components/marketing/legal-document";

export const metadata: Metadata = {
  title: "Privacy Policy — ChiroThin Tracker",
  description:
    "How ChiroThin Tracker collects, shares, retains and protects the personal and health information of doctors and patients in the ChiroThin® wellness programs.",
  alternates: { canonical: "/privacy-policy" },
};

const SUPPORT = "mailto:support@chirothintracker.com";

export default function PrivacyPolicyPage() {
  return (
    <LegalDocument
      eyebrow="Legal"
      title="Privacy Policy"
      sections={[
        {
          heading: "About Our Privacy Policy",
          body: (
            <p>
              Your privacy is very important to us! To better protect your
              privacy, we provide this policy that explains our online
              information practices and the choices you can make about the way
              your information is collected and used. You can find this policy
              from any page of our website by clicking “Privacy Policy” in the
              footer.
            </p>
          ),
        },
        {
          heading: "What personal data we collect and why we collect it",
          body: (
            <>
              <p>
                ChiroThinTracker.com collects a variety of personal information
                from doctors and patients for the purposes of providing care
                under the ChiroThin® wellness programs.
              </p>
              <p>
                Required personal information collected includes, but is not
                limited to, name, e-mail address, and personal account
                information. As a patient in the ChiroThin® wellness programs,
                we may request additional personal health information, such as
                weight, body measurements, and diet and food intake information.
                Submission of personal health information is optional but greatly
                impacts the effectiveness of the program.
              </p>
              <p>
                Other technical information may be collected, including cookie
                information, public IP address, and site usage data. This
                information may be used for internal market research, system
                security and monitoring, and analytics for program improvement
                efforts.
              </p>
            </>
          ),
        },
        {
          heading: "Embedded content from other websites",
          body: (
            <p>
              Some content on this site may include embedded content, such as
              images, videos, and downloadable files. Accessing, viewing, or
              downloading embedded content from other websites is the equivalent
              of visiting the other websites. Embedded content may collect data
              about you, use cookies, implement additional third-party tracking.
              This privacy policy does not extend to content hosted by other
              websites.
            </p>
          ),
        },
        {
          heading: "Who we share your data with",
          body: (
            <>
              <p>
                When making online purchases, we may share payment information
                with our payment processors and merchant service providers. To
                find out which providers we use, please contact us at{" "}
                <MailLink href={SUPPORT}>support@chirothintracker.com</MailLink>.
                Payment information includes name, mailing address, shipping
                address, and credit card information.
              </p>
              <p>
                Personal health information and personal account information is
                shared with your doctor for the purposes of participation in the
                ChiroThin® wellness programs.
              </p>
              <p>
                Aggregated and de-identified health data may be provided to
                ChiroThin® program managers for the purposes of monitoring,
                analyzing, and improving the effectiveness of the program.
              </p>
            </>
          ),
        },
        {
          heading: "How long we retain your data",
          body: (
            <p>
              Personal account information, patient messaging, and personal
              health information is kept available to your doctor for up to six
              years or until a purge request has been made.
            </p>
          ),
        },
        {
          heading: "What rights you have over your data",
          body: (
            <p>
              If you have registered for the site and provided personal
              information, you may request copies of the information at any time.
              Requests can be made in person with your doctor, by e-mail at{" "}
              <MailLink href={SUPPORT}>support@chirothintracker.com</MailLink>, by
              a ticket request at{" "}
              <MailLink href="https://support.chirothintracker.com">
                support.chirothintracker.com
              </MailLink>
              , or by postal mail using the addresses listed below. You may also
              request that your information be purged from the site at any time.
              While the data may be removed from the site, your doctor may retain
              personal information as a part of your health record. In addition,
              any data that we are obliged to keep for administrative, legal, or
              security purposes will not be removed.
            </p>
          ),
        },
        {
          heading: "How we protect your data",
          body: (
            <>
              <p>
                All data, including personal information and health information
                is encrypted in transit using the latest TLS (transport layer
                security) capabilities. All data is encrypted, replicated and
                archived in geographically separate data centers to help protect
                against loss or criminal activity. Every effort is made to allow
                only you and your doctor to access your patient information.
              </p>
              <p>
                If you believe that your information has been compromised in any
                way, please contact us immediately at{" "}
                <MailLink href={SUPPORT}>support@chirothintracker.com</MailLink>{" "}
                or using the contact information below.
              </p>
            </>
          ),
        },
        {
          heading: "Who we are",
          body: (
            <>
              <p>
                ChiroThinTracker.com is a joint effort between Gardner InfoTech
                Managed Services, LLC, Brilliant Software Inc., and
                ChiroNutraceuticals.
              </p>
              <div className="mt-2 grid gap-6 sm:grid-cols-3">
                <Address
                  name="ChiroNutraceuticals"
                  lines={["17824 Edison Ave", "Chesterfield, MO 63005"]}
                  phone="(877) 377-7636"
                />
                <Address
                  name="Brilliant Software, Inc."
                  lines={["5574 Sunflower Lane S", "Fargo, ND 58104"]}
                  phone="(701) 866-8256"
                />
                <Address
                  name="Gardner InfoTech Managed Services, LLC"
                  lines={["401 6th Ave SW", "Surrey, ND 58785"]}
                  phone="(701) 712-8400"
                />
              </div>
            </>
          ),
        },
      ]}
    />
  );
}