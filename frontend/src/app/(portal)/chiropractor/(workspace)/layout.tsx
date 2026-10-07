import { PortalShell } from "@/components/portal/portal-shell-server";

export default function WorkspaceChiropractorLayout({
  children,
}: {
  children: React.ReactNode;
}) {
  return <PortalShell audience="chiropractor" title="Messages" bleed>{children}</PortalShell>;
}