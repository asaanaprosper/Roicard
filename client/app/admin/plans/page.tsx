import { PlansManagementView } from "@/components/admin";
import type { Metadata } from "next";

export const metadata: Metadata = {
  title: "Plans — Admin",
};

export default function AdminPlansPage() {
  return <PlansManagementView />;
}
