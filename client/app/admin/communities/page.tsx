import { CommunitiesManagementView } from "@/components/admin";
import type { Metadata } from "next";

export const metadata: Metadata = {
  title: "Communities — Admin",
};

export default function AdminCommunitiesPage() {
  return <CommunitiesManagementView />;
}
