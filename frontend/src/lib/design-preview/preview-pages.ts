/**
 * Registry of the commerce mock-ups.
 *
 * Plain data, deliberately not a component file: this is imported by both server
 * pages and client components, and a module marked `"use client"` has its exports
 * replaced by client-reference proxies when a server component imports it — so an
 * array declared in one cannot be `.map`ped on the server.
 */

export interface PreviewPage {
  href: string;
  label: string;
  /** The design file this mock-up was transcribed from. */
  source: string;
  /** One line on what is mocked and what is not. */
  note: string;
}

export const PREVIEW_PAGES: readonly PreviewPage[] = [
  {
    href: "/subscribe",
    label: "Doctor subscription",
    source: "ChiroThin Tracker — Subscribe flow.html",
    note: "The approved subscription design is now connected to real Drupal plans, registration, login and Authorize.Net checkout.",
  },
];

/**
 * The patient store has no approved design, so it has no mock-up. Recorded here
 * so the gap is stated in one place instead of being rediscovered: there is no
 * product listing, product detail, cart or checkout anywhere in the design set,
 * the patient sidebars carry a "Shop" nav label with nothing behind it, and no
 * file contains product pricing.
 */
export const UNDESIGNED_PREVIEW_PAGES: readonly { label: string; note: string }[] = [
  {
    label: "Patient store",
    note: "No design exists yet. The patient sidebars carry a “Shop” nav label with no page behind it, and nothing in the design set defines products, prices, a cart or a checkout. Needs a design before it can be mocked up.",
  },
];