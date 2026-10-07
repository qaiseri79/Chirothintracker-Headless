import type { Metadata } from "next";
import { Inter, Newsreader } from "next/font/google";
import { AuthProvider } from "@/lib/auth";
import { MessageUnreadProvider } from "@/components/providers/message-unread-provider";
import { IntakeCountProvider } from "@/components/providers/intake-count-provider";
import { ProgressProvider } from "@/components/providers/progress-provider";
import "./globals.css";

const inter = Inter({
  variable: "--font-inter",
  subsets: ["latin"],
});

const newsreader = Newsreader({
  variable: "--font-newsreader",
  subsets: ["latin"],
});

export const metadata: Metadata = {
  /*
   * Absolute URLs (canonical, Open Graph, anything a crawler resolves) are
   * built from this. Point NEXT_PUBLIC_SITE_URL at the deployment host; the
   * fallback keeps `next build` and local dev working without it set.
   */
  metadataBase: new URL(
    process.env.NEXT_PUBLIC_SITE_URL ?? "http://localhost:3000",
  ),
  title: "ChiroThin — Patient Intake",
  description: "Complete your ChiroThin patient intake form.",
  robots: { index: false, follow: false },
};

export default function RootLayout({ children }: LayoutProps<"/">) {
  return (
    <html
      lang="en"
      /* smooth-scrolls the landing page's #pricing / #faq / #demo anchors */
      className={`${inter.variable} ${newsreader.variable} h-full scroll-smooth`}
    >
      <body className="min-h-full">
        <AuthProvider>
          <MessageUnreadProvider>
            <IntakeCountProvider>
              <ProgressProvider>{children}</ProgressProvider>
            </IntakeCountProvider>
          </MessageUnreadProvider>
        </AuthProvider>
      </body>
    </html>
  );
}
