"use client";

import type { ReactNode } from "react";
import { X } from "lucide-react";
import { Button } from "@/components/ui/button";
import { DialogClose, DialogContent, DialogDescription, DialogTitle } from "@/components/ui/dialog";

/** Shared popup frame: the form scrolls while Close stays above its sticky header. */
export function TrackingDialogContent({ title, description, children }: {
  title: string;
  description: string;
  children: ReactNode;
}) {
  return (
    <DialogContent
      showCloseButton={false}
      className="flex max-h-[calc(100dvh-2rem)] w-[calc(100%-2rem)] max-w-3xl flex-col gap-0 overflow-hidden rounded-xl p-0"
      style={{ fontFamily: "var(--font-inter), system-ui, sans-serif" }}
    >
      <DialogTitle className="sr-only">{title}</DialogTitle>
      <DialogDescription className="sr-only">{description}</DialogDescription>
      <DialogClose asChild>
        <Button type="button" variant="ghost" size="icon" aria-label="Close"
          className="absolute right-3 top-3 z-30 bg-surface text-muted-foreground hover:bg-canvas">
          <X className="size-4" aria-hidden="true" />
        </Button>
      </DialogClose>
      <div data-tracking-scroll className="min-h-0 overflow-y-auto overscroll-contain">
        {children}
      </div>
    </DialogContent>
  );
}
