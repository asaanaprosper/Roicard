import { OrganizationWorkspaceView } from "@/components/organizations/OrganizationWorkspaceView";
import type { Metadata } from "next";

export const metadata: Metadata = {
  title: "Organization workspace",
};

export default function OrganizationWorkspacePage() {
  return <OrganizationWorkspaceView />;
}
