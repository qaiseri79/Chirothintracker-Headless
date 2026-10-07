"use client";

import { useEffect, useRef, useState, type ChangeEvent } from "react";
import { Camera, Plus, User, X } from "lucide-react";
import { Dialog, DialogContent, DialogDescription, DialogTitle } from "@/components/ui/dialog";
import { BTN_CANCEL, BTN_SUBMIT, FORM_ERROR, LABEL } from "../../content-forms/content-form-primitives";
import { getPatientPhotos } from "@/lib/patients/patient-photos";
import type { PatientSummaryRow } from "@/lib/patients/summary";
import { useSummaryData } from "./summary-data-provider";

/** Client-only display tag; the backend stores the ordered list only. */
type Tag = "Before" | "After";

/** One tile in the before/after grid; `id` is set for existing Drupal files. */
interface GalleryPhoto {
  id: number | null;
  url: string;
  tag: Tag;
  file?: File;
}

/** The profile photo: an existing file id, or a preview of a new upload. */
interface ProfilePhoto {
  id: number | null;
  url: string;
  file?: File;
}

const GALLERY_LIMIT = 10;
const FILE_LIMIT = 20 * 1024 * 1024;
const ACCEPT = ".png,.gif,.jpg,.jpeg";

/**
 * The design's `photos(p)` modal (`New Design/chirothin-patient-summary-v9.html`)
 * — "Update patient photos": the patient row, the required profile photo with a
 * preview and Choose/Remove actions, and the before/after gallery where each
 * tile's tag toggles between Before and After and the trailing tile adds more.
 * The tag is a display choice only; saving sends the ordered list plus any new
 * uploads, and the row's avatar picks up the new profile photo.
 */
