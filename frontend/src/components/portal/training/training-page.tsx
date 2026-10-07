"use client";

import { useCallback, useState } from "react";
import { useRouter } from "next/navigation";
import { Play, X, Info, GraduationCap } from "lucide-react";
import { AddTrainingDialog } from "@/components/portal/training/add-training-dialog";
import { Toast } from "@/components/portal/toast";
import type { TrainingNode } from "@/lib/training/types";

interface TrainingPageProps {
  nodes: TrainingNode[];
  /**
   * Why the library could not be read, or null when it was.
   *
   * Distinct from an empty list on purpose: "no training materials" and "Drupal is
   * down" look identical if they share a branch, and a patient cannot tell which
   * one they are looking at.
   */
  error?: string | null;
  /**
   * Whether the backend would admit a training create for this caller. The "Add
   * training" button shows only for active chiropractors; patients and inactive
   * doctors never see it because the endpoint would refuse them.
   */
  canCreate?: boolean;
}

export function TrainingPage({ nodes, error = null, canCreate = false }: TrainingPageProps) {
  const router = useRouter();
  const [items, setItems] = useState<TrainingNode[]>(nodes);
  const [activeVideo, setActiveVideo] = useState<TrainingNode | null>(null);
  const [addOpen, setAddOpen] = useState(false);
  const [toast, setToast] = useState<string | null>(null);

  /**
   * A newly created item lists immediately, above everything else — the freshest
   * row and the one the doctor is looking for — with no reload.
   */
  const handleCreated = useCallback((node: TrainingNode) => {
    setItems((prev) => [node, ...prev]);
    setToast("Training published");
  }, []);

  const handleVideoClick = useCallback((node: TrainingNode) => {
    setActiveVideo(node);
  }, []);

  const handleCloseModal = useCallback(() => {
    setActiveVideo(null);
  }, []);

  const header = (
    <div className="flex flex-wrap items-start justify-between gap-3">
      <div>
        <h1 className="font-serif text-2xl text-foreground">Training</h1>
        <p className="mt-1 text-sm text-muted-foreground">
          Program training materials and educational videos.
        </p>
      </div>
      {canCreate && (
        <button
          type="button"
          onClick={() => setAddOpen(true)}
          className="inline-flex items-center justify-center gap-2 rounded-xl px-4 py-2.5 text-sm font-semibold transition-colors disabled:opacity-40 bg-primary text-white shadow-sm hover:bg-brand-dark"
        >
          <GraduationCap className="size-[18px] shrink-0" aria-hidden="true" />
          Add training
        </button>
      )}
    </div>
  );

  if (error) {
    return (
      <div className="space-y-4">
        {header}
        <div
          role="alert"
          className="rounded-2xl border border-line bg-surface p-5 shadow-panel"
        >
          <p className="text-sm text-foreground">{error}</p>
          <button
            type="button"
            onClick={() => router.refresh()}
            className="mt-3 rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-primary-dark"
          >
            Try again
          </button>
        </div>
      </div>
    );
  }

  return (
    <>
      <div className="space-y-6">
        {header}

        {canCreate && (
          <AddTrainingDialog
            open={addOpen}
            onOpenChange={setAddOpen}
            onCreated={handleCreated}
            onAuthError={() => router.replace("/login")}
          />
        )}

        <div className="space-y-4">
          {items.map((node) => (
            <TrainingNodeCard
              key={node.id}
              node={node}
              onPlay={handleVideoClick}
            />
          ))}

          {items.length === 0 && (
            <div className="rounded-2xl border border-line bg-surface p-10 text-center shadow-panel">
              <p className="text-muted-foreground text-sm">No training materials available.</p>
            </div>
          )}
        </div>
      </div>

      {/* Video Modal */}
      {activeVideo && (
        <div
          className="fixed inset-0 z-50 flex items-center justify-center bg-foreground/70 p-3 sm:p-8"
          onClick={handleCloseModal}
        >
          <div
            className="w-full max-w-4xl overflow-hidden rounded-2xl bg-surface shadow-panel"
            onClick={(e) => e.stopPropagation()}
          >
            <div className="flex items-center gap-3 border-b border-line px-5 py-3">
              <h3 className="min-w-0 flex-1 truncate font-serif text-lg">
                {activeVideo.title}
              </h3>
              <button
                onClick={handleCloseModal}
                aria-label="Close"
                className="flex h-8 w-8 items-center justify-center rounded-lg text-muted-foreground hover:bg-canvas"
              >
                <X className="h-4 w-4" aria-hidden="true" />
              </button>
            </div>

            <div className="relative aspect-video w-full bg-black">
              {activeVideo.video && (
                <video
                  controls
                  autoPlay
                  src={activeVideo.video.url}
                  className="h-full w-full"
                />
              )}
            </div>

            {activeVideo.body && (
              <div
                className="body-html border-t border-line px-5 py-4 text-sm leading-relaxed text-foreground/75"
                dangerouslySetInnerHTML={{ __html: activeVideo.body }}
              />
            )}
          </div>
        </div>
      )}

      {toast && <Toast message={toast} onClose={() => setToast(null)} />}
    </>
  );
}

