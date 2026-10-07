"use client";

import { useState } from "react";
import { createPortal } from "react-dom";
import { Pin, Plus, Search } from "lucide-react";
import { useSummaryData } from "./summary-data-provider";
import type { PatientSummaryRow } from "@/lib/patients/summary";

/**
 * The Notes tab: the chiropractor's private notes on the patient.
 *
 * A search box and an "Add note" button over an inline composer (shown while a
 * note is being written) and the note list. The list is filtered by the search
 * and sorted pinned-first, then newest-first, so the notes that matter stay at
 * the top regardless of age.
 *
 * Saves, deletes, and pins use the shared patient API and confirmed entity IDs.
 */

const INPUT_CLASSES =
  "w-full rounded-lg border border-line bg-white px-3 py-2 text-sm focus:border-[#0B5D52] focus:outline-none";

export function NotesTab({
  notesList,
  patientId,
  showToast,
}: {
  notesList: PatientSummaryRow["notesList"];
  patientId: number;
  showToast: (message: string) => void;
}) {
  const { write, readOnly, pending } = useSummaryData();
  const [error, setError] = useState<string | null>(null);
  const [searchQuery, setSearchQuery] = useState("");
  const [editingNoteId, setEditingNoteId] = useState<number | "new" | null>(null);
  const [draftDate, setDraftDate] = useState("");
  const [draftText, setDraftText] = useState("");
  /** Whether the delete-confirmation modal is open. */
  const [showDeleteConfirm, setShowDeleteConfirm] = useState(false);

  function openNewNote() {
    setEditingNoteId("new");
    setDraftDate(new Date().toISOString().slice(0, 10));
    setDraftText("");
  }

  function openEditNote(note: PatientSummaryRow["notesList"][number]) {
    setEditingNoteId(note.id);
    setDraftDate(note.date);
    setDraftText(note.text);
  }

  function closeComposer() {
    setEditingNoteId(null);
  }

  /** Apply an incremental note change and report the confirmed result. */
  async function change(payload: unknown, message: string) {
    setError(null);
    try { await write(patientId, "/notes", payload, "notes"); showToast(message); return true; }
    catch (cause) { setError(cause instanceof Error ? cause.message : "Unable to save note."); return false; }
  }
  async function handleSave() {
    if (!draftText.trim()) return;
    if (await change({ action: "save", ...(typeof editingNoteId === "number" ? { id: editingNoteId } : {}), date: draftDate, text: draftText }, "Note saved")) closeComposer();
  }
  function handleDelete() { setShowDeleteConfirm(true); }
  async function confirmDelete() {
    if (typeof editingNoteId !== "number") return;
    if (await change({ action: "delete", id: editingNoteId }, "Note deleted")) { setShowDeleteConfirm(false); closeComposer(); }
  }
  async function handleTogglePin(noteId: number) {
    const note = notesList.find((item) => item.id === noteId);
    if (note) await change({ action: "pin", id: noteId, pinned: !note.isPinned }, "Note updated");
  }

  const visibleNotes = notesList
    .filter((note) => note.text.toLowerCase().includes(searchQuery.trim().toLowerCase()))
    .sort((a, b) => {
      if (a.isPinned !== b.isPinned) return a.isPinned ? -1 : 1;
      return b.date.localeCompare(a.date);
    });

  return (
    <div>
      {error && <p role="alert" className="mb-3 text-sm text-destructive">{error}</p>}
      <div className="mb-5 flex flex-wrap items-center gap-3">
        <div className="relative w-full sm:w-72">
          <Search className="absolute left-3 top-1/2 size-4 -translate-y-1/2 text-[#6B7280]" />
          <input
            type="search"
            value={searchQuery}
            onChange={(event) => setSearchQuery(event.target.value)}
            placeholder="Search notes..."
            className="w-full rounded-lg border border-line bg-white py-2 pl-9 pr-3 text-sm focus:border-[#0B5D52] focus:outline-none"
          />
        </div>
        <span className="text-xs text-[#6B7280]">Private: only you can see these notes.</span>
        <button
            disabled={readOnly || pending[patientId]}
          type="button"
          onClick={openNewNote}
          className="ml-auto inline-flex items-center gap-2 rounded-full bg-[#0B5D52] px-4 py-2 text-sm font-semibold text-white hover:bg-[#08423A]"
        >
          <Plus className="size-4" /> Add note
        </button>
      </div>

      {editingNoteId !== null ? (
        <div className="mb-5 space-y-3 rounded-2xl border border-line bg-white p-5">
          <h3 className="font-serif text-lg text-[#101827]">
            {editingNoteId === "new" ? "New note" : "Edit note"}
          </h3>
          <div>
            <label className="mb-1 block text-sm font-medium">Date</label>
            <input
              type="date"
              value={draftDate}
              onChange={(event) => setDraftDate(event.target.value)}
              className={INPUT_CLASSES}
            />
          </div>
          <div>
            <label className="mb-1 block text-sm font-medium">Notes</label>
            <textarea
              rows={4}
              value={draftText}
              onChange={(event) => setDraftText(event.target.value)}
              className={INPUT_CLASSES}
            />
          </div>
          <div className="flex items-center gap-2">
            {editingNoteId !== "new" ? (
              <button
            disabled={readOnly || pending[patientId]}
                type="button"
                onClick={handleDelete}
                className="mr-auto text-sm font-semibold text-red-700 hover:underline"
              >
                Delete
              </button>
            ) : null}
            <button
            disabled={readOnly || pending[patientId]}
              type="button"
              onClick={closeComposer}
              className="rounded-full border border-line px-4 py-2 text-sm font-semibold"
            >
              Cancel
            </button>
            <button
            disabled={readOnly || pending[patientId]}
              type="button"
              onClick={handleSave}
              className="rounded-full bg-[#0B5D52] px-5 py-2 text-sm font-semibold text-white"
            >
              Save
            </button>
          </div>
        </div>
      ) : null}

      <div className="divide-y divide-line overflow-hidden rounded-2xl border border-line bg-white">
        {visibleNotes.map((note) => (
          <div key={note.id} className="flex items-start gap-3 px-4 py-3">
            <span className="w-28 shrink-0 pt-0.5 text-sm font-medium text-[#101827]">
              {note.date}
            </span>
            <p className="min-w-0 flex-1 whitespace-pre-line text-sm text-[#101827]">
              {note.text}
            </p>
            <button
            disabled={readOnly || pending[patientId]}
              type="button"
              onClick={() => handleTogglePin(note.id)}
              className={
                note.isPinned
                  ? "rounded-md bg-[#F6E4DC] p-1.5 text-[#A8421F]"
                  : "rounded-md p-1.5 text-[#6B7280] hover:text-[#101827]"
              }
              aria-label={note.isPinned ? "Unpin note" : "Pin note"}
            >
              <Pin className="size-4" />
            </button>
            <button
            disabled={readOnly || pending[patientId]}
              type="button"
              onClick={() => openEditNote(note)}
              className="rounded-md border border-line px-2.5 py-1 text-xs font-semibold hover:border-[#0B5D52]"
            >
              Edit
            </button>
          </div>
        ))}
      </div>

      {showDeleteConfirm && typeof document !== "undefined"
        ? createPortal(
            <div className="fixed inset-0 z-[60] flex items-center justify-center bg-[#101827]/40 p-4">
              <div
                role="dialog"
                className="w-full max-w-md rounded-2xl bg-white p-6 shadow-xl"
              >
                <h3 className="font-serif text-lg text-[#101827]">Delete this note?</h3>
                <div className="mt-4 space-y-3 text-sm text-[#101827]">
                  <p>This cannot be undone.</p>
                </div>
                <div className="mt-5 flex justify-end gap-2">
                  <button
            disabled={readOnly || pending[patientId]}
                    type="button"
                    onClick={() => setShowDeleteConfirm(false)}
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
