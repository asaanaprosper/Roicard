import { PlanUsageView } from "@/components/billing/PlanUsageView";
import type { Metadata } from "next";

export const metadata: Metadata = {
  title: "Plan & usage",
};

export default function PlanPage() {
  return <PlanUsageView />;
}
