export interface ClinicLocation {
  id: number;
  title: string;
  clinic: string;
  authoredOn: string;
}
export interface Chiropractor {
  id: number;
  name: string;
  username: string;
  email: string;
  locationId: number | null;
  blocked: boolean;
  active: boolean;
  accessReason: string | null;
}
export interface ClinicSnapshot {
  clinic: { id: number; name: string };
  primary: { id: number; name: string };
  canManage: boolean;
  funding: "self" | "sponsored";
  chiropractors: Chiropractor[];
  locations: ClinicLocation[];
  createdId?: number;
  invitationSent?: boolean;
  notice?: string;
}
