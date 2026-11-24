import { SignalsView } from "@/components/signals/SignalsView";
import type { Metadata } from "next";

export const metadata: Metadata = {
  title: "Seeking & Offering",
};

export default function SignalsPage() {
  return <SignalsView />;
}
