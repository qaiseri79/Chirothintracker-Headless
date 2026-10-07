/**
 * Types and placeholder records for the clinic page.
 *
 * ## Placeholder data, not a data layer
 *
 * There is no clinic API yet: no endpoint lists a clinic's locations and no endpoint
 * lists the chiropractors attached to it. Rather than have the page read an empty
 * list and render an empty state that looks like a real clinic with no team, the
 * records below stand in so the page can be built and reviewed against the design
 * in `New Design/ChiroThin — My Clinic Page.html`.
 *
 * Everything the page does that is *not* a fetch — tabbing, searching, filtering,
 * adding, editing, blocking — already runs against real state and real handlers here,
 * so wiring an API later is a matter of swapping this module for a loader that
 * returns the same shapes. Nothing in `clinic-page.tsx` should need to change to do
 * it.
 *
 * The seed mirrors the design's own demo state, including its three unassigned
 * chiropractors, because "Unassigned" is otherwise a state a reviewer never sees.
 */

export interface ClinicLocation {
  id: number;
  /** The location's own name, e.g. "Downtown Clinic". Not the clinic it belongs to. */
  title: string;
  /** The clinic this location sits under. */
  clinic: string;
  /** `MM/DD/YYYY`, matching the backend's authored-on value. */
  authoredOn: string;
}

export interface Chiropractor {
  id: number;
  /** The account's display name. */
  name: string;
  /** The Drupal username, which the design's "User name" column shows. */
  username: string;
  email: string;
  /**
   * The assigned location's id, or `null` when the chiropractor is not assigned to
   * one.
   *
   * The design filters and matches locations by *title*. Ids are used here instead so
   * that renaming a location carries its chiropractors with it — a title-keyed model
   * would silently orphan anyone assigned to the old name.
   */
  locationId: number | null;
  /** Blocked chiropractors lose portal access immediately. */
  blocked: boolean;
}

/** The clinics a location can belong to. */
export const CLINICS: string[] = ["ChiroThinTracker.com Training Site"];

export const SEED_LOCATIONS: ClinicLocation[] = [
  { id: 1, title: "Satellite Clinic", clinic: CLINICS[0], authoredOn: "03/18/2025" },
  { id: 2, title: "Site 2", clinic: CLINICS[0], authoredOn: "03/18/2025" },
  { id: 3, title: "Nutrition Tracking", clinic: CLINICS[0], authoredOn: "07/08/2026" },
];

export const SEED_CHIROPRACTORS: Chiropractor[] = [
  {
    id: 1,
    name: "Amber Von Haden",
    username: "amberyaw",
    email: "amberyaw@yahoo.com",
    locationId: 1,
    blocked: false,
  },
  {
    id: 2,
    name: "Annie Oakley",
    username: "annie.oakley",
    email: "annie.oakley@fakeemail.com",
    locationId: null,
    blocked: false,
  },
  {
    id: 3,
    name: "Annie Are You Okay?",
    username: "annie",
    email: "annie@yopmail.com",
    locationId: null,
    blocked: false,
  },
  {
    id: 4,
    name: "Annie Smith",
    username: "annie",
    email: "annie@fakeemail.com",
    locationId: null,
    blocked: true,
  },
];

/**
 * The next id to hand out when a record is added.
 *
 * Seeded past every id above so a locally added record cannot collide with a seeded
 * one. Not a real sequence — see the module note about placeholder data.
 */
export const FIRST_FREE_ID = 10;