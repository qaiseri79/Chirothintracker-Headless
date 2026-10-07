import {
  BookOpen,
  Dumbbell,
  FolderOpen,
  GraduationCap,
  HelpCircle,
  House,
  MessageSquare,
  MoreHorizontal,
  Scale,
  ShoppingCart,
  TrendingUp,
  Users,
  Utensils,
  Wrench,
  Clipboard,
  CreditCard,
} from "lucide-react";
import type { LucideIcon } from "lucide-react";
import type { PortalAudience } from "@/lib/portal";

/**
 * The signed-in sidebar, per audience.
 *
 * Ported from "New Design/ChiroThin — My Progress.html", which is the first
 * design to include the app shell (collapsing sidebar + sticky header). That
 * design only covers the patient's "My Progress" page, so the chiropractor list
 * is a judgement call: it keeps the same shape and the same slots the patient
 * list uses — home, a people slot, a diary slot, resources, shop, extras — but
 * drops the patient-only items (Recipes, Training, Log My Weight, My Progress)
 * that mean nothing to a clinician. Change it here when the chiropractor
 * navigation is designed.
 *
 * Deliberately free of `"use client"` and `server-only` so the server layouts
 * can import `PortalAudience` from the same module the shell reads.
 */

export interface PortalNavItem {
  label: string;
  icon: LucideIcon;
  /**
   * The route this item opens, or `undefined` while the destination does not
   * exist yet.
   *
   * The design links every item to `#`. Reproducing that literally would hand
   * patients eight links that silently do nothing, so an item with no route
   * renders inert (not focusable, no href) and says so in its tooltip instead.
   * Giving an item an `href` is all it takes to switch it on once the page
   * behind it lands.
   */
  href?: string;
  badge?: number;
}

export const PORTAL_NAV: Record<PortalAudience, PortalNavItem[]> = {
  patient: [
    { label: "Home", icon: House, href: "/dashboard" },
     { label: "Recipes", icon: Utensils, href: "/recipes" },
    { label: "Resources", icon: Wrench, href: "/dashboard/resources" },
    { label: "Training", icon: Dumbbell, href: "/dashboard/training" },
    { label: "Log My Weight", icon: Scale, href: "/log-progress" },
    // "My Progress" is what `/dashboard` renders, so this is the only
    // destination implemented so far.
    { label: "My Progress", icon: TrendingUp, href: "/dashboard" },
    { label: "Messages", icon: MessageSquare, href: "/messages" },
    { label: "Shop", icon: ShoppingCart },
    { label: "Extras", icon: MoreHorizontal },
  ],
  chiropractor: [
    { label: "Patient Summary", icon: Clipboard, href: "/chiropractor" },
    { label: "Patients", icon: Users, href: "/chiropractor/patients", badge: 6 },
    { label: "Messages", icon: MessageSquare, href: "/chiropractor/messages", badge: 1 },
    { label: "My Clinic Page", icon: House, href: "/chiropractor/clinic" },
     { label: "Recipes", icon: Utensils, href: "/recipes" },
    { label: "Resources", icon: FolderOpen, href: "/chiropractor/resources" },
    { label: "Training", icon: GraduationCap, href: "/chiropractor/training" },
    { label: "Subscription & Billing", icon: CreditCard, href: "/billing" },
    { label: "Support Center", icon: HelpCircle, href: "/chiropractor/support" },
    { label: "Cart", icon: ShoppingCart },
  ],
};

/** True when `pathname` is the item's own destination or something under it. */
export function isNavItemActive(item: PortalNavItem, pathname: string): boolean {
  if (!item.href) return false;
  // Exact match for portal root paths; startsWith for sub-routes.
  if (item.href === "/dashboard" || item.href === "/chiropractor") {
    return pathname === item.href;
  }
  return pathname === item.href || pathname.startsWith(`${item.href}/`);
}
