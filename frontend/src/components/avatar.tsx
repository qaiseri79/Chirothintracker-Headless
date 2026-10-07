/**
 * Initials in the design's accent-soft circle.
 *
 * Shared by the portal shell header and the messages thread header so the two
 * cannot disagree about what an avatar is.
 *
 * The design mock loads a placeholder photo from `i.pravatar.cc`. A remote
 * avatar service would leak every portal visit to a third party and needs
 * `remotePatterns` configured in `next.config.ts`, so this derives the initials
 * from the account name instead. Swapping in a real photo later means replacing
 * this one component — which is why nothing else computes initials.
 */
export function InitialsAvatar({
  name,
  className = "size-8 text-xs",
}: {
  name?: string;
  /** Circle size and type scale. Defaults to the shell header's 32px. */
  className?: string;
}) {
  const initials = (name ?? "")
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((part) => part[0]?.toUpperCase() ?? "")
    .join("");

  return (
    <span
      className={`flex shrink-0 items-center justify-center rounded-full border border-border bg-flame-soft font-semibold text-flame ${className}`}
      aria-hidden="true"
    >
      {initials || <span className="text-muted-foreground">–</span>}
    </span>
  );
}
