import { AdminOrganizationsView } from "@/components/admin/AdminOrganizationsView";
import type { Metadata } from "next";

export const metadata: Metadata = {
  title: "Organizations",
};

export default function AdminOrganizationsPage() {
  return <AdminOrganizationsView />;
}
