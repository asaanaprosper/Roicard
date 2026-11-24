import { BillingHistoryView } from "@/components/billing/BillingHistoryView";
import type { Metadata } from "next";

export const metadata: Metadata = {
  title: "Billing history",
};

export default function BillingPage() {
  return <BillingHistoryView />;
}
