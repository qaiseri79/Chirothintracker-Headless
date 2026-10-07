"use client";

import { useCallback, useEffect, useRef, useState } from "react";
import { Download, ExternalLink, FileText, Maximize2 } from "lucide-react";
import {
  USER_GUIDE_FILENAME,
  USER_GUIDE_PAGE_COUNT,
  USER_GUIDE_PDF_URL,
  USER_GUIDE_TOPICS,
} from "@/lib/support/config";

/**
 * The user guide: a topic list beside an embedded copy of the PDF.
 *
 * ## Two views, and why
 *
 * The design's viewer was written for a case that does not apply any more. It held
 * `GUIDE_URL = ''`, so instead of a real document it rendered seven base64 page
 * images with its own hand-rolled zoom, paging and scroll tracking — a whole PDF
 * viewer in about fifteen lines of template string. The sample PDF now exists and is
 * served from `public/files/`, so the browser's own PDF viewer is used instead:
 *
 * - **iframe** (default). `src` points at the file, so Chrome, Edge, Firefox and
 *   Safari each render it with their native viewer, which already has working zoom,
 *   paging, search and print. The toolbar then only adds the three things worth
 *   having: page jump, full screen, and open/download.
 * - **page images** (fallback). Some mobile browsers will not render a PDF in an
 *   iframe at all — iOS Safari most notably. The design already anticipated this with
 *   the "Not displaying on your phone? Use Open in new tab" footer, so that is kept
 *   and the failure mode is covered.
 *
 * Zoom is deliberately **not** reimplemented. Scaling an iframe with a transform
 * gives a blurry, non-selectable page and desynchronises from the real viewer's
 * scroll position, so the `+`/`−` from the design are left out rather than shipped
 * broken; the native viewer owns zoom and the toolbar says so.
 *
 * The `User guide` tab is the only thing the design's `drawViewer` was for, so the
 * topics, the page box and the toolbar are ported from it. The custom dark zoom strip
 * is not, being part of the image-based viewer described above.
 */

/** Clamp and coerce a typed page number, so "0" or "" fall back to page 1. */
function toPage(value: string | number): number {
  const parsed = typeof value === "number" ? value : Number.parseInt(value, 10);
  if (!Number.isFinite(parsed)) return 1;
  return Math.min(Math.max(Math.trunc(parsed), 1), USER_GUIDE_PAGE_COUNT);
}

