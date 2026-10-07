"use client";

import { useCallback, useMemo, useState } from "react";
import { useRouter } from "next/navigation";
import { Search, X, FileText, Eye, Download, FileWarning, FolderOpen } from "lucide-react";
import { AddResourceDialog } from "@/components/portal/resources/add-resource-dialog";
import { Toast } from "@/components/portal/toast";
import type { ResourceNode, ResourceOptions } from "@/lib/resources/types";

interface ResourcesPageProps {
  /**
   * The rows the server managed to read. Empty when the read failed, in which case
   * `error` carries the reason.
   *
   * Either the rows arrived or `error` is set — the two are not independent, and the
   * page has to be able to say which, because "this clinic has no resources" and
   * "the backend is down" are different facts that happen to render the same list
   * otherwise.
   */
  nodes: ResourceNode[];
  error?: string | null;
  /**
   * Whether the backend would admit a resource create for this caller. The "Add
   * resource" button shows only for active chiropractors; patients and inactive
   * doctors never see it because the endpoint would refuse them.
   */
  canCreate?: boolean;
  /**
   * The form's category options, fetched server-side for the caller who can
   * create. `null` when they failed to load — the dialog then offers a retry
   * instead of rendering an empty form.
   */
  resourceOptions?: ResourceOptions | null;
}

