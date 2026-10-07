"use client";

import { useState } from "react";
import { createPortal } from "react-dom";
import { Download } from "lucide-react";
import { useSummaryData } from "./summary-data-provider";
import type { PatientSummaryRow } from "@/lib/patients/summary";

/**
 * The Attachments tab: files the chiropractor has on the patient.
 *
 * An upload box over the file list. Each row shows a colour-coded extension
 * chip, the file's name, its formatted size and date, and Download / Delete
 * actions. Uploads and downloads use clinic-scoped, authenticated file endpoints.
 */

/** Human-readable file size: MB above 1 MB, otherwise whole KB (min 1). */
function formatBytes(bytes: number) {
  if (bytes > 1048576) return (bytes / 1048576).toFixed(1) + " MB";
  return Math.max(1, Math.round(bytes / 1024)) + " KB";
}

export function AttachmentsTab({
  attachmentsList,
  patientId,
  showToast,
}: {
  attachmentsList: PatientSummaryRow["attachmentsList"];
  patientId: number;
  showToast: (message: string) => void;
}) {
  /** The file the delete modal is confirming, or null when it is closed. */
  const { write, readOnly, pending } = useSummaryData();
  const [error, setError] = useState<string | null>(null);
  const [deleteTarget, setDeleteTarget] = useState<number | null>(null);

  /** The modal's confirmed delete: remove the file, close, toast. */
  async function confirmDelete() {
    if (!deleteTarget) return;
    setError(null);
    try { await write(patientId, `/files/${deleteTarget}`, null, "attachments", "DELETE"); setDeleteTarget(null); showToast("Attachment deleted"); }
    catch (cause) { setError(cause instanceof Error ? cause.message : "Unable to delete attachment."); }
  }
  async function upload(file: File) {
    setError(null);
    if (file.size > 20 * 1024 * 1024) { setError("Choose a file up to 20 MB."); return; }
    const form = new FormData(); form.append("file", file);
    try { await write(patientId, "/files", form, "attachments"); showToast("Attachment uploaded"); }
    catch (cause) { setError(cause instanceof Error ? cause.message : "Unable to upload attachment."); }
  }

  return (
    <div>
      {error && <p role="alert" className="mb-3 text-sm text-destructive">{error}</p>}
      <div className="mb-5 rounded-2xl border border-line bg-white p-5">
        <h3 className="font-serif text-lg text-[#101827]">Add attachment</h3>
        <label className="mt-3 flex cursor-pointer flex-col items-center gap-1 rounded-xl border-2 border-dashed border-line px-4 py-8 text-center text-sm hover:border-[#0B5D52]">
          <Download className="size-5 rotate-180 text-[#0B5D52]" />
          <span className="font-semibold text-[#0B5D52]">Choose a file or drag it here</span>
          <span className="text-xs text-[#6B7280]">
            One file at a time, up to 20 MB. PDF, DOCX, DOC, XLSX, XLS, JPG, PNG.
          </span>
          <input
            type="file"
            accept=".pdf,.docx,.doc,.xlsx,.xls,.jpg,.jpeg,.png"
            className="sr-only"
            disabled={readOnly || pending[patientId]}
            onChange={(event) => { const file = event.target.files?.[0]; if (file) void upload(file); event.target.value = ""; }}
          />
        </label>
      </div>

      <div className="divide-y divide-line overflow-hidden rounded-2xl border border-line bg-white">
        {attachmentsList.length > 0 ? (
          attachmentsList.map((file) => {
            const ext = file.name.split(".").pop()?.toLowerCase() || "";
            // Simple color mapping for the icon
            const extColor = ["pdf"].includes(ext)
              ? "#C0392B"
              : ["xls", "xlsx"].includes(ext)
                ? "#1D6F42"
                : ["doc", "docx"].includes(ext)
                  ? "#2B579A"
                  : "#B7791F";

            return (
              <div key={file.id} className="flex items-center gap-3 px-4 py-3">
                <span
                  className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg text-[11px] font-bold uppercase text-white"
                  style={{ backgroundColor: extColor }}
                >
                  {ext.slice(0, 4)}
                </span>
                <div className="min-w-0 flex-1">
                  <p className="truncate text-sm font-semibold text-[#101827]">{file.name}</p>
                  <p className="text-xs text-[#6B7280]">
                    {formatBytes(file.size)} · {file.date}
                  </p>
                </div>
                <a
                  href={file.url}
                  className="inline-flex items-center gap-1.5 rounded-full border border-line px-3 py-1.5 text-xs font-semibold hover:border-[#0B5D52]"
                >
                  <Download className="size-3.5" /> Download
                </a>
                <button
                  disabled={readOnly || pending[patientId]}
                  onClick={() => setDeleteTarget(file.id)}
                  className="rounded-full px-3 py-1.5 text-xs font-semibold text-red-700 hover:bg-red-50"
                >
                  Delete
                </button>
              </div>
            );
          })
        ) : (
          <p className="px-4 py-8 text-center text-sm text-[#6B7280]">No attachments yet.</p>
        )}
      </div>

      {deleteTarget && typeof document !== "undefined"
        ? createPortal(
            <div className="fixed inset-0 z-[60] flex items-center justify-center bg-[#101827]/40 p-4">
              <div
                role="dialog"
                className="w-full max-w-md rounded-2xl bg-white p-6 shadow-xl"
              >
                <h3 className="font-serif text-lg text-[#101827]">Delete attachment?</h3>
                <div className="mt-4 space-y-3 text-sm text-[#101827]">
                  <p>Are you sure you want to delete &quot;{attachmentsList.find((file) => file.id === deleteTarget)?.name}&quot;? This cannot be undone.</p>
                </div>
                <div className="mt-5 flex justify-end gap-2">
                  <button
                  disabled={readOnly || pending[patientId]}
                    type="button"
                    onClick={() => setDeleteTarget(null)}
                    className="rounded-full border border-line px-4 py-2 text-sm font-semibold"
                  >
                    Cancel
                  </button>
                  <button
                  disabled={readOnly || pending[patientId]}
                    type="button"
                    onClick={confirmDelete}
                    className="rounded-full bg-red-700 px-5 py-2 text-sm font-semibold text-white hover:bg-red-800"
                  >
                    Delete
                  </button>
                </div>
              </div>
            </div>,
            document.body,
          )
        : null}
    </div>
  );
}
