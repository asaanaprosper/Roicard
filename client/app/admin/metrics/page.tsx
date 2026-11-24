import { AdminMetricsView } from "@/components/admin/AdminMetricsView";
import type { Metadata } from "next";

export const metadata: Metadata = {
  title: "Pilot metrics",
};

export default function AdminMetricsPage() {
  return <AdminMetricsView />;
}
