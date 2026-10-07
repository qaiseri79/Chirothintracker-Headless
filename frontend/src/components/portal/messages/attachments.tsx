/**
 * A file-type badge, and the two attachment layouts a message bubble needs.
 *
 * Shared by the patient thread and the doctor thread so an uploaded PDF cannot
 * look like one thing in a patient's thread and another in a chiropractor's.
 */

import { FILE_KINDS, fmtSize, isImage, kindOf } from "@/lib/messages/attachments";
import type { Attachment } from "@/lib/messages/types";

/**
 * The 36px square type badge the design draws beside a file.
 *
 * Renders nothing for an unrecognised kind, because there is no colour to draw
 * it with — a file the server let through but the frontend has no badge for is
 * shown as its name alone rather than as a mislabelled PDF.
 */
export function FileBadge({
  kind,
  className = "",
}: {
  kind: string;
  className?: string;
}) {
  const k = FILE_KINDS[kind];
  if (!k) return null;
  return (
    <span
      className={`flex h-9 w-9 shrink-0 items-center justify-center rounded-lg text-[10px] font-bold tracking-wide text-white ${k.color} ${className}`}
    >
      {k.label}
    </span>
  );
}

/** Splits attachments into the inline image grid and the file rows below it. */
export function splitAttachments(attachments: Attachment[]): {
  images: Attachment[];
  files: Attachment[];
} {
  return {
    images: attachments.filter((a) => isImage(a.name)),
    files: attachments.filter((a) => !isImage(a.name)),
  };
}

/**
 * The download URL for an attachment.
 *
 * Always through `/api/messages/attachment/[fid]` rather than a direct
 * `private://` path: the proxy is what forwards the session cookie, and
 * `MessagesService::attachmentFile()` is what refuses anyone who is not party
 * to the thread owning the file.
 */
export function attachmentUrl(fid: number): string {
  return `/api/messages/attachment/${fid}`;
}

/**
 * A non-image attachment as a tappable row.
 *
 * `mine` flips the fill so it stays legible on the doctor's filled bubble as
 * well as on the patient's outlined one.
 */
export function AttachmentRow({
  attachment,
  mine,
}: {
  attachment: Attachment;
  mine: boolean;
}) {
  return (
    <a
      href={attachmentUrl(attachment.fid)}
      target="_blank"
      rel="noopener noreferrer"
      className={`flex items-center gap-2.5 rounded-lg px-2.5 py-2 transition-colors ${
        mine ? "bg-white/15 hover:bg-white/25" : "bg-black/[0.06] hover:bg-black/10"
      }`}
    >
      <FileBadge kind={kindOf(attachment.name) ?? ""} />
      <span className="min-w-0">
        <span className="block truncate text-[13px] font-medium">{attachment.name}</span>
        <span className={`block text-[11px] ${mine ? "text-white/70" : "opacity-70"}`}>
          {fmtSize(attachment.size)}
        </span>
      </span>
    </a>
  );
}

/** The inline image grid, shown above the file rows when a message has images. */
export function AttachmentImages({ attachments }: { attachments: Attachment[] }) {
  if (attachments.length === 0) return null;
  return (
    <div className={`mt-2 grid gap-1.5 ${attachments.length > 1 ? "grid-cols-2" : "grid-cols-1"}`}>
      {attachments.map((att) => (
        <a
          key={att.fid}
          href={attachmentUrl(att.fid)}
          target="_blank"
          rel="noopener noreferrer"
        >
          {/* eslint-disable-next-line @next/next/no-img-element -- streams through
              the authenticated attachment proxy, which is not a Next image loader */}
          <img
            src={attachmentUrl(att.fid)}
            alt={att.name}
            className="h-32 w-full rounded-lg object-cover"
          />
        </a>
      ))}
    </div>
  );
}

/** The stacked file rows below the image grid. */
export function AttachmentFiles({
  attachments,
  mine,
}: {
  attachments: Attachment[];
  mine: boolean;
}) {
  if (attachments.length === 0) return null;
  return (
    <div className="mt-2 space-y-1.5">
      {attachments.map((att) => (
        <AttachmentRow key={att.fid} attachment={att} mine={mine} />
      ))}
    </div>
  );
}
