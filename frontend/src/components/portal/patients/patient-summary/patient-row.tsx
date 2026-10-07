"use client";

import { useState } from "react";
import { createPortal } from "react-dom";
import {
  Camera,
  ChevronDown,
  Clipboard,
  Clock,
  Download,
  Eye,
  Mail,
  MoreVertical,
  Pencil,
  RefreshCw,
} from "lucide-react";
import { formatSummaryNumber, type PatientSummaryRow } from "@/lib/patients/summary";
import { useSummaryData } from "./summary-data-provider";
import { PatientExpandedDetails } from "./patient-expanded-details";
import { UpdatePhotosDialog } from "./update-photos-dialog";
import { EditAccountDialog } from "./edit-account-dialog";

/**
 * The phase menu's options, from the design's `phaseMenu`.
 *
 * The design lists every phase except the patient's current one; the spec
 * fixes this list, so it is written out instead.
 */
/** The action menu's options, from the design's `moreMenu`. */
const ACTION_ITEMS = [
  { label: "Edit account", icon: Pencil },
  { label: "Update photos", icon: Camera },
  { label: "Open full details", icon: Clipboard },
  { label: "View intake", icon: Clipboard },
  { label: "Log patient progress", icon: Clock },
  { label: "Refresh data", icon: RefreshCw },
  { label: "Resend welcome e-mail", icon: Mail },
  { label: "Download messages", icon: Download },
  { label: "Download logs", icon: Download },
];

/**
 * One roster row, plus the expanded cell beneath it.
 *
 * Transcribed from the design's `roster()` row: avatar, name and the
 * "Day N · Started MM/DD/YYYY" subtext, the same six columns with the same
 * responsive visibility, the same program badges, the same review pill. The
 * design's `accent`/`success` are this app's `flame`/`positive` — the same
 * substitution `lib/patients/types.ts` documents — so the pills use the tokens
 * the theme actually configures.
 *
 * Two cells hold dropdowns, both custom (the design's `menu()` popovers):
 * the phase cell, which changes the patient's phase, and the trailing actions
 * cell. One `openMenu` state serves both, so only a single menu — and a single
 * outside-click backdrop — is ever open per row. The backdrop is portaled to
 * `document.body` so it never lands inside the table's DOM.
 *
 * The table controls expansion so only one patient's detail card is visible.
 * The open row takes the design's `bg-primary-soft/60` instead of the hover
 * fill, and its chevron turns to point up.
 */
