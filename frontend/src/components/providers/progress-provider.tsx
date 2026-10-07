"use client";

import { AppProgressBar as ProgressBar } from "next-nprogress-bar";
import { Suspense } from "react";

export function ProgressProvider({ children }: { children: React.ReactNode }) {
  return (
    <>
      {children}
      <Suspense fallback={null}>
        <ProgressBar
          // The bar markup is injected into document.body by NProgress.start(),
          // which AppProgressBar only calls on an <a> click or when startOnLoad is
          // set. Without this the bar never appears on a load or refresh.
          startOnLoad
          // The bar is mounted as <div class="nprogress">, but this library's
          // generated stylesheet still targets "#nprogress" (an id that
          // nprogress-v2 never sets), so it is dead CSS. Styling lives in
          // globals.css against the class instead.
          disableStyle
          options={{ showSpinner: false }}
          shallowRouting
          // AppProgressBar calls done() as soon as pathname changes, so a fast
          // transition holds the bar for a few ms and reads as "nothing happens".
          // Holding it briefly past completion makes it legible without lying
          // about progress being unfinished.
          stopDelay={200}
        />
      </Suspense>
    </>
  );
}