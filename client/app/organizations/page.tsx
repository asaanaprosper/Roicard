import { OrganizationsBrowseView } from "@/components/organizations/OrganizationsBrowseView";
import type { Metadata } from "next";

export const metadata: Metadata = {
  title: "Organizations",
};

export default function OrganizationsPage() {
  return <OrganizationsBrowseView />;
}
