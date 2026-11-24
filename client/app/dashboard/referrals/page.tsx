import { ReferralView } from "@/components/referrals/ReferralView";
import type { Metadata } from "next";

export const metadata: Metadata = {
  title: "Referrals",
};

export default function ReferralsPage() {
  return <ReferralView />;
}
