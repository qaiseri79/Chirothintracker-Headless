"use client";

import { useCallback, useEffect, useLayoutEffect, useRef, useState, useTransition } from "react";

/**
 * Progressive rendering of a long list, in fixed-size batches.
 *
 * Lifted out of `progress/log-history.tsx`, which had this logic inline, because
 * the message thread now needs it too and two copies of an `IntersectionObserver`
 * is two copies of a subtle re-entrancy bug. The recipe is unchanged from the
 * log history: a sentinel element the observer watches, a `rootMargin` so the
 * next batch is ready before it is needed, and a visible button bound to the
 * same handler so the list is still reachable by keyboard and screen reader.
 *
 * ## This bounds the DOM, not the request
 *
 * `items` is expected to be the complete array; batches are slices of it and no
 * network call is made. That is the right trade where one consumer needs the
 * whole set anyway — the weight chart scales its y-axis to the minimum and
 * maximum of every weigh-in, so handing it a truncated list would quietly
 * redraw the patient's history as the last N days. A list whose *payload* also
 * needs to shrink wants server-side paging, which is a different change.
 */
export function useProgressiveList<T>(
  items: T[],
  options: {
    /** How many items to render on first paint, and to add per batch. */
    pageSize: number;
    /**
     * `"append"` grows the list at the end; `"prepend"` grows it at the start and
     * is for newest-last lists like a message thread, where the reader wants the
     * most recent items first and reaches backwards through history by scrolling
     * up.
     */
    mode?: "append" | "prepend";
    /**
     * The scrolling ancestor to observe against. Omit for lists that scroll with
     * the page, which is what an `IntersectionObserver` defaults to.
     */
    rootRef?: React.RefObject<HTMLElement | null>;
    /** Pre-fetch distance. */
    rootMargin?: string;
    /**
     * Changing this resets to a single batch, so a list that changes wholesale —
     * a different conversation, a different account — does not inherit how far
     * the reader had scrolled through the previous one.
     */
    resetKey?: unknown;
  },
) {
  const { pageSize, mode = "append", rootRef, rootMargin = "200px", resetKey } = options;

  const [count, setCount] = useState(pageSize);
  const [isPending, startTransition] = useTransition();
  const sentinelRef = useRef<HTMLDivElement>(null);

  // Reset during render rather than in an effect. React re-renders immediately
  // and discards this pass, so the reader never sees a frame of the previous
  // conversation's batch depth — whereas resetting in an effect commits that
  // frame first and then cascades a second render.
  //
  // Nothing resets on `items.length`: a list that gains an item keeps how far it
  // had been read, which is what a conversation does when a message is sent.
  const [seenResetKey, setSeenResetKey] = useState(resetKey);
  if (seenResetKey !== resetKey) {
    setSeenResetKey(resetKey);
    setCount(pageSize);
  }

  const start = mode === "prepend" ? Math.max(0, items.length - count) : 0;
  const visible = mode === "prepend" ? items.slice(start) : items.slice(0, count);
  const hasMore = mode === "prepend" ? start > 0 : count < items.length;
  const remaining = mode === "prepend" ? start : items.length - count;

  const loadMore = useCallback(() => {
    if (!hasMore) return;
    startTransition(() => {
      // Clamped, so the final batch cannot overshoot the end of a list that grew
      // (or shrank) between renders.
      setCount((current) => Math.min(current + pageSize, items.length));
    });
  }, [hasMore, pageSize, items.length]);

  // Armed only once the sentinel has been seen leaving the viewport, which is
  // what stops the list running away to the end on its own. Without it a prepend
  // whose batch is shorter than the viewport leaves the sentinel still on screen,
  // the effect re-runs because `loadMore` changed, the observer fires again
  // immediately, and every remaining item is rendered before the reader scrolls.
  const armed = useRef(true);

  useEffect(() => {
    if (!hasMore) return;

    const sentinel = sentinelRef.current;
    if (!sentinel) return;

    const observer = new IntersectionObserver(
      (notifications) => {
        for (const notification of notifications) {
          if (!notification.isIntersecting) {
            // Out of view: re-arm, so the next arrival into view is honoured.
            armed.current = true;
            return;
          }
          if (armed.current) {
            armed.current = false;
            loadMore();
          }
        }
      },
      { root: rootRef?.current ?? null, rootMargin },
    );

    observer.observe(sentinel);
    return () => observer.disconnect();
  }, [hasMore, loadMore, rootRef, rootMargin]);

  // Keep the reader's place when older items are added above them.
  //
  // Prepending grows the content above the current scroll offset, so the naive
  // result is the view jumping by a screenful mid-read. The growth is measured
  // before and after the commit and folded into `scrollTop`; `useLayoutEffect` is
  // required rather than `useEffect` because the correction has to be applied in
  // the same frame the browser paints, or the jump is visible first.
  const scrollBefore = useRef<{ height: number; top: number } | null>(null);

  const loadMoreAndMeasure = useCallback(() => {
    if (mode === "prepend") {
      const el = rootRef?.current;
      if (el) {
        scrollBefore.current = { height: el.scrollHeight, top: el.scrollTop };
      }
    }
    loadMore();
  }, [loadMore, mode, rootRef]);

  useLayoutEffect(() => {
    const before = scrollBefore.current;
    if (!before) return;
    scrollBefore.current = null;
    restoreScrollAfterPrepend(rootRef?.current, before);
  }, [count, rootRef]);

  return {
    visible,
    hasMore,
    remaining,
    loadMore: loadMoreAndMeasure,
    sentinelRef,
    isPending,
  };
}

/**
 * Moves the scroll offset down by however much taller the content just got, so
 * the message the reader was looking at is still under their eyes.
 *
 * Module scope rather than inline, because the element is reached through a ref
 * the caller passed in and this is a write to the browser's own layout, not a
 * change to anything React owns.
 */
function restoreScrollAfterPrepend(
  el: HTMLElement | null | undefined,
  before: { height: number; top: number },
) {
  if (!el) return;
  el.scrollTop = before.top + (el.scrollHeight - before.height);
}
