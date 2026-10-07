import { summaryRequest } from "./summary-api";

/** One image in the patient's photo gallery or the profile photo. */
export interface PatientPhoto {
  id: number;
  url: string;
}

/** What the "Update patient photos" dialog loads before editing. */
export interface PatientPhotos {
  profilePhoto: PatientPhoto | null;
  photos: PatientPhoto[];
}

export function getPatientPhotos(patientId: number): Promise<PatientPhotos> {
  return summaryRequest<PatientPhotos>(patientId, "/photos");
}