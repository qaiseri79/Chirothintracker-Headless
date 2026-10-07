"use client";

import { useEffect, useState } from "react";
import { createPortal } from "react-dom";

/**
 * The global toast, from the design's `#toast`.
 *
 * A single bottom-center pill that fades in on mount, holds for 2.2s — the
 * design's own timing — then fades out before unmounting. It is portaled to
 * `document.body` and `pointer-events-none`, so it never intercepts a click
 * and never scrolls with the page.
 *
 * The parent owns the message: it renders the toast while a message is set and
 * clears it on `onClose`, which is called only after the fade-out has had its
 * 300ms to run.
 */
export function Toast({ message, onClose }: { message: string; onClose: () => void }) {
  const [isVisible, setIsVisible] = useState(false);

  useEffect(() => {
    // Small delay to allow CSS transition to run after mount
    requestAnimationFrame(() => setIsVisible(true));

    const timer = setTimeout(() => {
      setIsVisible(false);
      setTimeout(onClose, 300); // Wait for fade out before unmounting
    }, 2200);

    return () => clearTimeout(timer);
  }, [onClose]);

  if (typeof document === "undefined") return null;

  return createPortal(
    <div
      className={`pointer-events-none fixed bottom-6 left-1/2 z-[60] -translate-x-1/2 rounded-full bg-[#101827] px-4 py-2 text-sm text-white shadow-xl transition-opacity duration-300 ${
        isVisible ? "opacity-100" : "opacity-0"
      }`}
    >
      {message}
    </div>,
    document.body,
  );
}
