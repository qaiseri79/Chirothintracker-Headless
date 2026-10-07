import Link from "next/link";
import { Container } from "@/components/marketing/primitives";

const FOOTER_BG = "#101827";
const FOOTER_TEXT = "#cbd5e1";
const FOOTER_RULE = "#2a3150";

const FOOTER_LINKS = [
  { label: "Privacy Policy", href: "/privacy-policy" },
  { label: "Terms of Service", href: "/terms-of-service" },
];

export function MarketingFooter() {
  return (
    <footer
      id="login"
      style={{ background: FOOTER_BG, color: FOOTER_TEXT }}
      className="py-10"
    >
      <Container className="flex flex-wrap items-center justify-between gap-4 text-[14px]">
        <span className="font-serif text-[20px] text-white">ChiroThinTracker</span>
        <span className="flex gap-6">
          {FOOTER_LINKS.map((link) => (
            <Link
              key={link.href}
              href={link.href}
              className="rounded outline-none hover:text-white focus-visible:ring-3 focus-visible:ring-ring/50"
            >
              {link.label}
            </Link>
          ))}
        </span>
        <span>
          Support:{" "}
          <a
            href="mailto:support@chirothintracker.com"
            className="underline underline-offset-2 outline-none focus-visible:ring-3 focus-visible:ring-ring/50"
          >
            support@chirothintracker.com
          </a>
        </span>
      </Container>
      <Container
        className="mt-5 border-t pt-5 text-[13px] text-[#94a3b8]"
        style={{ borderColor: FOOTER_RULE }}
      >
        ChiroThinTracker.com is a joint effort between Gardner InfoTech Managed
        Services, LLC, Brilliant Software Inc., and ChiroNutraceuticals.
      </Container>
    </footer>
  );
}