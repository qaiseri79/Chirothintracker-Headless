/**
 * Everything the Support Center needs that is content, not logic.
 *
 * Kept in one place so the page component holds no URLs and no schedule copy, and so
 * swapping the placeholder form/PDF for the real ones is a one-file edit rather than
 * a hunt through markup.
 *
 * ## Colour tokens
 *
 * The design ("New Design/ChiroThin — Support Center.html") carries its own token
 * names that do **not** all exist here, and two of them collide with tokens that
 * mean something else. Mapped in the table below:
 *
 * | design      | this app          | why not the same name                        |
 * |-------------|-------------------|----------------------------------------------|
 * | `accent`    | `flame`           | `--accent` here is `#f3f4f1`, a near-white grey. Using it for the design's orange would put white text on white. |
 * | `accent-soft` | `flame-soft`    | same collision                                |
 * | `primary-dark` | `brand-dark`   | the design's alias for the darker brand green |
 * | `ink`       | `foreground`      | `#101827`, the design's near-black            |
 *
 * The mapping is written out as a table rather than exported as values because
 * Tailwind finds class names by scanning source text: a class assembled from a
 * constant (`` `from-${COLOUR.primaryDark}` ``) is invisible to the scanner and
 * silently ships with no CSS at all. The components therefore spell the classes out
 * in full, and this table is the record of which design name became which.
 */

/**
 * GoHighLevel embed URL for the support-ticket form.
 *
 * Empty until the real form is provisioned — the page renders an explicit
 * "not configured" panel in that state rather than an iframe pointing nowhere, which
 * would look like a broken form to a chiropractor trying to get help.
 *
 * This is the iframe form of the GoHighLevel embed code (Marketing → Sites →
 * Widgets → Embed), which is a single `https://` URL that renders the form in an
 * iframe. Set it in `.env.local`:
 *
 *   NEXT_PUBLIC_SUPPORT_FORM_URL=https://...
 *
 * `NEXT_PUBLIC_` is required: the form is rendered in a client component, so the
 * value is inlined into the browser bundle rather than read on the server.
 */
export const SUPPORT_FORM_URL = process.env.NEXT_PUBLIC_SUPPORT_FORM_URL ?? "";

/**
 * The user guide PDF.
 *
 * Served from `public/files/`, so it is a plain static file the browser fetches
 * directly. Currently the sample document — the real guide has not been written.
 */
export const USER_GUIDE_PDF_URL = "/files/chirothintracker-user-guide.pdf";

/** Filename offered by the Download button. */
export const USER_GUIDE_FILENAME = "ChiroThinTracker-User-Guide.pdf";

/**
 * Pages in the current PDF.
 *
 * Only used for the "/ 6" readout and to keep the page box in range. The native
 * browser viewer owns actual pagination, so this is a display detail, not the
 * document's length — see {@link USER_GUIDE_TOPICS} for the related caveat.
 */
export const USER_GUIDE_PAGE_COUNT = 6;

/**
 * "Jump to" entries, from the design's `GUIDE`.
 *
 * The page numbers are **placeholders**. The design listed them as `REAL_PAGES`,
 * i.e. someone was meant to fill in the page each topic starts on in the finished
 * guide. The sample PDF has no text layer (`pdftotext` returns nothing on all six
 * pages), so the real mapping cannot be read out of it, and these are numbered
 * straight through instead. Correct them when the real guide lands.
 */
export const USER_GUIDE_TOPICS = [
  "Getting started",
  "Enrolling patients",
  "Daily logs",
  "Sessions & treatments",
  "Messages & notes",
  "My clinic page",
] as const;

/**
 * The weekly live session.
 *
 * Copied from the design, including the join link. `timeZoneLabels` are rendered in
 * the design's fixed order — it does not reorder them for the viewer's timezone, so
 * neither do we; that would make the four boxes jump around between users for no
 * gain.
 */
export const LIVE_QA = {
  cadence: "Weekly · Every Thursday",
  title: "Live Q&A with the ChiroThin team",
  blurb:
    "Bring your questions about the program, the portal, or your patients. Jump in live — no sign-up needed.",
  joinLabel: "Join Zoom Meeting",
  joinUrl:
    "https://us02web.zoom.us/j/88130839111?pwd=a02yJaFeTDD6zQg3I7wmoSXitEaO11.1",
  timeZoneLabels: [
    { time: "7 am", zone: "PDT" },
    { time: "8 am", zone: "MDT" },
    { time: "9 am", zone: "CDT" },
    { time: "10 am", zone: "EDT" },
  ],
  /** 4 is Thursday, matching the design's `d.getDay() === 4` check. */
  weekday: 4,
} as const;