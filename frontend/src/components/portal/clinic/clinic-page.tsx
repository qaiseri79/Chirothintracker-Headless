"use client";

import * as React from "react";
import { useAuth } from "@/lib/auth";
import { resolvePortalAccess } from "@/lib/portal";
import { cn } from "cn";
import { MapPin, Pencil, Plus, Search, Users } from "lucide-react";
import { Toast } from "@/components/portal/toast";
import { clinicRequest, ClinicRequestError } from "@/lib/clinic/client";
import type { ClinicSnapshot, Chiropractor, ClinicLocation } from "@/lib/clinic/types";
import { BlockAccessDialog } from "./block-access-dialog";
import {
  ClinicDrawer,
  type ChiropractorDraft,
  type DrawerMode,
  type LocationDraft,
} from "./clinic-drawer";
import {
  BTN,
  BTN_OUTLINE,
  BTN_PRIMARY,
  BTN_ROW,
  CARD,
  FIELD,
  ROW_HOVER,
  TABLE_HEAD,
} from "./clinic-primitives";

/** One tab's label, its heading and the sentence under it, as the design pairs them. */
const TABS = [
  {
    id: "chiropractors",
    label: "My Chiropractors",
    heading: "My Chiropractors",
    blurb: "Chiropractors and health coaches who can access your clinic.",
    icon: Users,
  },
  {
    id: "locations",
    label: "Clinic Locations",
    heading: "Clinic Locations",
    blurb: "The locations your team works from. Assign chiropractors to each one.",
    icon: MapPin,
  },
] as const;

type TabId = (typeof TABS)[number]["id"];

/**
 * Which locations the chiropractor table is showing.
 *
 * `"all"` and `"unassigned"` are sentinels because a location id is a number; the
 * design carries the same three states as `"all"`, a location title, and `"none"`.
 */
type LocationFilter = "all" | "unassigned" | number;

const EMPTY_DOCTORS: Chiropractor[] = [];
const EMPTY_LOCATIONS: ClinicLocation[] = [];

const ALL: LocationFilter = "all";
const UNASSIGNED: LocationFilter = "unassigned";

/** The design's `ini()`: first letters of the first two words, uppercase. */
function initials(name: string): string {
  return (
    name
      .split(/\s+/)
      .filter(Boolean)
      .slice(0, 2)
      .map((word) => word[0])
      .join("")
      .toUpperCase() || "?"
  );
}

