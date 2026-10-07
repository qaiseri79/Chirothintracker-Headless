/**
 * Attachment helpers shared by the patient thread and the doctor thread.
 *
 * Pure functions only — no React, no fetch — so both the composer that stages a
 * file and the bubble that renders one agree about what a "PDF" is and how
 * "482113" reads.
 *
 * ## The 10 MB / extension list is the frontend's, not Drupal's
 *
 * `MessagesService::storeAttachment()` has its own, looser allowlist and a 20 MB
 * cap. These constants are the stricter, user-facing ones: they reject a file at
 * the point of picking it rather than after the user has composed a message
 * around it. They are deliberately a superset of what the patient composer has
 * always accepted, so tightening them is a product decision, not a refactor.
 */

export const MAX_FILE_BYTES = 10 * 1024 * 1024;

/** The `accept` attribute for the composer's file input. */
export const ATTACHMENT_ACCEPT =
  ".pdf,.jpg,.jpeg,.png,.gif,.webp,.heic,.doc,.docx,.xls,.xlsx,.csv,.txt";

/**
 * The five badge families the design draws. An extension with no entry here
 * (`gif` is mapped to `img`, but say a `.zip`) has no badge colour, so callers
 * treat `null` from {@link kindOf} as "unsupported" rather than guessing.
 */
export const FILE_KINDS: Record<string, { label: string; color: string }> = {
  pdf: { label: "PDF", color: "bg-red-600" },
  img: { label: "IMG", color: "bg-accent" },
  doc: { label: "DOC", color: "bg-blue-600" },
  xls: { label: "XLS", color: "bg-emerald-600" },
  txt: { label: "TXT", color: "bg-slate-500" },
};

/** Extensions rendered as an inline image grid rather than a file row. */
export const IMAGE_EXTENSIONS = ["jpg", "jpeg", "png", "gif", "webp", "heic"] as const;

function extensionOf(filename: string): string {
  return (filename.split(".").pop() || "").toLowerCase();
}

/**
 * The badge family for a filename, or `null` when the type is not accepted.
 *
 * `null` is the reject signal: the caller refuses the file. Note the asymmetry
 * with the badge lookup — a `.zip` has no family *and* must not be shown.
 */
export function kindOf(filename: string): string | null {
  const ext = extensionOf(filename);
  if (ext === "pdf") return "pdf";
  if (IMAGE_EXTENSIONS.includes(ext as (typeof IMAGE_EXTENSIONS)[number])) return "img";
  if (ext === "doc" || ext === "docx") return "doc";
  if (ext === "xls" || ext === "xlsx" || ext === "csv") return "xls";
  if (ext === "txt") return "txt";
  return null;
}

/** True when the filename should render inline as an image. */
export function isImage(filename: string): boolean {
  return IMAGE_EXTENSIONS.includes(extensionOf(filename) as (typeof IMAGE_EXTENSIONS)[number]);
}

export function fmtSize(bytes: number): string {
  if (bytes < 1024) return bytes + " B";
  if (bytes < 1048576) return Math.round(bytes / 1024) + " KB";
  return (bytes / 1048576).toFixed(1) + " MB";
}

/**
 * Why a file cannot be attached, or `null` when it can.
 *
 * Split out from the composer's state machine so the rule is stated once and the
 * caller decides how loudly to complain.
 */
export function rejectReason(file: File): string | null {
  if (!kindOf(file.name)) return `${file.name}: unsupported file type`;
  if (file.size === 0) return `${file.name}: file is empty`;
  if (file.size > MAX_FILE_BYTES) return `${file.name}: larger than 10 MB`;
  return null;
}