export function PatientRow({
  phaseOptions,
  locations,
  statuses,
  patient,
  isExpanded,
  onToggleExpansion,
  onPhaseChange,
  onReviewToggle,
  onUpdateLastSeen,
  onOpenWorkspace,
  showToast,
}: {
  phaseOptions: string[];
  locations: Array<{ id: number; name: string }>;
  statuses: Array<{ id: number; name: string }>;
  patient: PatientSummaryRow;
  isExpanded: boolean;
  onToggleExpansion: () => void;
  onPhaseChange: (phase: string) => void;
  onReviewToggle: (id: number) => void;
  onUpdateLastSeen: (id: number, lastSeen: string) => Promise<void>;
  onOpenWorkspace: (tab: string) => void;
  showToast?: (message: string) => void;
}) {
  const { readOnly, pending, load, refresh } = useSummaryData();
  const [openMenu, setOpenMenu] = useState<"actions" | "phase" | null>(null);
  const [photosOpen, setPhotosOpen] = useState(false);
  const [accountOpen, setAccountOpen] = useState(false);

  return (
    <>
      {openMenu ? createPortal(
        <div
          className="fixed inset-0 z-40"
          onClick={() => setOpenMenu(null)}
          aria-hidden="true"
        />,
        document.body,
      ) : null}
      <tr
        onClick={() => { if (!isExpanded) { void load(patient.id, "sessions"); void load(patient.id, "logs"); } onToggleExpansion(); }}
        className={`cursor-pointer border-b border-line ${
          isExpanded ? "bg-primary-soft/60" : "hover:bg-canvas"
        }`}
      >
        <td className="px-5 py-3">
          <div className="flex items-center gap-3">
            <div className="relative shrink-0">
              {patient.avatar ? <img
                src={patient.avatar}
                alt=""
                className="h-9 w-9 rounded-full border border-line object-cover"
              /> : <span className="flex h-9 w-9 items-center justify-center rounded-full bg-primary-soft text-xs font-semibold text-primary">{patient.name.slice(0, 2).toUpperCase()}</span>}
              <button
                type="button"
                title="Update photos"
                onClick={(event) => {
                  event.stopPropagation();
                  if (readOnly) {
                    showToast?.("This account has read-only access.");
                    return;
                  }
                  setPhotosOpen(true);
                }}
                className="absolute -bottom-1 -right-1 flex h-5 w-5 items-center justify-center rounded-full border border-line bg-surface text-[#6B7280] shadow hover:text-primary"
              >
                <Camera className="h-3 w-3" aria-hidden="true" />
              </button>
            </div>
            <div>
              <p className="flex items-center gap-2 font-medium leading-tight">
                {patient.name}
              </p>
              <p className="text-xs text-[#6B7280]">
                Day {patient.day} · Started {patient.startDate}
              </p>
            </div>
          </div>
        </td>
        <td className="hidden px-3 py-3 xl:table-cell">
          <div className="relative">
            <button
              type="button"
              onClick={(e) => {
                // Stopped from bubbling: opening the phase menu is not the
                // same gesture as expanding the row.
                e.stopPropagation();
                setOpenMenu((current) => (current === "phase" ? null : "phase"));
              }}
              disabled={readOnly || pending[patient.id]}
              aria-haspopup="listbox"
              aria-expanded={openMenu === "phase"}
              className="inline-flex max-w-full items-center gap-1 rounded-md border border-line bg-surface px-2.5 py-1 text-xs font-medium hover:border-[#0B5D52]"
            >
              <span className="truncate">{patient.phase}</span>
              <ChevronDown className="h-3 w-3 shrink-0" />
            </button>
            {openMenu === "phase" ? (
              <div
                className="absolute z-50 w-56 rounded-xl border border-line bg-surface p-1.5 shadow-xl"
                role="listbox"
              >
                {phaseOptions.map((phase) => (
                  <button
                    key={phase}
                    type="button"
                    role="option"
                    aria-selected={patient.phase === phase}
                    onClick={() => {
                      onPhaseChange(phase);
                      setOpenMenu(null);
                    }}
                    className="flex w-full disabled:opacity-40 items-center gap-2.5 rounded-lg px-3 py-2 text-left text-sm font-medium hover:bg-[#E4EEEC]"
                  >
                    Set {phase}
                  </button>
                ))}
              </div>
            ) : null}
          </div>
        </td>
        <td className="hidden px-3 py-3 md:table-cell">
          <span
            className={`inline-block max-w-full truncate whitespace-nowrap rounded-md px-2.5 py-1 text-xs font-semibold ${
              patient.program === "Red Light + Weight Loss"
                ? "bg-flame text-white"
                : "bg-primary text-white"
            }`}
          >
            {patient.program}
          </span>
        </td>
        <td className="hidden px-3 py-3 sm:table-cell">
          <div className="flex w-36 items-center gap-2">
            <div className="h-2 flex-1 overflow-hidden rounded-full bg-line">
              <div
                className="h-full rounded-full bg-primary"
                style={{ width: `${Math.min(100, Math.max(0, patient.percentOfGoal ?? 0))}%` }}
              />
            </div>
            <span className="w-11 text-right text-xs font-medium tabular-nums">
              {formatSummaryNumber(patient.percentOfGoal, 0, "%")}
            </span>
          </div>
        </td>
        <td className="hidden px-3 py-3 text-right font-medium text-positive md:table-cell">
          {formatSummaryNumber(patient.netLoss, 1, "lbs")}
        </td>
        <td className="px-3 py-3">
          <button
            type="button"
            onClick={(e) => {
              e.stopPropagation();
              if (pending[patient.id]) return;
              onReviewToggle(patient.id);
            }}
            disabled={readOnly}
            aria-disabled={pending[patient.id] || readOnly}
            className={`inline-flex cursor-pointer items-center gap-1 rounded-full px-2.5 py-1 text-xs font-semibold ${
              patient.review === "New"
                ? "bg-[#F6E4DC] text-[#A8421F]"
                : "bg-[#E4F0E9] text-[#3D8361]"
            }`}
          >
            <Eye className="size-3" aria-hidden="true" />
            {patient.review}
          </button>
        </td>
        <td className="px-3 py-3">
          <div className="relative flex items-center justify-end gap-1">
            {/* Stopped from bubbling: the dots open the patient's action menu,
                which is a different job from expanding the row. */}
            <button
              type="button"
              onClick={(event) => {
                event.stopPropagation();
                setOpenMenu((current) => (current === "actions" ? null : "actions"));
              }}
              className="rounded-md p-1.5 text-[#6B7280] hover:bg-white hover:text-[#101827]"
              aria-label="Patient actions"
              aria-haspopup="menu"
              aria-expanded={openMenu === "actions"}
            >
              <MoreVertical className="size-4" />
            </button>
            <span
              className={`text-[#6B7280] transition-transform ${
                isExpanded ? "rotate-180" : ""
              }`}
            >
              <ChevronDown className="size-4" />
            </span>
            {openMenu === "actions" ? (
              <div
                className="absolute z-50 w-64 rounded-xl border border-line bg-surface p-1.5 shadow-xl right-10"
                role="menu"
              >
                {ACTION_ITEMS.map((item) => (
                  <button
                    key={item.label}
                    type="button"
                    role="menuitem"
                    disabled={!["Open full details", "View intake", "Refresh data", "Update photos", "Edit account"].includes(item.label)}
                    onClick={() => {
                      if (item.label === "Open full details") {
                        onOpenWorkspace("progress");
                      }
                      if (item.label === "View intake") onOpenWorkspace("intake");
                      if (item.label === "Refresh data") void refresh(patient.id).catch((error) => showToast?.(error instanceof Error ? error.message : "Unable to refresh patient."));
                      if (item.label === "Update photos") {
                        if (readOnly) showToast?.("This account has read-only access.");
                        else setPhotosOpen(true);
                      }
                      if (item.label === "Edit account") {
                        if (readOnly) showToast?.("This account has read-only access.");
                        else setAccountOpen(true);
                      }
                      setOpenMenu(null);
                    }}
                    className="flex w-full items-center gap-2.5 rounded-lg px-3 py-2 text-left text-sm font-medium hover:bg-[#E4EEEC] disabled:opacity-40"
                  >
                    <item.icon className="size-4" aria-hidden="true" />
                    {item.label}
                  </button>
                ))}
              </div>
            ) : null}
          </div>
        </td>
      </tr>
      {isExpanded ? (
        <tr className="border-b border-line bg-canvas">
          <td colSpan={7} className="min-w-0 overflow-hidden p-4 lg:p-5">
            <PatientExpandedDetails
              patient={patient}
              onUpdateLastSeen={onUpdateLastSeen}
              onOpenWorkspace={onOpenWorkspace}
            />
          </td>
        </tr>
      ) : null}
      {photosOpen ? <UpdatePhotosDialog patient={patient} onClose={() => setPhotosOpen(false)} showToast={showToast} /> : null}
      {accountOpen ? <EditAccountDialog patient={patient} locations={locations} statuses={statuses} onClose={() => setAccountOpen(false)} showToast={showToast} /> : null}
    </>
  );
}
