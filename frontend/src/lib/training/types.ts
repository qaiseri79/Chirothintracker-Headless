/**
 * Training material, as the endpoint delivers it.
 *
 * Every shape here is read off `ContentService::serialise()` and its `TEXT_FIELDS`
 * / `FILE_FIELDS` maps for the `training` bundle — so this file is deliberately
 * not a superset of what a training card could want. A field the endpoint never
 * sends can only ever be `undefined`, and a UI branch built on one is a branch
 * that never runs. (The hand-written fixture this replaced had exactly such
 * fields; see {@see TrainingVideo}.)
 */

/**
 * A file field as the endpoint serialises it: `{url, name, mime, size}`, or
 * `undefined` when the node has no file.
 *
 * **No `thumbnail`, no `duration`.** Both were in the fixture that stood in for
 * this, and the card rendered them, so removing them changes the card's
 * appearance. `ContentService::fileReference()` builds this shape from the file
 * entity, and a Drupal file carries no poster frame and no runtime — a duration
 * would have to be measured in the browser, per node, before it could be shown.
 * The card offers a play affordance instead. If a poster frame is wanted later,
 * it belongs as a new field on `ContentService`, not as a guess here.
 */
export interface TrainingVideo {
  /** Absolute URL. The only field a player needs. */
  url: string;
  name?: string;
  mime?: string;
  size?: number;
}

export interface TrainingNode {
  id: number;
  title: string;
  /**
   * Body as HTML, rendered by the site's own filter pipeline.
   *
   * `html` and not `text`: the card renders markup, and `text` would have to be
   * escaped by hand. Drupal has already run the stored value through a restricted
   * format (`RENDERABLE_FORMATS` in ContentService), which is what makes handing
   * it to `dangerouslySetInnerHTML` safe. A row with a body but no rendered HTML
   * yields `undefined` rather than an unescaped fallback — the title still renders,
   * which is better than the body arriving as visible markup.
   */
  body?: string;
  video?: TrainingVideo;
}

/**
 * The caller's capability flags, from the list payload's `capabilities` block.
 *
 * `canCreate` is what the "Add training" button is gated on. It is computed by
 * the backend against the actual create route (ContentCapabilities), so the
 * button never appears for a caller — patient, inactive doctor — the endpoint
 * would refuse.
 */
export interface TrainingCapabilities {
  canCreate: boolean;
  canDelete: boolean;
  canManageOwnContent: boolean;
  canFavourite: boolean;
  audience: string;
}

/** Narrows a `capabilities` block from the payload, or null when unusable. */
export function toCapabilities(value: unknown): TrainingCapabilities | null {
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

/**
 * The raw list envelope from `GET /api/headless/content/training`.
 *
 * Fields are untrusted and narrowed by the mapper rather than cast, for the
 * reason `RecipeListResponse` gives: a 200 with an unexpected shape should render
 * an empty library, not throw while the page is being rendered.
 */
export interface TrainingListResponse {
  bundle?: string;
  label?: string;
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
interface RawTraining {
  id?: unknown;
  title?: unknown;
  body?: { text?: unknown; html?: unknown } | null;
  video?: {
    url?: unknown;
    name?: unknown;
    mime?: unknown;
    size?: unknown;
  } | null;
}

/**
 * One endpoint row to one `TrainingNode`, or null when it cannot be rendered.
 *
 * Null for a row with no usable id: the card is keyed by it, so there is nothing
 * to render one as.
 */
export function toTraining(raw: unknown): TrainingNode | null {
  if (typeof raw !== "object" || raw === null) return null;
  const row = raw as RawTraining;

  if (typeof row.id !== "number") return null;

  const body =
    typeof row.body?.html === "string" && row.body.html !== ""
      ? row.body.html
      : undefined;

  let video: TrainingVideo | undefined;
  if (typeof row.video?.url === "string" && row.video.url !== "") {
    video = { url: row.video.url };
    if (typeof row.video.name === "string") video.name = row.video.name;
    if (typeof row.video.mime === "string") video.mime = row.video.mime;
    if (typeof row.video.size === "number") video.size = row.video.size;
  }

  return {
    id: row.id,
    title: typeof row.title === "string" ? row.title : "",
    body,
    video,
  };
}

/** The whole list, with unusable rows dropped. */
export function toTrainingList(items: unknown): TrainingNode[] {
  if (!Array.isArray(items)) return [];
  return items.map(toTraining).filter((node): node is TrainingNode => node !== null);
}