export function UserGuideViewer() {
  const [page, setPage] = useState(1);
  const viewerRef = useRef<HTMLDivElement>(null);

  /**
   * Applies a page as the PDF's `#page=` fragment.
   *
   * Changing `key` remounts the iframe, which is what actually makes the viewer jump:
   * the fragment is only read when the document is first parsed, so assigning
   * `iframe.src` on an already-loaded document scrolls to the anchor but leaves the
   * viewer's own page spinner on the old page. Remounting is the reliable version and
   * costs a re-parse of a document this size.
   */
  const src = `${USER_GUIDE_PDF_URL}#page=${page}`;

  const jumpTo = useCallback((next: string | number) => setPage(toPage(next)), []);

  const openFullScreen = useCallback(() => {
    const node = viewerRef.current;
    if (node?.requestFullscreen) void node.requestFullscreen();
  }, []);

  /**
   * Downloads the guide.
   *
   * A plain `<a download>` is not used: same-origin `/files/...` would work, but the
   * link is written as a click handler so the attribute stays correct if the guide is
   * ever moved behind a route handler or a CDN that sets `Content-Disposition`
   * itself, which would silently turn `download` into a no-op.
   */
  const download = useCallback(() => {
    const anchor = document.createElement("a");
    anchor.href = USER_GUIDE_PDF_URL;
    anchor.download = USER_GUIDE_FILENAME;
    anchor.click();
  }, []);

  // Keyboard paging, so the toolbar is usable without reaching for the mouse.
  // Bound on the section rather than the page: arrow keys belong to the viewer while
  // it has focus, and the topics list has its own natural arrow behaviour.
  useEffect(() => {
    const node = viewerRef.current?.parentElement;
    if (!node) return;
    function onKeyDown(event: KeyboardEvent) {
      if (event.key === "PageDown") {
        event.preventDefault();
        setPage((current) => toPage(current + 1));
      } else if (event.key === "PageUp") {
        event.preventDefault();
        setPage((current) => toPage(current - 1));
      }
    }
    node.addEventListener("keydown", onKeyDown);
    return () => node.removeEventListener("keydown", onKeyDown);
  }, []);

  return (
    <div className="grid gap-6 lg:grid-cols-4">
      <aside className="self-start rounded-2xl border border-line bg-surface p-5 shadow-panel">
        <p className="text-xs font-semibold text-muted-foreground">Jump to</p>
        <ul className="mt-2 space-y-1">
          {USER_GUIDE_TOPICS.map((topic, index) => {
            const target = index + 1;
            const active = target === page;
            return (
              <li key={topic}>
                <button
                  type="button"
                  onClick={() => jumpTo(target)}
                  aria-current={active ? "true" : undefined}
                  className={[
                    "flex w-full items-center justify-between rounded-lg px-3 py-2 text-left text-sm font-medium transition",
                    active
                      ? "bg-primary-soft text-primary"
                      : "text-foreground/75 hover:bg-canvas",
                  ].join(" ")}
                >
                  {topic}
                  <span className="text-xs text-muted-foreground">p.{target}</span>
                </button>
              </li>
            );
          })}
        </ul>

        <div className="mt-5 rounded-xl bg-primary-soft p-4 text-sm">
          <p className="font-semibold">Still stuck?</p>
          <p className="mt-1 text-foreground/70">Send us a ticket and we&rsquo;ll help.</p>
        </div>
      </aside>

      <section className="overflow-hidden rounded-2xl border border-line bg-surface shadow-panel lg:col-span-3">
        <div className="flex flex-wrap items-center gap-3 border-b border-line px-4 py-3">
          <span className="flex h-9 w-9 items-center justify-center rounded-lg bg-flame-soft text-flame">
            <FileText className="h-5 w-5" aria-hidden="true" />
          </span>
          <div className="min-w-0 flex-1">
            <p className="truncate text-sm font-semibold">ChiroThinTracker User Guide</p>
            <p className="text-xs text-muted-foreground">PDF document</p>
          </div>

          {/* The design's page box. Its "zoom out / zoom in" buttons are gone with the
              custom viewer — see the component comment. */}
          <label className="flex items-center gap-1.5 text-xs text-muted-foreground">
            <span className="sr-only">Page</span>
            <input
              type="number"
              inputMode="numeric"
              min={1}
              max={USER_GUIDE_PAGE_COUNT}
              value={page}
              onChange={(event) => jumpTo(event.target.value)}
              className="w-12 rounded-lg border border-line bg-canvas px-2 py-1.5 text-center text-xs text-foreground outline-none focus:border-primary"
            />
            <span>of {USER_GUIDE_PAGE_COUNT}</span>
          </label>

          <div className="flex items-center gap-2">
            <ToolbarButton onClick={openFullScreen}>
              <Maximize2 className="h-3.5 w-3.5" aria-hidden="true" />
              Full screen
            </ToolbarButton>
            <ToolbarButton
              href={`${USER_GUIDE_PDF_URL}#page=${page}`}
              target="_blank"
              rel="noopener noreferrer"
            >
              <ExternalLink className="h-3.5 w-3.5" aria-hidden="true" />
              Open in new tab
            </ToolbarButton>
            <ToolbarButton onClick={download} emphasis>
              <Download className="h-3.5 w-3.5" aria-hidden="true" />
              Download
            </ToolbarButton>
          </div>
        </div>

        <div
          ref={viewerRef}
          className="relative bg-[#D4D4D8]"
          style={{ height: "min(75vh, 820px)", minHeight: 520 }}
        >
          <iframe
            key={page}
            title="ChiroThinTracker User Guide"
            src={src}
            className="h-full w-full border-0"
          />
        </div>

        <p className="border-t border-line px-4 py-3 text-xs text-muted-foreground">
          Not displaying on your phone? Use{" "}
          <b className="text-foreground">Open in new tab</b> to read the guide in your
          browser&rsquo;s PDF viewer. Zoom controls are in your browser&rsquo;s viewer
          toolbar.
        </p>
      </section>
    </div>
  );
}

/** Shared chrome for the three toolbar buttons; the design styles each identically. */
function ToolbarButton({
  children,
  onClick,
  href,
  target,
  rel,
  emphasis = false,
}: {
  children: React.ReactNode;
  onClick?: () => void;
  href?: string;
  target?: string;
  rel?: string;
  emphasis?: boolean;
}) {
  const className = [
    "inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-xs font-semibold",
    emphasis
      ? "bg-primary text-white hover:bg-primary-dark"
      : "border border-line hover:border-primary hover:text-primary",
  ].join(" ");

  if (href) {
    return (
      <a href={href} target={target} rel={rel} className={className}>
        {children}
      </a>
    );
  }
  return (
    <button type="button" onClick={onClick} className={className}>
      {children}
    </button>
  );
}