function TrainingNodeCard({
  node,
  onPlay,
}: {
  node: TrainingNode;
  onPlay: (node: TrainingNode) => void;
}) {
  const hasVideo = !!node.video?.url;
  const hasBody = !!node.body;

  if (hasVideo) {
    return (
      <article className="overflow-hidden rounded-2xl border border-line bg-surface shadow-panel lg:flex">
        {/*
          The play panel, not a poster frame. `ContentService::fileReference()`
          serialises a training video as `{url, name, mime, size}` — there is no
          thumbnail on the payload to show, and inventing one would mean shipping
          a placeholder image for every video in the library. A real poster frame
          belongs as a new field on the endpoint.

          Nodes whose video is an iframe in the body rather than an uploaded file
          never reach this branch: they render as the body card below, with the
          player inline. See `.body-html` in globals.css for how that is sized.
        */}
        <button
          type="button"
          onClick={() => onPlay(node)}
          className="group relative block aspect-video w-full shrink-0 overflow-hidden bg-linear-to-br from-brand-dark to-foreground focus:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2 focus-visible:ring-offset-surface lg:w-[22rem]"
          aria-label={`Watch video: ${node.title}`}
        >
          <span
            aria-hidden="true"
            className="absolute inset-0 opacity-30"
            style={{
              background:
                "radial-gradient(circle at 70% 20%, rgba(255,255,255,0.2), transparent 55%)",
            }}
          />
          <span className="absolute left-1/2 top-1/2 flex h-14 w-14 -translate-x-1/2 -translate-y-1/2 items-center justify-center rounded-full bg-white/95 text-primary shadow-lg transition group-hover:scale-110">
            <Play className="h-6 w-6 translate-x-0.5 fill-current" aria-hidden="true" />
          </span>
        </button>
        <div className="flex min-w-0 flex-1 flex-col p-5 sm:p-6">
          <h3 className="font-serif text-xl leading-snug">{node.title}</h3>
          {node.body && (
            <div
              className="body-html mt-2 text-sm leading-relaxed text-foreground/75"
              dangerouslySetInnerHTML={{ __html: node.body }}
            />
          )}
          <div className="mt-auto pt-4">
            <button
              type="button"
              onClick={() => onPlay(node)}
              className="inline-flex items-center gap-2 rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-primary-dark focus:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2"
            >
              <Play className="h-3.5 w-3.5" aria-hidden="true" />
              Watch video
            </button>
          </div>
        </div>
      </article>
    );
  }

  if (hasBody) {
    return (
      <article className="flex gap-4 rounded-2xl border border-primary/20 bg-primary-soft p-5 sm:p-6">
        <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-surface text-primary">
          <Info className="h-5 w-5" aria-hidden="true" />
        </span>
        <div className="min-w-0">
          <h3 className="font-serif text-xl leading-snug">{node.title}</h3>
          {node.body && (
            <div
              className="body-html mt-2 text-sm leading-relaxed text-foreground/75"
              dangerouslySetInnerHTML={{ __html: node.body }}
            />
          )}
        </div>
      </article>
    );
  }

  return (
    <article className="rounded-2xl border border-line bg-surface px-5 py-4 shadow-panel">
      <h3 className="font-serif text-lg">{node.title}</h3>
    </article>
  );
}
