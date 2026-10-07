/**
 * The resource shape the UI renders, and the mapper from the API's.
 *
 * ## The endpoint returns a file, not a document
 *
 * `GET /api/headless/content/chirothin_resource` serialises `field_resource`, which
 * is a Drupal `file` field, as:
 *
 *     { "url": "https://…/Loading%20Phase…pdf",
 *       "name": "Loading Phase - Consider These Smoothies.pdf",
 *       "mime": "application/pdf",
 *       "size": 407147 }
 *
 * Three consequences the page has to absorb:
 *
 * - **`size` is a byte count, not a string.** The placeholder data carried `"2.4 MB"`
 *   already formatted, which is why this looks like a drop-in swap and is not. Every
 *   value is an integer (`23833` for a 23 KB `.docx`, `61091404` for a 58 MB `.mp4`).
 * - **`resource` is `null` for 12 of the 138 published resources.** Those nodes exist
 *   and are titled ("ChiroThin - Getting Started Video", "Join Our Facebook
 *   Community") but their `field_resource` holds `target_id: "0"` — an empty file
 *   reference. There is no link field on this bundle to fall back to: the only
 *   fields are `field_description`, `field_my_clinic`, `field_published_to`,
 *   `field_resource` and `field_resource_type`. So they are real rows that cannot be
 *   downloaded, and they have to render as a title with no file rather than as a
 *   dead download button pointing at `""`.
 * - **`mime` decides the preview, not the extension.** The published set is pdf,
 *   jpeg, png, docx, doc, xlsx and mp4 — so a viewer that only handles pdf would
 *   show "cannot be previewed" for the videos and the images.
 *
 * ## Categories
 *
 * `resourceTypes` is the only taxonomy on the bundle, and it is single-valued in
 * practice: the four labels in use are `Documents`, `Videos`, `Guides` and
 * `Products`. The field is cardinality 1 on the node type, but it is typed as an
 * array and the mapper takes the first term so a second one cannot produce a blank
 * heading. The full list is preserved for the same reason as on recipes — a
 * category filter over all terms should not need a second fetch.
 *
 * ## `description` is mostly there, and sometimes only whitespace
 *
 * Of the 138 published resources, 64 carry real description text, 70 are null, and
 * 4 hold nothing but whitespace — `"  \n\n  "` from an editor that was opened and
 * closed without typing. So the field is worth mapping and worth searching, and
 * {@link toResource} trims it so a blank panel is never rendered.
 */

import { fmtSize } from "@/lib/messages/attachments";

/** One `{id, label}` resource-type term as the endpoint emits it. */
export interface ResourceTerm {
  id: number;
  label: string;
}

/**
 * How the viewer should present a resource's file.
 *
 * Derived from `mime` in {@link toResource} so the choice is testable and stated
 * once, rather than re-guessed from the filename in the component.
 *
 * - `pdf` — browsers render these inline, so an `<iframe>` works.
 * - `image` — an `<img>`, not an iframe, so it scales to the modal.
 * - `video` — a `<video>` element; an iframe would not get controls.
 * - `none` — Word and Excel files. The browser cannot display them, so the only
 *   honest action is a download.
 */
export type ResourcePreview = "pdf" | "image" | "video" | "none";

/** What the resources page renders, one entry per row. */
export interface ResourceNode {
  id: number;
  title: string;
  /** Grouping heading: the first resource-type term, or `Uncategorised`. */
  group: string;
  /** Every resource-type term, not just the heading. */
  resourceTypes: ResourceTerm[];
  /** Filename as uploaded, or `""` when the node has no file attached. */
  file: string;
  /** Human-readable byte count, or `""` when there is no file. */
  size: string;
  /** Absolute URL to the file, or `""` when the node has no file attached. */
  url: string;
  /** The file's MIME type as Drupal reports it, or `""`. */
  mime: string;
  /** Whether this row has a file at all — the gate for every file action. */
  hasFile: boolean;
  /** How to preview it, or `none` when it cannot be previewed. */
  preview: ResourcePreview;
  /** The long description as plain text, or `""` when there is none. */
  description: string;
}

/** Group heading for a resource that carries no resource-type term. */
export const UNCATEGORISED = "Uncategorised";

/**
 * The option list the "Add resource" form renders.
 *
 * Mirrors the payload key `resourceTypes` the create endpoint validates against
 * (ContentService::OPTION_VOCABULARIES maps it to the `resource_category`
 * vocabulary), so the choices the form offers can never name a different
 * vocabulary than the one a submitted id is checked against. The bundle's field
 * is cardinality 1, and the option list respects that: the form enforces exactly
 * one selection.
 */
export interface ResourceOptions {
  resourceTypes: ResourceTerm[];
}

/**
 * The caller's capability flags, from the list payload's `capabilities` block.
 *
 * `canCreate` is what the "Add resource" button is gated on. It is computed by
 * the backend against the actual create route (ContentCapabilities), so the
 * button never appears for a caller — patient, inactive doctor — the endpoint
 * would refuse.
 */
export interface ResourceCapabilities {
  canCreate: boolean;
  canDelete: boolean;
  canManageOwnContent: boolean;
  canFavourite: boolean;
  audience: string;
}