export function ClinicPage({ initialData, initialError = null }: { initialData: ClinicSnapshot | null; initialError?: string | null }) {
  const { user, refresh } = useAuth();
  const [data, setData] = React.useState(initialData);
  const [error, setError] = React.useState<string | null>(initialError);
  const [busy, setBusy] = React.useState(false);
  const readOnly = resolvePortalAccess(user?.roles, user?.capabilities, user?.portalAccess)?.readOnly ?? true;
  const [tab, setTab] = React.useState<TabId>("chiropractors");
  const [query, setQuery] = React.useState("");
  const [locationFilter, setLocationFilter] =
    React.useState<LocationFilter>(ALL);

  const chiropractors = data?.chiropractors ?? EMPTY_DOCTORS;
  const locations = data?.locations ?? EMPTY_LOCATIONS;
  const clinics = data ? [data.clinic.name] : [];
  const canManage = !!data?.canManage && !readOnly;
  const disabled = !canManage || busy;

  /** Which form the drawer is showing, and the record it is editing. */
  const [drawer, setDrawer] = React.useState<{
    mode: DrawerMode;
    doctorId?: number;
    locationId: number | null;
  } | null>(null);
  const [pendingBlockId, setPendingBlockId] = React.useState<number | null>(
    null,
  );
  const [toastMessage, setToastMessage] = React.useState<string | null>(null);

  const tabRefs = React.useRef<Record<string, HTMLButtonElement | null>>({});

  const activeTab = TABS.find((candidate) => candidate.id === tab) ?? TABS[0];

  /** Titles are looked up rather than stored on the chiropractor, so a rename shows. */
  const titleOf = React.useCallback(
    (id: number | null) =>
      locations.find((location) => location.id === id)?.title ?? "",
    [locations],
  );

  const filtered = React.useMemo(() => {
    const needle = query.trim().toLowerCase();
    return chiropractors.filter((chiropractor) => {
      if (locationFilter === UNASSIGNED) {
        if (chiropractor.locationId !== null) return false;
      } else if (locationFilter !== ALL && chiropractor.locationId !== locationFilter) {
        return false;
      }
      if (!needle) return true;
      // The design searches name, username and email together with no separator.
      return `${chiropractor.name}${chiropractor.username}${chiropractor.email}`
        .toLowerCase()
        .includes(needle);
    });
  }, [chiropractors, query, locationFilter]);

  const activeCount = chiropractors.filter((c) => c.active).length;
  const unassignedCount = chiropractors.filter((c) => c.locationId === null)
    .length;

  // The design's `st`, which is a different set of three per tab: counts of people on
  // the team tab, counts of places on the locations tab.
  const stats: [string, number][] =
    tab === "chiropractors"
      ? [
          ["Total", chiropractors.length],
          ["Active", activeCount],
          ["Blocked", chiropractors.filter((c) => c.blocked).length],
        ]
      : [
          ["Locations", locations.length],
          ["Clinics", clinics.length],
          ["Unassigned chiropractors", unassignedCount],
        ];

  /**
   * Arrow keys move between tabs, Home and End jump to the ends, matching
   * `PatientsPage`.
   *
   * Selection follows focus because switching these panels is free — both are already
   * in memory — which is the recommended behaviour while a panel is cheap to show.
   */
  function handleKeyDown(event: React.KeyboardEvent<HTMLDivElement>) {
    const index = TABS.findIndex((candidate) => candidate.id === tab);
    let next: number;

    switch (event.key) {
      case "ArrowRight":
        next = (index + 1) % TABS.length;
        break;
      case "ArrowLeft":
        next = (index - 1 + TABS.length) % TABS.length;
        break;
      case "Home":
        next = 0;
        break;
      case "End":
        next = TABS.length - 1;
        break;
      default:
        return;
    }

    event.preventDefault();
    const id = TABS[next].id;
    setTab(id);
    tabRefs.current[id]?.focus();
  }

  async function loadClinic() {
    try { setData(await clinicRequest()); setError(null); }
    catch (failure) { setError(failure instanceof Error ? failure.message : "Clinic data is unavailable."); }
  }

  async function mutate(path: string, body: unknown, method = "POST") {
    if (!canManage || busy) throw new Error("Only the primary doctor with active access can make changes.");
    setBusy(true);
    setError(null);
    try {
      const next = await clinicRequest(path, body, method);
      setData(next);
      return next;
    } catch (failure) {
      if (failure instanceof ClinicRequestError && failure.status === 403) {
        await refresh().catch(() => null);
        await loadClinic();
      }
      throw failure;
    } finally { setBusy(false); }
  }

  async function handleAddChiropractor(draft: ChiropractorDraft) {
    const editing = drawer?.mode === "edit-chiropractor" ? drawer.doctorId : undefined;
    const next = await mutate(editing ? "doctors/" + editing : "doctors", draft, editing ? "PATCH" : "POST");
    setToastMessage(editing ? "Chiropractor updated" : next.notice ?? "Chiropractor created and invitation sent");
  }

  async function handleSaveLocation(draft: LocationDraft) {
    const id = drawer?.locationId;
    await mutate(id ? "locations/" + id : "locations", { title: draft.title }, id ? "PATCH" : "POST");
    setToastMessage(id ? "Location updated" : "Location added");
  }

  async function handleConfirmBlock() {
    if (disabled || pendingBlockId === null) return;
    const target = chiropractors.find((doctor) => doctor.id === pendingBlockId);
    if (!target) return;
    try {
      await mutate("doctors/" + target.id + "/access", { blocked: !target.blocked });
      setToastMessage(target.name + (target.blocked ? " unblocked" : " blocked"));
      setPendingBlockId(null);
    } catch (failure) {
      setError(failure instanceof Error ? failure.message : "The access change could not be completed.");
    }
  }

  const pendingTarget =
    chiropractors.find((chiropractor) => chiropractor.id === pendingBlockId) ??
    null;

  const editingLocation =
    drawer?.locationId === undefined || drawer?.locationId === null
      ? null
      : (locations.find((location) => location.id === drawer.locationId) ?? null);

  return (
    <div className="space-y-5">
      {error ? <div role="alert" className="rounded-xl border border-flame/30 bg-flame-soft p-4 text-sm">
        {error} <button type="button" className="ml-2 underline" onClick={() => void loadClinic()}>Retry</button>
      </div> : null}
      {data?.funding === "sponsored" ? <p className="rounded-xl border border-line bg-canvas p-3 text-sm">
        Your access is funded by {data.primary.name}. Only the primary doctor can manage the clinic team and locations.
      </p> : null}
      <div className="flex flex-wrap items-end justify-between gap-4">
        <div>
          <h2 className="font-serif text-2xl text-foreground">
            {activeTab.heading}
          </h2>
          <p className="mt-1 text-sm text-muted-foreground">{activeTab.blurb}</p>
        </div>
        <button
          type="button"
          disabled={disabled}
          title={!canManage ? "Only the primary doctor with active access can make changes." : undefined}
          onClick={() =>
            setDrawer({
              mode: tab === "chiropractors" ? "add-chiropractor" : "add-location",
              locationId: null,
            })
          }
          className={cn(BTN, BTN_PRIMARY)}
        >
          <Plus className="size-[18px] shrink-0" aria-hidden="true" />
          {tab === "chiropractors"
            ? "Add Chiropractor / Health Coach"
            : "Add Clinic Location"}
        </button>
      </div>

      <div
        role="tablist"
        aria-label="Clinic sections"
        onKeyDown={handleKeyDown}
        className={cn("inline-flex rounded-xl border border-line bg-surface p-1 shadow-panel")}
      >
        {TABS.map((candidate) => {
          const selected = candidate.id === tab;
          const Icon = candidate.icon;

          return (
            <button
              key={candidate.id}
              ref={(node) => {
                tabRefs.current[candidate.id] = node;
              }}
              type="button"
              role="tab"
              id={`clinic-tab-${candidate.id}`}
              aria-selected={selected}
              aria-controls="clinic-panel"
              // Roving tabindex: one stop for the whole bar.
              tabIndex={selected ? 0 : -1}
              onClick={() => setTab(candidate.id)}
              className={cn(
                BTN,
                "py-2",
                selected
                  ? "bg-primary text-white"
                  : "text-foreground/70 hover:bg-primary-soft hover:text-primary",
              )}
            >
              <Icon className="size-4 shrink-0" aria-hidden="true" />
              {candidate.label}
            </button>
          );
        })}
      </div>

      <div className="grid grid-cols-3 gap-3">
        {stats.map(([label, value]) => (
          <div key={label} className={cn(CARD, "px-4 py-3")}>
            <p className="font-serif text-2xl leading-none text-foreground">
              {value}
            </p>
            <p className="mt-1.5 text-[11px] font-medium tracking-wide text-muted-foreground uppercase">
              {label}
            </p>
          </div>
        ))}
      </div>

      <div
        role="tabpanel"
        id="clinic-panel"
        aria-labelledby={`clinic-tab-${tab}`}
        tabIndex={0}
      >
        {tab === "chiropractors" ? (
          <div className={cn(CARD, "overflow-hidden")}>
            <div className="flex flex-col gap-3 border-b border-line p-4 sm:flex-row">
              <div className="relative flex-1">
                <Search
                  className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                  aria-hidden="true"
                />
                <input
                  type="search"
                  value={query}
                  onChange={(event) => setQuery(event.target.value)}
                  placeholder="Search name, username or email"
                  aria-label="Search chiropractors"
                  className={cn(FIELD, "pl-9")}
                />
              </div>
              <select
                value={
                  locationFilter === UNASSIGNED
                    ? "unassigned"
                    : String(locationFilter)
                }
                onChange={(event) => {
                  const next = event.target.value;
                  setLocationFilter(
                    next === "unassigned"
                      ? UNASSIGNED
                      : next === "all"
                        ? ALL
                        : Number(next),
                  );
                }}
                aria-label="Filter by clinic location"
                className={cn(FIELD, "w-full sm:w-56")}
              >
                <option value="all">All locations</option>
                {locations.map((location) => (
                  <option key={location.id} value={location.id}>
                    {location.title}
                  </option>
                ))}
                <option value="unassigned">Unassigned</option>
              </select>
            </div>

            {filtered.length > 0 ? (
              <div className="overflow-x-auto">
                <table className="w-full text-left text-sm">
                  <caption className="sr-only">
                    Chiropractors and health coaches with access to your clinic
                  </caption>
                  <thead className={TABLE_HEAD}>
                    <tr>
                      <th scope="col" className="px-5 py-3 font-semibold">
                        Chiropractor
                      </th>
                      <th
                        scope="col"
                        className="hidden px-3 py-3 font-semibold md:table-cell"
                      >
                        User name
                      </th>
                      <th
                        scope="col"
                        className="hidden px-3 py-3 font-semibold sm:table-cell"
                      >
                        Clinic location
                      </th>
                      <th scope="col" className="px-3 py-3 font-semibold">
                        Status
                      </th>
                      <th scope="col" className="relative px-5 py-3">
                        <span className="sr-only">Actions</span>
                      </th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-line">
                    {filtered.map((chiropractor) => {
                      const locationTitle = titleOf(chiropractor.locationId);

                      return (
                        <tr
                          key={chiropractor.id}
                          className={cn(
                            ROW_HOVER,
                            chiropractor.blocked && "opacity-70",
                          )}
                        >
                          <td className="px-5 py-3.5">
                            <div className="flex items-center gap-3">
                              <span
                                className={cn(
                                  "flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-xs font-semibold",
                                  chiropractor.blocked
                                    ? "bg-line text-muted-foreground"
                                    : "bg-primary-soft text-primary",
                                )}
                                aria-hidden="true"
                              >
                                {initials(chiropractor.name)}
                              </span>
                              <div className="min-w-0">
                                <p className="truncate font-medium text-foreground">
                                  {chiropractor.name}
                                </p>
                                <p className="truncate text-xs text-muted-foreground">
                                  {chiropractor.email}
                                </p>
                              </div>
                            </div>
                          </td>
                          <td className="hidden px-3 py-3.5 text-foreground/70 md:table-cell">
                            {chiropractor.username}
                          </td>
                          <td className="hidden px-3 py-3.5 sm:table-cell">
                            {locationTitle ? (
                              <span className="inline-flex items-center gap-1.5 text-foreground/80">
                                <MapPin
                                  className="size-3.5 shrink-0 text-primary"
                                  aria-hidden="true"
                                />
                                {locationTitle}
                              </span>
                            ) : (
                              <span className="rounded-full bg-canvas px-2.5 py-1 text-xs text-muted-foreground">
                                Unassigned
                              </span>
                            )}
                          </td>
                          <td className="px-3 py-3.5">
                            <span
                              className={cn(
                                "rounded-full px-2.5 py-1 text-xs font-semibold",
                                chiropractor.blocked
                                  ? "bg-flame-soft text-flame"
                                  : chiropractor.active ? "bg-primary-soft text-primary" : "bg-amber-50 text-amber-900",
                              )}
                            >
                              {chiropractor.blocked ? "Blocked" : chiropractor.active ? "Active" : "Inactive"}
                            </span>
                          </td>
                          <td className="px-5 py-3.5 text-right">
                            <button type="button" disabled={disabled} className={cn(BTN_ROW, "mr-2 border-primary/30 text-primary hover:bg-primary-soft")}
                              onClick={() => setDrawer({ mode: "edit-chiropractor", doctorId: chiropractor.id, locationId: null })}>
                              Edit
                            </button>
                            <button
                              type="button"
                              disabled={disabled}
                              onClick={() =>
                                setPendingBlockId(chiropractor.id)
                              }
                              className={cn(
                                BTN_ROW,
                                chiropractor.blocked
                                  ? "border-primary/30 text-primary hover:bg-primary-soft"
                                  : "border-flame/30 text-flame hover:bg-flame-soft",
                              )}
                            >
                              {chiropractor.blocked ? "Unblock" : "Block"}
                            </button>
                          </td>
                        </tr>
                      );
                    })}
                  </tbody>
                </table>
              </div>
            ) : (
              <div className="px-6 py-14 text-center">
                <span
                  className="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-primary-soft text-primary"
                  aria-hidden="true"
                >
                  <Users className="size-6" />
                </span>
                <p className="mt-3 font-serif text-lg text-foreground">
                  No chiropractors found
                </p>
                <p className="text-sm text-muted-foreground">
                  Try a different search or location filter.
                </p>
              </div>
            )}
          </div>
        ) : (
          <div className={cn(CARD, "overflow-hidden")}>
            {locations.length > 0 ? (
              <div className="overflow-x-auto">
                <table className="w-full text-left text-sm">
                  <caption className="sr-only">
                    Clinic locations your team works from
                  </caption>
                  <thead className={TABLE_HEAD}>
                    <tr>
                      <th scope="col" className="px-5 py-3 font-semibold">
                        Clinic location
                      </th>
                      <th
                        scope="col"
                        className="hidden px-3 py-3 font-semibold md:table-cell"
                      >
                        Clinic
                      </th>
                      <th scope="col" className="px-3 py-3 font-semibold">
                        Chiropractors
                      </th>
                      <th
                        scope="col"
                        className="hidden px-3 py-3 font-semibold sm:table-cell"
                      >
                        Authored on
                      </th>
                      <th scope="col" className="relative px-5 py-3">
                        <span className="sr-only">Actions</span>
                      </th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-line">
                    {locations.map((location) => {
                      const assigned = chiropractors.filter(
                        (chiropractor) =>
                          chiropractor.locationId === location.id,
                      ).length;

                      return (
                        <tr key={location.id} className={ROW_HOVER}>
                          <td className="px-5 py-3.5">
                            <div className="flex items-center gap-3">
                              <span
                                className="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-primary-soft text-primary"
                                aria-hidden="true"
                              >
                                <MapPin className="size-4" />
                              </span>
                              <p className="font-medium text-foreground">
                                {location.title}
                              </p>
                            </div>
                          </td>
                          <td className="hidden px-3 py-3.5 text-foreground/70 md:table-cell">
                            {location.clinic}
                          </td>
                          <td className="px-3 py-3.5">
                            <span className="rounded-full bg-canvas px-2.5 py-1 text-xs font-medium text-foreground">
                              {assigned}{" "}
                              {assigned === 1 ? "chiropractor" : "chiropractors"}
                            </span>
                          </td>
                          <td className="hidden px-3 py-3.5 text-foreground/70 sm:table-cell">
                            {location.authoredOn}
                          </td>
                          <td className="px-5 py-3.5 text-right">
                            <button
                              type="button"
                              disabled={disabled}
                              onClick={() =>
                                setDrawer({
                                  mode: "edit-location",
                                  locationId: location.id,
                                })
                              }
                              className={cn(
                                BTN_ROW,
                                BTN_OUTLINE,
                                "hover:border-primary hover:bg-primary-soft hover:text-primary",
                              )}
                            >
                              <Pencil
                                className="size-3.5 shrink-0"
                                aria-hidden="true"
                              />
                              Edit
                            </button>
                          </td>
                        </tr>
                      );
                    })}
                  </tbody>
                </table>
              </div>
            ) : (
              <div className="px-6 py-14 text-center">
                <p className="font-serif text-lg text-foreground">
                  No locations yet
                </p>
                <p className="text-sm text-muted-foreground">
                  Add your first clinic location to get started.
                </p>
              </div>
            )}
          </div>
        )}
      </div>

      {drawer && canManage ? (
        <ClinicDrawer
          mode={drawer.mode}
          clinics={clinics}
          locations={locations}
          location={editingLocation}
          chiropractor={drawer.doctorId ? chiropractors.find((doctor) => doctor.id === drawer.doctorId) ?? null : null}
          busy={busy}
          onClose={() => { if (!busy) setDrawer(null); }}
          onAddChiropractor={handleAddChiropractor}
          onSaveLocation={handleSaveLocation}
        />
      ) : null}

      <BlockAccessDialog
        chiropractor={!canManage ? null : pendingTarget?.name ?? null}
        blocked={pendingTarget?.blocked ?? false}
        onConfirm={() => void handleConfirmBlock()}
        busy={busy}
        onClose={() => { if (!busy) setPendingBlockId(null); }}
      />

      {toastMessage ? (
        <Toast
          message={toastMessage}
          onClose={() => setToastMessage(null)}
        />
      ) : null}
    </div>
  );
}