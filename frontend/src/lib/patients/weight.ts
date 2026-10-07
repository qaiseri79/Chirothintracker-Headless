/**
 * Weight bounds, shared by the forms that collect them and the route that guards them.
 *
 * Its own module rather than living in `patients-ui.tsx` because both sides need it and
 * they cannot import each other: that file is a client component, and a route handler
 * cannot pull one in. The alternative — a constant copied into each — is the kind of
 * duplication that survives exactly until the two disagree, and then the form accepts
 * what the endpoint rejects.
 */

/**
 * The heaviest weight, in pounds, that may be entered.
 *
 * Mirrors `PatientsService::MAX_WEIGHT_LBS` and must be changed alongside it. Set well
 * above the heaviest human ever documented, so it turns away keying errors — an extra
 * digit, a stray decimal point — and nothing else. The archive is what makes it
 * necessary: real accounts hold goal weights of 5155770000 and 3434340.
 */
export const MAX_WEIGHT_LBS = 1000;

/**
 * What is wrong with a weight, or NULL when it is fine.
 *
 * Three checks, in the order that gives the most useful message first, because "that
 * is not a number" is the only one that is worth saying about `abc` and the least that
 * is worth saying about `-250`.
 *
 * Zero is deliberately allowed. The archive stores 0 as a real reading — "measured, and
 * the reading was 0" — which is a different fact from "never recorded", and a validator
 * that called it meaningless would reject the confirmation of a record that has it.
 * Under 1lb is a slip of the keyboard rather than a body.
 *
 * @param weight A parsed number. Callers holding a string should parse it first.
 * @param label  The field's name, used only to phrase the message.
 */
export function weightProblem(weight: number, label: string): string | null {
  if (!Number.isFinite(weight)) {
    return `${label} must be a number.`;
  }
  if (weight < 0) {
    return `${label} cannot be negative.`;
  }
  if (weight > MAX_WEIGHT_LBS) {
    return `${label} looks too large. Enter a weight in pounds, up to ${MAX_WEIGHT_LBS}.`;
  }
  return null;
}

/**
 * A number input's contents as a number, or `undefined` when it holds nothing usable.
 *
 * `undefined` rather than `NaN`, and NULL rather than either, because both of those
 * would turn "the box is empty" into a weight of zero — which the archive cannot tell
 * apart from a real 0lb reading. An empty weight field has to stay a non-value all the
 * way to the endpoint so the stored one is left alone.
 */
export function parseWeightInput(raw: string): number | undefined {
  const trimmed = raw.trim();
  if (trimmed === "") return undefined;
  const parsed = Number(trimmed);
  return Number.isFinite(parsed) ? parsed : undefined;
}