export function UpdatePhotosDialog({ patient, onClose, showToast }: {
  patient: PatientSummaryRow;
  onClose: () => void;
  showToast?: (message: string) => void;
}) {
  const { write } = useSummaryData();
  const [profile, setProfile] = useState<ProfilePhoto | null>(null);
  const [photos, setPhotos] = useState<GalleryPhoto[]>([]);
  const [loading, setLoading] = useState(true);
  const [loadError, setLoadError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const objectUrls = useRef<string[]>([]);

  function preview(file: File): string {
    const url = URL.createObjectURL(file);
    objectUrls.current.push(url);
    return url;
  }
  useEffect(() => () => { for (const url of objectUrls.current) URL.revokeObjectURL(url); }, []);

  useEffect(() => {
    let alive = true;
    getPatientPhotos(patient.id)
      .then((result) => {
        if (!alive) return;
        setProfile(result.profilePhoto ? { id: result.profilePhoto.id, url: result.profilePhoto.url } : null);
        setPhotos(result.photos.map((photo) => ({ id: photo.id, url: photo.url, tag: "Before" })));
      })
      .catch((failure) => {
        if (alive) setLoadError(failure instanceof Error ? failure.message : "Unable to load photos.");
      })
      .finally(() => { if (alive) setLoading(false); });
    return () => { alive = false; };
  }, [patient.id]);

  function chooseProfile(event: ChangeEvent<HTMLInputElement>) {
    const file = event.target.files?.[0];
    event.target.value = "";
    if (!file) return;
    if (!file.type.startsWith("image/") || file.size > FILE_LIMIT) { setError("Choose an image up to 20 MB."); return; }
    setProfile({ id: null, url: preview(file), file });
    setError(null);
  }

  function addPhotos(event: ChangeEvent<HTMLInputElement>) {
    const files = Array.from(event.target.files ?? []);
    event.target.value = "";
    let message: string | null = null;
    const additions: GalleryPhoto[] = [];
    for (const file of files) {
      if (!file.type.startsWith("image/") || file.size > FILE_LIMIT) { message = "Each photo must be an image up to 20 MB."; continue; }
      additions.push({ id: null, url: preview(file), tag: "Before", file });
    }
    if (photos.length + additions.length > GALLERY_LIMIT) message = `Choose up to ${GALLERY_LIMIT} before and after photos.`;
    setPhotos((current) => [...current, ...additions].slice(0, GALLERY_LIMIT));
    setError(message);
  }

  function toggleTag(index: number) {
    setPhotos((current) => current.map((photo, i) => i === index ? { ...photo, tag: photo.tag === "Before" ? "After" : "Before" } : photo));
  }

  async function save() {
    if (!profile) { setError("A profile photo is required."); return; }
    setSaving(true);
    setError(null);
    const form = new FormData();
    form.append("payload", JSON.stringify({
      keepProfileId: profile.file ? null : profile.id,
      keepPhotoIds: photos.filter((photo) => photo.id !== null).map((photo) => photo.id as number),
    }));
    if (profile.file) form.append("profilePhoto", profile.file);
    for (const photo of photos) if (photo.file) form.append("photos[]", photo.file);
    try {
      await write(patient.id, "/photos", form);
      showToast?.("Photos updated");
      onClose();
    } catch (failure) {
      setError(failure instanceof Error ? failure.message : "Unable to save photos. Please try again.");
      setSaving(false);
    }
  }

  return (
    <Dialog open onOpenChange={(next) => { if (!next && !saving) onClose(); }}>
      <DialogContent showCloseButton={false} overlayClassName="bg-[#101827]/40" className="font-sans max-h-[90vh] w-[calc(100vw-2rem)] max-w-xl gap-0 overflow-y-auto rounded-[16px] border-0 bg-surface p-6 shadow-xl sm:rounded-[16px]">
        <DialogTitle className="font-serif text-lg font-normal">Update patient photos</DialogTitle>
        <DialogDescription className="sr-only">Change the patient&apos;s profile photo and before and after photos.</DialogDescription>
        {loading ? (
          <p role="status" className="py-8 text-sm text-muted-foreground">Loading photos…</p>
        ) : loadError ? (
          <div className="py-8">
            <p role="alert" className="text-sm text-destructive">{loadError}</p>
            <button type="button" onClick={() => { setLoading(true); setLoadError(null); void getPatientPhotos(patient.id).then((result) => { setProfile(result.profilePhoto ? { id: result.profilePhoto.id, url: result.profilePhoto.url } : null); setPhotos(result.photos.map((photo) => ({ id: photo.id, url: photo.url, tag: "Before" }))); }).catch((failure) => setLoadError(failure instanceof Error ? failure.message : "Unable to load photos.")).finally(() => setLoading(false)); }} className="mt-3 text-sm font-semibold text-primary underline">Retry</button>
          </div>
        ) : (
          <div className="mt-4 space-y-5">
            <div>
              <label htmlFor={`patient-${patient.id}`} className={LABEL}>Patient <span className="text-flame">*</span></label>
              <input id={`patient-${patient.id}`} disabled value={`Full Name: ${patient.name}`} className="w-full rounded-xl border border-line bg-canvas px-3.5 py-2.5 text-sm text-foreground disabled:opacity-70" />
            </div>
            <div>
              <p className={LABEL}>Profile photo <span className="text-flame">*</span></p>
              <div className="mt-2 flex items-center gap-4">
                {profile ? (
                  <img src={profile.url} alt="" className="h-20 w-20 shrink-0 rounded-full border border-line bg-canvas object-cover" />
                ) : (
                  <span className="flex h-20 w-20 shrink-0 items-center justify-center rounded-full border border-dashed border-line bg-canvas text-muted-foreground">
                    <User className="h-8 w-8" aria-hidden="true" />
                  </span>
                )}
                <div className="space-y-2">
                  <label className="inline-flex cursor-pointer items-center gap-2 rounded-lg border border-line px-3 py-2 text-sm font-medium disabled:pointer-events-none disabled:opacity-40 hover:border-primary">
                    <Camera className="h-4 w-4" aria-hidden="true" />Choose photo
                    <input type="file" accept={ACCEPT} disabled={saving} onChange={chooseProfile} className="hidden" />
                  </label>
                  {profile ? (
                    <button type="button" onClick={() => setProfile(null)} disabled={saving} className="block text-xs font-medium text-red-600 hover:underline disabled:opacity-40">Remove</button>
                  ) : null}
                </div>
              </div>
            </div>
            <div>
              <div className="flex items-center justify-between">
                <p className={LABEL}>Before and after photos</p>
                <span className="text-xs text-muted-foreground">{photos.length} {photos.length === 1 ? "photo" : "photos"}</span>
              </div>
              <div className="mt-2 grid grid-cols-3 gap-2 sm:grid-cols-4">
                {photos.map((photo, index) => (
                  <div key={`${photo.id ?? "new"}-${index}`} className="relative aspect-square overflow-hidden rounded-lg border border-line">
                    <img src={photo.url} alt="" className="h-full w-full object-cover" />
                    <button type="button" title="Click to switch Before/After" onClick={() => toggleTag(index)} disabled={saving} className={`absolute bottom-1 left-1 rounded-full px-2 py-0.5 text-[11px] font-semibold text-white disabled:opacity-40 ${photo.tag === "Before" ? "bg-amber-600" : "bg-positive"}`}>{photo.tag}</button>
                    <button type="button" aria-label="Remove" onClick={() => setPhotos((current) => current.filter((_, i) => i !== index))} disabled={saving} className="absolute right-1 top-1 flex h-5 w-5 items-center justify-center rounded-full bg-foreground/70 text-white hover:bg-red-600 disabled:opacity-40"><X className="h-3 w-3" aria-hidden="true" /></button>
                  </div>
                ))}
                <label className="flex aspect-square cursor-pointer flex-col items-center justify-center gap-1 rounded-lg border border-dashed border-primary/50 text-xs font-semibold text-primary disabled:pointer-events-none disabled:opacity-40 hover:bg-primary-soft">
                  <Plus className="h-5 w-5" aria-hidden="true" />Add photos
                  <input type="file" accept={ACCEPT} multiple disabled={saving} onChange={addPhotos} className="hidden" />
                </label>
              </div>
            </div>
            {error ? <p role="alert" className={FORM_ERROR}>{error}</p> : null}
          </div>
        )}
        <div className="mt-6 flex items-center justify-end gap-3 border-t border-line pt-5">
          <button type="button" onClick={onClose} disabled={saving} className={BTN_CANCEL}>Cancel</button>
          <button type="button" onClick={() => void save()} disabled={saving || loading || !!loadError} className={BTN_SUBMIT}>{saving ? "Saving…" : "Save"}</button>
        </div>
      </DialogContent>
    </Dialog>
  );
}