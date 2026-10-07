import { PortalShell } from "@/components/portal/portal-shell-server";

export default function StandardChiropractorLayout({
  children,
}: {
  children: React.ReactNode;
}) {
  return <PortalShell audience="chiropractor" maxWidth="wide">{children}</PortalShell>;
}