export function ResourcesPage({
  nodes,
  error,
  canCreate = false,
  resourceOptions = null,
}: ResourcesPageProps) {
  const router = useRouter();
  const [resources, setResources] = useState<ResourceNode[]>(nodes);
  const [searchQuery, setSearchQuery] = useState("");
  const [selectedCategory, setSelectedCategory] = useState("");
  const [activeResource, setActiveResource] = useState<ResourceNode | null>(null);
  const [addOpen, setAddOpen] = useState(false);
  const [toast, setToast] = useState<string | null>(null);

  /**
   * A newly created resource lists immediately, above everything else — the
   * freshest row and the one the doctor is looking for — with no reload and at
   * the top of whichever group it lands in.
   */
  const handleResourceCreated = useCallback((resource: ResourceNode) => {
    setResources((prev) => [resource, ...prev]);
    setToast("Resource published");
  }, []);

  const categories = useMemo(() => {
    const cats = new Set(resources.map((n) => n.group));
    return Array.from(cats).sort();
  }, [resources]);

  const filtered = useMemo(() => {
    let result = resources;
    if (searchQuery) {
      const q = searchQuery.toLowerCase();
      // The description is searched alongside the visible fields because it is the
      // one piece of prose a user would expect a search box to reach: 64 of the 138
      // published resources carry one, and several read as summaries rather than
      // restatements of the title ("Some dieters feel different changes when
      // starting the diet"), so it is often the only field that matches.
      result = result.filter(
        (n) =>
          n.title.toLowerCase().includes(q) ||
          n.file.toLowerCase().includes(q) ||
          n.group.toLowerCase().includes(q) ||
          n.description.toLowerCase().includes(q),
      );
    }
    if (selectedCategory) {
      result = result.filter((n) => n.group === selectedCategory);
    }
    return result;
  }, [resources, searchQuery, selectedCategory]);

  const grouped = useMemo(() => {
    const groups: Record<string, ResourceNode[]> = {};
    for (const node of filtered) {
      (groups[node.group] = groups[node.group] || []).push(node);
    }
    return groups;
  }, [filtered]);

  const groupKeys = useMemo(() => Object.keys(grouped).sort(), [grouped]);

  // Escape and backdrop click. Without this a keyboard user who opens the viewer has
  // no way to close it, and the modal traps nothing — Tab walks straight out into the
  // page behind it.
  const handleCloseModal = useCallback(() => {
    setActiveResource(null);
  }, []);

  const handleKeyDown = useCallback(
    (event: React.KeyboardEvent) => {
      if (event.key === "Escape") handleCloseModal();
    },
    [handleCloseModal],
  );

  const header = (
    <div className="flex flex-wrap items-start justify-between gap-3">
      <div>
        <h1 className="font-serif text-2xl text-foreground">Resources</h1>
        <p className="mt-1 text-sm text-muted-foreground">
          Guides, manuals, and reference documents for your program.
        </p>
      </div>
      {canCreate && (
        <button
          type="button"
          onClick={() => setAddOpen(true)}
          className="inline-flex items-center justify-center gap-2 rounded-xl px-4 py-2.5 text-sm font-semibold transition-colors disabled:opacity-40 bg-primary text-white shadow-sm hover:bg-brand-dark"
        >
          <FolderOpen className="size-[18px] shrink-0" aria-hidden="true" />
          Add resource
        </button>
      )}
    </div>
  );

  // A failed read is rendered as an error, not as an empty library. Both render a
  // list-shaped page, and showing "No resources available" when the backend is down
  // tells a user their clinic has no documents, which is a different and wrong fact.
  if (error) {
    return (
      <div className="space-y-4">
        {header}
        <div
          role="alert"
          className="rounded-xl border border-border bg-surface p-5 shadow-panel"
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
          <AddResourceDialog
            open={addOpen}
            onOpenChange={setAddOpen}
            options={resourceOptions}
            onCreated={handleResourceCreated}
            onAuthError={() => router.replace("/login")}
            onRetry={() => router.refresh()}
          />
        )}

        {/* Search & Filter Bar
            Laid out from "New Design/ChiroThin — Resources.html": a 3-column grid
            where the search field spans 2 and the category select spans 1, both with
            a visible label above the control. The label on the search field is the
            load-bearing part — as an `sr-only` label it took no vertical space, so
            the search input sat at the top of its cell while the labelled Category
            select was pushed down, and the two controls did not line up. That is
            the same bar as the Recipes design, and the same as the shipped recipes
            page, so the two filter bars stay consistent with each other. */}
        {resources.length > 0 && (
          <div className="rounded-xl border border-line bg-surface p-5 shadow-panel">
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
              <div className="sm:col-span-2">
                <label
                  htmlFor="resource-search"
                  className="mb-1.5 block text-xs font-medium text-muted-foreground"
                >
                  Search Resources
                </label>
                <div className="relative">
                  <Search
                    className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground"
                    aria-hidden="true"
                  />
                  <input
                    id="resource-search"
                    type="text"
                    placeholder="Search documents…"
                    value={searchQuery}
                    onChange={(e) => setSearchQuery(e.target.value)}
                    className="w-full rounded-lg border border-line bg-canvas py-2.5 pl-9 pr-3.5 text-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                  />
                </div>
              </div>
              <div>
                <label
                  htmlFor="resource-category"
                  className="mb-1.5 block text-xs font-medium text-muted-foreground"
                >
                  Category
                </label>
                <select
                  id="resource-category"
                  value={selectedCategory}
                  onChange={(e) => setSelectedCategory(e.target.value)}
                  className="w-full rounded-lg border border-line bg-canvas px-3.5 py-2.5 text-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                >
                  <option value="">- Any -</option>
                  {categories.map((c) => (
                    <option key={c} value={c}>
                      {c}
                    </option>
                  ))}
                </select>
              </div>
            </div>
          </div>
        )}

        {/* Resource Groups
            The design draws the empty state as a bare centred line rather than a
            bordered panel with an icon, so a page with no matches reads as "nothing
            here" rather than as a card. The two cases are kept apart because they
            are different facts: an empty library means nothing has been published
            for this clinic, a filtered-empty one means the search was too narrow. */}
        {groupKeys.length === 0 ? (
          <p className="py-10 text-center text-sm text-muted-foreground">
            {resources.length === 0
              ? "No resources have been published for your clinic yet."
              : "No resources match your search."}
          </p>
        ) : (
          <div className="space-y-8">
            {groupKeys.map((group) => (
              <div key={group}>
                <h2 className="mb-3 font-serif text-xl text-foreground">{group}</h2>
                <div className="space-y-2">
                  {grouped[group].map((node) => (
                    <ResourceRow key={node.id} node={node} onView={setActiveResource} />
                  ))}
                </div>
              </div>
            ))}
          </div>
        )}
      </div>

      {/* Viewer Modal */}
      {activeResource && (
        <div
          className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4"
          onClick={handleCloseModal}
          onKeyDown={handleKeyDown}
        >
          <div
            role="dialog"
            aria-modal="true"
            aria-label={activeResource.title}
            className="w-full max-w-4xl rounded-2xl bg-surface shadow-panel overflow-hidden"
            onClick={(e) => e.stopPropagation()}
          >
            <div className="flex items-center justify-between border-b border-border p-4">
              <div className="min-w-0">
                <h2 className="font-serif text-lg text-foreground truncate">
                  {activeResource.title}
                </h2>
                <p className="text-xs text-muted-foreground truncate">
                  {/* The filename and size are absent on the 12 rows with no file, so
                      the separator is only rendered when there is something either
                      side of it. */}
                  {[activeResource.file || activeResource.size || null]
                    .filter(Boolean)
                    .join(" • ") || "No file attached"}
                </p>
              </div>
              <div className="flex items-center gap-2">
                {/* Gated on hasFile: these 12 rows have `url: ""`, and an anchor
                    with an empty href is a link back to this page. */}
                {activeResource.hasFile && (
                  <a
                    href={activeResource.url}
                    download={activeResource.file || undefined}
                    className="inline-flex items-center gap-1.5 rounded-lg bg-primary px-3 py-1.5 text-sm font-medium text-white hover:bg-primary-dark"
                  >
                    <Download className="h-4 w-4" aria-hidden="true" />
                    Download
                  </a>
                )}
                <button
                  onClick={handleCloseModal}
                  aria-label="Close"
                  className="flex h-8 w-8 items-center justify-center rounded-lg text-muted-foreground hover:bg-canvas"
                >
                  <X className="h-4 w-4" aria-hidden="true" />
                </button>
              </div>
            </div>

            <div className="h-[60vh] min-h-[400px] bg-canvas">
              <ResourcePreviewPanel node={activeResource} />
            </div>

            {activeResource.description && (
              <div className="max-h-40 overflow-y-auto border-t border-border p-4">
                <p className="whitespace-pre-wrap text-sm text-muted-foreground">
                  {activeResource.description}
                </p>
              </div>
            )}
          </div>
        </div>
      )}

      {toast && <Toast message={toast} onClose={() => setToast(null)} />}
    </>
  );
}

