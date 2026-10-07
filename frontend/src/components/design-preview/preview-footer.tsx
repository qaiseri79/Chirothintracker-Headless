import Link from "next/link";

/**
 * Footer for the preview shell.
 *
 * Reproduces the mock-up's footer (line 152): the ink-coloured band, the
 * wordmark on the left, legal links and a support address on the right. The two
 * legal links point at the real pages on this site rather than the mock-up's `#`.
 *
 * The band is written as a literal because `globals.css` documents an `ink`
 * token in a comment but never declares `--color-ink`, so `bg-ink` compiles to
 * nothing and leaves the band transparent.
 */
export function DesignPreviewFooter() {
  return (
    <footer className="bg-[#101827] text-[#cbd5e1]">
      <div className="mx-auto flex w-full max-w-[1120px] flex-wrap items-center justify-between gap-4 py-9 text-[14px] sm:gap-7">
        <span className="font-serif text-[20px] text-white">ChiroThinTracker</span>

        <span className="flex flex-wrap gap-6">
          <Link href="/privacy-policy" className="hover:text-white">
            Privacy Policy
          </Link>
          <Link href="/terms-of-service" className="hover:text-white">
            Terms of Service
          </Link>
        </span>

        <span>
          Support:{" "}
          <a
            href="mailto:support@chirothintracker.com"
            className="underline hover:text-white"
          >
            support@chirothintracker.com
          </a>
        </span>
      </div>
    </footer>
  );
}