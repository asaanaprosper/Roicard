import { AdminBillingView } from "@/components/admin/AdminBillingView";
import type { Metadata } from "next";

export const metadata: Metadata = {
  title: "Billing",
};

export default function AdminBillingPage() {
  return <AdminBillingView />;
}
