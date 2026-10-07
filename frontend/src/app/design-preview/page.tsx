import type { Metadata } from "next";
import Link from "next/link";
import { PreviewTrail } from "@/components/design-preview/preview-header";
import {
  PREVIEW_PAGES,
  UNDESIGNED_PREVIEW_PAGES,
} from "@/lib/design-preview/preview-pages";

export const metadata: Metadata = {
  title: "Design preview — ChiroThin commerce",
};

/**
 * Index of the commerce mock-ups.
 *
 * Exists so there is one place to find them, and so the ones that are not built
 * yet are visibly missing rather than silently absent.
 */
export default function DesignPreviewIndex() {
  return (
    <div className="mx-auto w-full max-w-[1120px] px-5 py-14">
      <h1 className="font-serif text-[clamp(30px,4vw,44px)] leading-[1.1] font-semibold">
        Commerce mock-ups
      </h1>
      <p className="mx-auto mt-2.5 max-w-[620px] text-muted-foreground">
        Approved commerce designs and their implementation status. The doctor
        subscription design now opens the real subscription flow.
      </p>

      <div className="mt-8">
        <PreviewTrail items={PREVIEW_PAGES} />
      </div>

      <ul className="mt-8 grid grid-cols-1 gap-4 md:grid-cols-2">
        {PREVIEW_PAGES.map((page) => (
          <li key={page.href}>
            <Link
              href={page.href}
              className="block rounded-[20px] border border-border bg-surface p-6 transition-colors hover:border-brand hover:bg-brand-soft/40"
            >
              <span className="block font-serif text-[22px] leading-tight font-semibold">
                {page.label}
              </span>
              <span className="mt-2 block text-[14px] text-muted-foreground">
                From <code>{page.source}</code>
              </span>
              <span className="mt-3 block text-[15px] text-muted-foreground">
                {page.note}
              </span>
              <span className="mt-3 inline-block text-[15px] font-bold text-brand">
                Open →
              </span>
            </Link>
          </li>
        ))}
      </ul>

      {/*
       * Listed as absent rather than quietly dropped, so the gap is visible to
       * whoever is scheduling the work.
       */}
      <section className="mt-10">
        <h2 className="font-serif text-[22px] leading-tight font-semibold">
          Not designed yet
        </h2>
        <ul className="mt-4 grid grid-cols-1 gap-4 md:grid-cols-2">
          {UNDESIGNED_PREVIEW_PAGES.map((page) => (
            <li
              key={page.label}
              className="rounded-[20px] border border-dashed border-border bg-surface/60 p-6"
            >
              <span className="block font-serif text-[22px] leading-tight font-semibold">
                {page.label}
              </span>
              <span className="mt-2 block text-[15px] text-muted-foreground">
                {page.note}
              </span>
            </li>
          ))}
        </ul>
      </section>
    </div>
  );
}