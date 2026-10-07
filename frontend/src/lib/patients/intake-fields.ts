import { allFields, staticFields, steps } from "@/lib/intake/blueprint";
import type { BlueprintField } from "@/lib/intake/types";

/**
 * Reads one intake submission's stored fields as text the view can print.
 *
 * ## Why this exists
 *
 * `PatientIntakeService::get()` returns `fields` as Drupal stores it, keyed by
 * field name: `[['value' => 'F']]`, an address as
 * `[['country_code' => 'US', 'address_line1' => '…']]`, and a checkbox grid as one
 * entry per ticked option. That is the right shape for a service and the wrong
 * shape for a chiropractor reading a patient's screening answers — "1" is not an
 * answer to "Have you been diagnosed with diabetes?".
 *
 * So the labels come from the blueprint, which is the same generated contract the
 * public form is built from (`tools/export_intake_blueprint.php`). That is the
 * reason for reading it rather than hard-coding a list here: an option added to
 * the intake form is labelled correctly in this view with no change to this file,
 * and there is only one copy of both.
 *
 * ## `drupalField` and `part`, not the input name
 *
 * The blueprint's `name` is the form input (`field_mailing_address[address_line1]`)
 * and carries no meaning in the database. The pair that does is `drupalField` plus
 * `part`, and several inputs may share one field — the address's five inputs are
 * one stored field — so reading by `name` would find nothing at all.
 */

/** One field, already rendered as the strings to print. */
export interface IntakeAnswer {
  /** The blueprint's input name, kept so a component can key on it. */
  name: string;
  label: string;
  /** Human-readable values, in stored order. Empty fields are not returned. */
  values: string[];
  /**
   * True when the patient answered a Yes/No or a multi-tick grid.
   *
   * This is what {@link flaggedAnswers} keys off, and it is deliberately narrower
   * than "has a value": a long motivation paragraph is not a health flag.
   */
  affirmative: boolean;
  /** The blueprint step this field was shown in. */
  step: string;
}

/** The step each field belongs to, by name. Built once; the blueprint is static. */
const STEP_OF = new Map<string, string>(
  steps.flatMap((step) => step.fields.map((field) => [field.name, step.title] as const)),
);

/**
 * Pulls the first array item, which is where a single-valued Drupal field keeps
 * its properties.
 */
function firstItem(raw: unknown): Record<string, unknown> | null {
  if (!Array.isArray(raw) || raw.length === 0) return null;
  const item = raw[0];
  if (item === null || typeof item !== "object" || Array.isArray(item)) return null;
  return item as Record<string, unknown>;
}

/**
 * One stored value as a string, or `null` to drop it.
 *
 * `0` and `"0"` survive because they are answers — a `No` to every radio on the
 * screening step is stored as `0`, and that row is the most reassuring one on the
 * form.
 */
function scalar(value: unknown): string | null {
  if (value === null || value === undefined) return null;
  if (typeof value === "boolean") return value ? "Yes" : "No";
  if (typeof value === "number") return Number.isFinite(value) ? String(value) : null;
  if (typeof value === "string") {
    const trimmed = value.trim();
    return trimmed === "" ? null : trimmed;
  }
  return null;
}

/**
 * Unwraps one stored entry into the value it holds.
 *
 * Drupal wraps a scalar in `['value' => …]`, so an entry that is an object with a
 * `value` key carries the value one level down. An entry that is already a scalar —
 * which is what a long list field can hand back — is itself.
 *
 * The check is on `Array.isArray`, not on `firstItem()`: an entry here is a single
 * item, never a list, so the list-peeking helper is the wrong tool and returns
 * nothing for every ordinary field.
 */
function unwrap(entry: unknown): unknown {
  if (entry !== null && typeof entry === "object" && !Array.isArray(entry)) {
    const record = entry as Record<string, unknown>;
    if ("value" in record) return record.value;
  }
  return entry;
}

/** The raw stored entries for a blueprint field, whatever shape they arrived in. */
function storedEntries(fields: Record<string, unknown>, field: BlueprintField): unknown[] {
  const raw = fields[field.drupalField];
  if (raw === null || raw === undefined) return [];
  const items = Array.isArray(raw) ? raw : [raw];

  if (field.part === null) {
    return items.map(unwrap);
  }

  // A compound field keeps its parts on the first item, and a part can be a list
  // of its own: `field_stressors` holds both `first` and `second`, and the
  // repeatable widgets store one entry per line the patient typed.
  const head = firstItem(items);
  if (!head || !(field.part in head)) return [];
  const part = unwrap(head[field.part]);
  return Array.isArray(part) ? part : [part];
}

/**
 * Turns a stored value into the text to print, using the blueprint's options.
 *
 * A stored value with no matching option is printed verbatim rather than dropped:
 * an unrecognised code is information about the data, and dropping it here would
 * leave a chiropractor reading a health screening with a question missing and no
 * way to know it had been answered.
 */
