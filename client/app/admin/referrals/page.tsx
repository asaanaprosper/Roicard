import { AdminReferralsView } from "@/components/admin/AdminReferralsView";
import type { Metadata } from "next";

export const metadata: Metadata = {
  title: "Referrals",
};

export default function AdminReferralsPage() {
  return <AdminReferralsView />;
}