/**
 * The modal body, chosen from the file's MIME type.
 *
 * Split out because the four cases are genuinely different elements, and the
 * decision of which one is `ResourceNode.preview` — made in the mapper, from the
 * MIME type Drupal reports, rather than re-guessed from the filename here.
 */
function ResourcePreviewPanel({ node }: { node: ResourceNode }) {
  if (!node.hasFile) {
    return (
      <div className="flex h-full items-center justify-center text-muted-foreground">
        <div className="text-center">
          <FileWarning className="mx-auto h-16 w-16 text-muted-foreground/50" aria-hidden="true" />
          <p className="mt-4 text-lg">No file attached</p>
          <p className="mt-1 text-sm">
            This resource is published without a downloadable file. Ask your clinic
            for the document.
          </p>
        </div>
      </div>
    );
  }

  if (node.preview === "image") {
    return (
      <div className="flex h-full items-center justify-center p-4">
        {/* eslint-disable-next-line @next/next/no-img-element */}
        <img
          src={node.url}
          alt={node.title}
          className="max-h-full max-w-full object-contain"
        />
      </div>
    );
  }

  if (node.preview === "video") {
    return (
      <div className="flex h-full items-center justify-center bg-black">
        {/* The file is served from the Drupal origin, so the controls and the stream
            are both same-origin. */}
        <video src={node.url} controls className="max-h-full max-w-full">
          Your browser cannot play this video.
        </video>
      </div>
    );
  }

  if (node.preview === "pdf") {
    return (
      <iframe
        src={node.url}
        className="h-full w-full border-0"
        title={`${node.title} preview`}
      />
    );
  }

  // Word and Excel files. The browser cannot display them, so the only honest
  // action is a download — which is why `preview: "none"` is a real case rather
  // than a failure.
  return (
    <div className="flex h-full items-center justify-center text-muted-foreground">
      <div className="text-center">
        <FileText className="mx-auto h-16 w-16 text-muted-foreground/50" aria-hidden="true" />
        <p className="mt-4 text-lg">Preview not available</p>
        <p className="mt-1 text-sm">
          {node.mime
            ? `A ${node.mime} file cannot be previewed in the browser.`
            : "This document cannot be previewed in the browser."}
        </p>
        <a
          href={node.url}
          download={node.file || undefined}
          className="mt-4 inline-flex items-center gap-1.5 rounded-lg bg-primary px-4 py-2 text-sm font-medium text-white hover:bg-primary-dark"
        >
          <Download className="h-4 w-4" aria-hidden="true" />
          Download to view
        </a>
      </div>
    </div>
  );
}

function ResourceRow({
  node,
  onView,
}: {
  node: ResourceNode;
  onView: (node: ResourceNode) => void;
}) {
  return (
    <div className="flex items-center gap-4 rounded-xl border border-line bg-surface px-5 py-4 shadow-panel">
      <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-flame-soft text-flame">
        <FileText className="h-5 w-5" aria-hidden="true" />
      </div>

      <div className="min-w-0 flex-1">
        <p className="truncate font-medium text-foreground">{node.title}</p>
        {/* A row with no file says so, rather than showing a blank line where the
            filename would be. */}
        <p className="truncate text-xs text-muted-foreground">
          {node.file || "No file attached"}
        </p>
      </div>

      <span className="hidden shrink-0 text-xs text-muted-foreground sm:block">
        {node.size}
      </span>

      <div className="flex shrink-0 items-center gap-1">
        <button
          onClick={() => onView(node)}
          title="View"
          aria-label={`View ${node.title}`}
          className="rounded-lg p-2 text-muted-foreground transition-colors hover:bg-primary-soft hover:text-primary"
        >
          <Eye className="h-4 w-4" aria-hidden="true" />
        </button>
        {/* A row with no file has nothing to download, so the button is absent
            instead of pointing at an empty href. */}
        {node.hasFile && (
          <a
            href={node.url}
            download={node.file || undefined}
            title="Download"
            aria-label={`Download ${node.title}`}
            className="rounded-lg p-2 text-muted-foreground transition-colors hover:bg-primary-soft hover:text-primary"
          >
            <Download className="h-4 w-4" aria-hidden="true" />
          </a>
        )}
      </div>
    </div>
  );
}
