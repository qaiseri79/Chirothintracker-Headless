"use client";

import * as React from "react";
import * as DialogPrimitive from "@radix-ui/react-dialog";
import { useAuth } from "@/lib/auth";
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogDescription,
  DialogFooter,
} from "@/components/ui/dialog";
import { Button } from "@/components/ui/button";
import { LogOut } from "lucide-react";

interface LogoutConfirmDialogProps {
  /** If true, renders an icon-only button suitable for tight spaces */
  iconOnly?: boolean;
}

export function LogoutConfirmDialog({ iconOnly = false }: LogoutConfirmDialogProps) {
  const { logout } = useAuth();
  const [open, setOpen] = React.useState(false);

  const handleConfirm = async () => {
    await logout();
    setOpen(false);
  };

  const triggerContent = iconOnly ? (
    <LogOut className="h-4 w-4" />
  ) : (
    <>
      <LogOut className="mr-2 h-4 w-4" />
      Log out
    </>
  );

  const DialogTrigger = DialogPrimitive.Trigger;

  return (
    <Dialog open={open} onOpenChange={setOpen}>
      <DialogTrigger asChild>
        <Button
          variant="ghost"
          size={iconOnly ? "icon-sm" : "sm"}
          onClick={() => setOpen(true)}
          title="Log out"
        >
          {triggerContent}
        </Button>
      </DialogTrigger>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Log out</DialogTitle>
          <DialogDescription>
            Are you sure you want to log out of your account?
          </DialogDescription>
        </DialogHeader>
        <DialogFooter>
          <Button variant="outline" onClick={() => setOpen(false)}>
            Cancel
          </Button>
          <Button variant="destructive" onClick={handleConfirm}>
            Log out
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}