function labelValue(value: unknown, field: BlueprintField): string | null {
  const text = scalar(value);
  if (text === null) return null;

  if (field.options.length > 0) {
    const match = field.options.find((option) => option.value === text);
    if (match) return match.label;
  }

  // The consent widget's stored `1` records agreement to the liability release,
  // so "Agreed" is the honest reading of it.
  if (field.widget === "consent") {
    return text === "1" || text === "true" ? "Agreed" : null;
  }

  // A checkbox widget only records that a box was ticked. It does not record what
  // the patient agreed to, and here that matters: `field_media_release_disagree`
  // is labelled "I do not consent to the media release", so a ticked box means
  // they *declined*. Rendering that as "Agreed" would assert the opposite of what
  // the patient said, which is the one thing a consent record must never do.
  // "Yes" beside the label says only what the storage actually holds.
  if (field.widget === "checkbox") return "Yes";

  return text;
}

/**
 * Every answered field in the blueprint, in the order the patient saw them.
 *
 * Unanswered fields are omitted rather than rendered blank: a submission where
 * somebody skipped the optional history questions should not show a column of
 * empty rows, and an empty row is indistinguishable from a value that failed to
 * load.
 */
export function intakeAnswers(fields: Record<string, unknown>): IntakeAnswer[] {
  const answers: IntakeAnswer[] = [];

  for (const field of allFields) {
    if (field.widget === "static") continue;

    const values: string[] = [];
    for (const stored of storedEntries(fields, field)) {
      const label = labelValue(stored, field);
      if (label !== null && !values.includes(label)) values.push(label);
    }

    if (values.length === 0) continue;

    answers.push({
      name: field.name,
      label: field.label,
      values,
      affirmative: field.widget === "radio" || field.widget === "checkbox-grid",
      step: STEP_OF.get(field.name) ?? "",
    });
  }

  return answers;
}

/** Groups answers by step title, keeping the blueprint's step order. */
export function answersByStep(answers: IntakeAnswer[]): { title: string; answers: IntakeAnswer[] }[] {
  const grouped: { title: string; answers: IntakeAnswer[] }[] = [];

  for (const step of steps) {
    const inStep = answers.filter((answer) => answer.step === step.title);
    if (inStep.length > 0) grouped.push({ title: step.title, answers: inStep });
  }

  // Anything whose step title is not in the blueprint goes last rather than
  // vanishing: an unrecognised group is still a patient's answer.
  const known = new Set(grouped.map((group) => group.title));
  const strays = answers.filter((answer) => !known.has(answer.step));
  if (strays.length > 0) grouped.push({ title: "Other", answers: strays });

  return grouped;
}

/**
 * The answers a chiropractor needs to look at.
 *
 * ## What counts as needing attention
 *
 * Only an affirmative answer on a Yes/No or checkbox widget, which is the rule
 * the design's intake tab uses: an amber row means "the patient said yes to a
 * screening question", not a long answer or a low confidence. Painting a
 * motivation paragraph amber would train the eye to ignore the colour, which is
 * the whole value of it.
 *
 * A `No` is never flagged even though it is stored as `0`, which is why this
 * matches the option's label rather than testing truthiness. The one exception is
 * a grid whose ticked option is itself a condition — "Pregnant" on the medical
 * eligibility grid is a yes to a screening question, so it is flagged, while
 * "Biliopancratic Diversion" is a history item and equally a yes. Both are flagged;
 * that is correct, and a chiropractor triaging the amber rows is the one who knows
 * which matter.
 */
export function flaggedAnswers(answers: IntakeAnswer[]): IntakeAnswer[] {
  return answers.filter(
    (answer) => answer.affirmative && answer.values.some((value) => !/^no\b/i.test(value)),
  );
}

/**
 * The stored legal text, in blueprint order.
 *
 * `static` fields hold the server-resolved agreement bodies rather than patient
 * answers, and they are what makes the signature line below them meaningful —
 * "signed by typing their name" against text the patient never saw would be a
 * claim the record cannot support.
 */
export function agreementText(fields: Record<string, unknown>): { name: string; label: string; text: string }[] {
  const out: { name: string; label: string; text: string }[] = [];

  for (const field of staticFields) {
    const text = storedEntries(fields, field)
      .map(scalar)
      .filter((value): value is string => value !== null)
      .join("\n\n")
      .trim();

    if (text === "") continue;
    out.push({ name: field.name, label: field.label, text });
  }

  return out;
}

/**
 * The single stored value for a field, or `""`.
 *
 * For the handful of fields the view names directly rather than iterating — the
 * typed legal name that is the signature.
 */
export function storedField(fields: Record<string, unknown>, name: string): string {
  const field = allFields.find((candidate) => candidate.name === name);
  if (!field) return "";
  return storedEntries(fields, field)
    .map(scalar)
    .filter((value): value is string => value !== null)
    .join(" ");
}

/**
 * Whether the patient actually consented.
 *
 * Not the same question as "is the field non-empty". `field_consent_consumption`
 * stores `1` for agreed and `0` for declined, so a plain truthiness check on the
 * stored string reports that a submission which was explicitly *not* consented to
 * carries a signature — and this view is the screen a chiropractor would read that
 * claim off. Only a literal `1` counts.
 */
export function consentGiven(fields: Record<string, unknown>): boolean {
  return storedField(fields, "field_consent_consumption") === "1";
}