/** Narrows a `capabilities` block from the payload, or null when unusable. */
export function toCapabilities(value: unknown): ResourceCapabilities | null {
  if (typeof value !== "object" || value === null) return null;
  const raw = value as { canCreate?: unknown; canDelete?: unknown; canManageOwnContent?: unknown; canFavourite?: unknown; audience?: unknown };

  return {
    canCreate: raw.canCreate === true,
    canDelete: raw.canDelete === true,
    canManageOwnContent: raw.canManageOwnContent === true,
    canFavourite: raw.canFavourite === true,
    audience: typeof raw.audience === "string" ? raw.audience : "",
  };
}

/** Narrows the option payload, dropping unusable terms per field. */
export function toResourceOptions(value: unknown): ResourceOptions | null {
  if (typeof value !== "object" || value === null) return null;
  const raw = value as { resourceTypes?: unknown };

  return { resourceTypes: toTerms(raw.resourceTypes) };
}

/**
 * The raw list envelope from `GET /api/headless/content/chirothin_resource`.
 *
 * Every field is untrusted: the mapper narrows each one rather than casting, so a
 * 200 with an unexpected shape renders an empty library instead of throwing while
 * the page is rendering.
 */
export interface ResourceListResponse {
  bundle?: string;
  label?: string;
  /** `shared` or the audience term this row was scoped to. */
  visibility?: string;
  items?: unknown[];
  pagination?: {
    page?: number;
    pageSize?: number;
    pageCount?: number;
    totalItems?: number;
  };
  capabilities?: Record<string, unknown>;
}

/** The row shape the mapper reads. Declared loosely for the same reason. */
interface RawResource {
  id?: unknown;
  title?: unknown;
  description?: { text?: unknown; html?: unknown } | null;
  resourceTypes?: unknown;
  resource?: {
    url?: unknown;
    name?: unknown;
    mime?: unknown;
    size?: unknown;
  } | null;
}

/** Narrows one `{id, label}` term, or null when it is not usable as one. */
function toTerm(value: unknown): ResourceTerm | null {
  if (typeof value !== "object" || value === null) return null;
  const { id, label } = value as { id?: unknown; label?: unknown };

  // No id means the term cannot be keyed; no label renders a blank heading, which
  // is the bug UNCATEGORISED exists to prevent.
  if (typeof id !== "number" || typeof label !== "string" || label === "") return null;

  return { id, label };
}

/** Narrows a list of terms, dropping unusable entries. */
function toTerms(value: unknown): ResourceTerm[] {
  if (!Array.isArray(value)) return [];
  return value.map(toTerm).filter((term): term is ResourceTerm => term !== null);
}

/**
 * Picks the viewer for a MIME type.
 *
 * `application/pdf` and the two raster image types are matched exactly rather than
 * by prefix. `image/svg+xml` is deliberately excluded: an SVG served from the same
 * origin as the portal and rendered inline is script-capable, and none of the
 * published resources are SVGs. A prefix test would silently pull it in.
 */
function toPreview(mime: string): ResourcePreview {
  if (mime === "application/pdf") return "pdf";
  if (mime === "image/jpeg" || mime === "image/png") return "image";
  if (mime.startsWith("video/")) return "video";
  return "none";
}

/**
 * One endpoint row to one `ResourceNode`.
 *
 * Returns null only for a row with no usable id, which is the one field nothing can
 * be rendered without: the row is keyed by it.
 *
 * A row with no file is *not* dropped. Those 12 nodes are published content the
 * clinic can see, and hiding them would make a real gap in the library look like
 * the library is smaller than it is. They render as a title with no file actions.
 */
export function toResource(raw: unknown): ResourceNode | null {
  if (typeof raw !== "object" || raw === null) return null;
  const row = raw as RawResource;

  if (typeof row.id !== "number") return null;

  const resourceTypes = toTerms(row.resourceTypes);

  // A file is only usable when it has a URL to fetch. `name`, `mime` and `size` can
  // each be missing on their own, and each falls back rather than disqualifying the
  // row: a nameless PDF is still a PDF a user needs.
  const file = typeof row.resource?.url === "string" ? row.resource.url : "";
  const mime = typeof row.resource?.mime === "string" ? row.resource.mime : "";
  const bytes = row.resource?.size;

  // Only formatted when it is a real number. `fmtSize` divides, so a missing or
  // non-numeric size has to be caught before it reaches it rather than rendering
  // "NaN" in the size column.
  const size = typeof bytes === "number" && Number.isFinite(bytes) ? fmtSize(bytes) : "";

  return {
    id: row.id,
    title: typeof row.title === "string" ? row.title : "",
    group: resourceTypes[0]?.label ?? UNCATEGORISED,
    resourceTypes,
    file: typeof row.resource?.name === "string" ? row.resource.name : "",
    size,
    url: file,
    mime,
    hasFile: file !== "",
    preview: toPreview(mime),
    // `text` rather than `html`: the modal renders this in a React text node, which
    // escapes it. The HTML would print as literal markup.
    //
    // Trimmed because 4 of the 138 rows hold only whitespace, and a description
    // panel rendering `"  \n\n  "` looks like a bug. Trimming also makes the
    // component's truthiness check correct without a second guard.
    description:
      typeof row.description?.text === "string" ? row.description.text.trim() : "",
  };
}

/** The whole list, with unusable rows dropped. */
export function toResources(items: unknown): ResourceNode[] {
  if (!Array.isArray(items)) return [];
  return items.map(toResource).filter((node): node is ResourceNode => node !== null);
}
